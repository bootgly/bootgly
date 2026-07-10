<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use function dechex;
use function explode;
use function feof;
use function is_string;
use function json_encode;
use function pack;
use function strcasecmp;
use function strlen;
use function time;
use function trim;
use Closure;
use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Events\Timer;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP\Server\SSE as Encoder;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Stream;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Streaming;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;


/**
 * Server-Sent Events stream (`$Response->SSE`).
 *
 * `open()` writes the `text/event-stream` head out-of-band, marks the
 * Response deferred (releasing it from the normal encode pipeline) and
 * keeps the transport open for push: events go out via `send()`/`ping()`,
 * a Timer supervisor drives the heartbeat/tick cadence, and the stream
 * ends with `close()` — or `disconnect()` teardown on any transport loss.
 *
 * HTTP/1.1 hijacks the dedicated connection (chunked framing, guarded by
 * `Decoder_Streaming`); HTTP/2 sustains its stream (DATA frames bounded by
 * the RFC 9113 send windows) while the connection keeps serving others.
 */
class SSE extends Resource implements Disconnecting
{
   // * Config
   /**
    * Seconds of write silence before a keep-alive comment; `0` disables.
    */
   public int $heartbeat = 15;
   /**
    * Client reconnection delay in milliseconds, sent once right after
    * `open()` as a `retry:` field; `0` omits it.
    */
   public int $retry = 0;

   // * Data
   /**
    * `Last-Event-ID` request header — the client's resume point.
    */
   public private(set) string $last = '';

   // * Metadata
   public private(set) bool $opened = false;
   public private(set) bool $closed = false;
   // @ Producer detached by close() while HTTP/2 backlog bytes are still
   //   parked — the supervisor survives as a drain watchdog until pump()
   //   emits the END_STREAM tail or the stall deadline resets the stream
   private bool $draining = false;
   private null|Response $Response = null;
   private null|Connection $Connection = null;
   private string $protocol = '';
   private string $method = '';
   // # HTTP/2
   private null|Decoder_HTTP2 $H2 = null;
   private null|Stream $Stream = null;
   private int $stream = 0;
   // # Supervisor
   private int $timer = 0;
   private int $interval = 0;
   private int $ticked = 0;
   private int $wrote = 0;
   private null|Closure $Tick = null;
   private null|Closure $Close = null;


   public function __construct (Response $Response)
   {
      parent::__construct();

      // * Metadata
      $this->Response = $Response;
   }

   /**
    * Bind the per-request transport context (called by the Response when
    * the resource mounts).
    */
   public function bind (null|Packages $Package, null|Request $Request): static
   {
      // ! Transport
      $this->Connection = $Package?->Connection;

      // ! Request context
      if ($Request !== null) {
         $this->protocol = $Request->protocol;
         $this->method = $Request->method;
         $this->stream = $Request->stream;

         $last = $Request->headers['last-event-id'] ?? '';
         $this->last = is_string($last) ? $last : '';
      }

      // # HTTP/2 stream
      if ($this->stream !== 0 && $Package?->decoded instanceof Decoder_HTTP2) {
         $this->H2 = $Package->decoded;
         $this->Stream = $this->H2->Streams[$this->stream] ?? null;
      }

      // :
      return $this;
   }

   /**
    * Open the event stream: write the head, defer the Response and install
    * the supervisor. `$Tick` runs every `$interval` seconds until the
    * stream ends; `$Close` runs exactly once on teardown.
    *
    * @param null|Closure(self):void $Tick
    * @param null|Closure(self):void $Close
    */
   public function open (null|Closure $Tick = null, int $interval = 1, null|Closure $Close = null): self
   {
      // ? Already streaming, torn down or detached from a live request
      if ($this->opened || $this->closed) {
         return $this;
      }
      $Response = $this->Response;
      $Connection = $this->Connection;
      if ($Response === null || $Connection === null) {
         return $this;
      }
      // ? Interim/unbounded streams are HTTP/1.1+
      if ($this->stream === 0 && $this->protocol === 'HTTP/1.0') {
         $Response->code(505);
         return $this;
      }
      // ? HTTP/2 stream already reset by the peer — no destination
      if ($this->stream !== 0) {
         $Stream = $this->Stream;
         if (
            $this->H2 === null || $Stream === null
            || ($this->H2->Streams[$this->stream] ?? null) !== $Stream
         ) {
            $this->closed = true;
            return $this;
         }
      }

      // ! Head fields (user-set fields — e.g. CORS — are preserved).
      //   remove() is case-insensitive across every serialization source
      //   (set/prepare/queue/preset): clearing each mandatory field before
      //   set() leaves exactly one canonical instance on the wire — a stale
      //   user-set Content-Length next to chunked framing is a request-
      //   smuggling class of bug, and duplicate Content-Type/Transfer-
      //   Encoding variants are ambiguous framing.
      $Header = $Response->Header;

      // ! Cache policy MERGES with the mandatory `no-cache` instead of
      //   replacing it — an application `no-store`/`private` is STRONGER
      //   and must survive the canonicalization. Every structured policy
      //   source counts: set() (any case), prepare() and preset().
      //   queue()d lines are a raw-line escape hatch, NOT a policy source —
      //   they are removed, never merged (documented API contract).
      $cache = '';
      /** @var array<string,string|true> $sources */
      foreach ([$Header->fields, $Header->prepared, $Header->preset] as $sources) {
         foreach ($sources as $name => $value) {
            if (
               is_string($value) && $value !== ''
               && strcasecmp($name, 'Cache-Control') === 0
            ) {
               $cache = $cache === '' ? $value : "{$cache}, {$value}";
            }
         }
      }

      $Header->remove('Content-Length');
      $Header->remove('Content-Type');
      $Header->remove('Cache-Control');
      $Header->remove('Transfer-Encoding');
      $Header->remove('X-Accel-Buffering');
      $Header->set('Content-Type', Encoder::TYPE);
      $Header->set('Cache-Control', $this->merge($cache));
      $Header->set('X-Accel-Buffering', 'no');

      // ? HEAD responses never include content (RFC 9110 §9.3.2) — and a
      //   Content-Length here would have to equal the GET representation
      //   (§8.6), which is an unsized live stream: serialize the metadata
      //   head out-of-band with NO Content-Length and NO Transfer-Encoding
      //   (the response ends at the header block). Never hijack or sustain
      //   the transport.
      if ($this->method === 'HEAD') {
         $this->closed = true;
         $Header->build();

         if ($this->stream !== 0) {
            /**
             * @var Decoder_HTTP2 $H2
             * @var Stream $Stream
             */
            $H2 = $this->H2;
            $Stream = $this->Stream;

            // # HEADERS with END_STREAM, no content-length (`null` size)
            $Stream->responded = true;
            $block = Encoder_HTTP2::compress(200, $Header->raw, null);
            $head = $H2->outbox
               . Encoder_HTTP2::pack($this->stream, $block, $H2->Remote->frame, HTTP2::FLAG_END_STREAM);
            $H2->outbox = '';

            // @ Release BEFORE the write (re-entrant close safety — see the
            //   graceful-close path)
            $Stream->close();
            unset($H2->Streams[$this->stream]);
            $H2->opened--;
         }
         else {
            $head = "HTTP/1.1 200 OK\r\n{$Header->raw}\r\n\r\n";
         }
         $Connection->writing($Connection->Socket, strlen($head), $head);

         // @ Release the Response from the encode pipeline — the head is
         //   already on the wire
         $Response->deferred = true;

         // :
         return $this;
      }

      if ($this->stream === 0) {
         $Header->set('Transfer-Encoding', 'chunked');
      }
      $Header->build();

      // * Metadata — lifecycle state is stored BEFORE the head write: a
      //   synchronous write failure closes the connection and tears this
      //   unit down re-entrantly (Connection::close()/Stream::close() →
      //   disconnect()), so the Close hook and supervisor state must
      //   already be in place for that teardown.
      $this->opened = true;
      $this->interval = $interval > 0 ? $interval : 1;
      $this->ticked = time();
      $this->wrote = time();
      $this->Tick = $Tick;
      $this->Close = $Close;

      // @ Release the Response from the encode pipeline and drop the
      //   worker's singleton Response reference — it is reset and reused by
      //   the next request; this unit lives on via the connection
      //   (`decoded`) and the supervisor Timer.
      $Response->deferred = true;
      $this->Response = null;

      // @ Write the head out-of-band
      if ($this->stream !== 0) {
         /**
          * @var Decoder_HTTP2 $H2
          * @var Stream $Stream
          */
         $H2 = $this->H2;
         $Stream = $this->Stream;

         // # Sustain the stream: no END_STREAM, no release, until close();
         //   register this unit as the stream owner — Stream::close() then
         //   tears it down deterministically on ANY release path
         //   (RST_STREAM, GOAWAY, connection teardown)
         $Stream->sustained = true;
         $Stream->responded = true;
         $Stream->Owner = $this;

         // # HEADERS without END_STREAM — pending control frames ride along
         $block = Encoder_HTTP2::compress(200, $Header->raw, null);
         $head = $H2->outbox . Encoder_HTTP2::pack($this->stream, $block, $H2->Remote->frame);
         $H2->outbox = '';
      }
      else {
         $head = "HTTP/1.1 200 OK\r\n{$Header->raw}\r\n\r\n";
      }
      $written = $Connection->writing($Connection->Socket, strlen($head), $head);

      // ? A failed head write closes the connection synchronously — the
      //   teardown (Stream::close()/Connection::close() → disconnect())
      //   may already have run re-entrantly. Never hijack the decoder,
      //   install the supervisor or send `retry:` after teardown: a timer
      //   whose every tick returns at the `closed` guard would otherwise
      //   survive (and retain this unit) for the worker lifetime.
      //   `writing()` can also close the connection and still return true
      //   (a pending `closeAfterDrain` applies on the drained write) — on
      //   HTTP/1.1 the decoder is not hijacked yet at this point, so that
      //   close does not notify this unit: check the connection status too.
      //   (PHPStan cannot see the re-entrant `$this->closed` mutation
      //   through the transport call chain.)
      if (
         $written === false || $this->closed // @phpstan-ignore booleanOr.rightAlwaysFalse
         || $Connection->status > Connections::STATUS_ESTABLISHED
      ) {
         $this->disconnect();
         return $this;
      }

      // @ HTTP/1.1 — hijack the dedicated connection: inbound bytes are
      //   discarded and Connection::close() tears this unit down
      //   deterministically (Disconnecting). HTTP/2 keeps its connection
      //   decoder — other streams must keep working.
      if ($this->stream === 0) {
         $Connection->Decoder = new Decoder_Streaming;
         $Connection->decoded = $this;
      }

      // @ Install the supervisor at the tightest due cadence
      $cadence = 10;
      if ($Tick !== null && $this->interval < $cadence) {
         $cadence = $this->interval;
      }
      if ($this->heartbeat > 0 && $this->heartbeat < $cadence) {
         $cadence = $this->heartbeat;
      }
      $timer = Timer::add(interval: $cadence, handler: [$this, 'supervise']);
      if ($timer !== false) {
         $this->timer = $timer;
         $Connection->timers[] = $timer;
      }

      // @ Reconnection delay field (field-only frame — never dispatched)
      if ($this->retry > 0) {
         $this->write(Encoder::encode(null, retry: $this->retry));
      }

      // :
      return $this;
   }

   /**
    * Send one event. Non-string `$data` is JSON-encoded.
    *
    * `true` means the payload was ACCEPTED into the bounded transport
    * buffers (kernel socket, pendingBuffer or the HTTP/2 send-window
    * backlog) — not necessarily flushed to the peer yet. `false` means it
    * was rejected: the stream/connection is gone or a resource budget was
    * breached (the stream is then reset and this unit torn down).
    */
   public function send (mixed $data, null|string $event = null, null|string $id = null): bool
   {
      // ?
      if ($this->opened === false || $this->closed || $this->draining) {
         return false;
      }

      // ! Payload
      $raw = is_string($data) ? $data : json_encode($data);
      if ($raw === false) {
         return false;
      }

      // :
      return $this->write(Encoder::encode($raw, $event, $id));
   }

   /**
    * Send one comment line — the keep-alive heartbeat frame.
    */
   public function ping (string $comment = ''): bool
   {
      // ?
      if ($this->opened === false || $this->closed || $this->draining) {
         return false;
      }

      // :
      return $this->write(Encoder::comment($comment));
   }

   /**
    * Heartbeat / tick supervisor (Timer callback). Tears the stream down on
    * transport loss, exempts the connection from idle reaping, runs the
    * user tick when due and pings on write silence.
    */
   public function supervise (): void
   {
      // ? Torn down
      if ($this->closed) {
         return;
      }
      $Connection = $this->Connection;
      if ($Connection === null || $Connection->status > Connections::STATUS_ESTABLISHED) {
         $this->disconnect();
         return;
      }
      // ? Peer closed the socket
      if (@feof($Connection->Socket)) {
         $this->disconnect();
         $Connection->close();
         return;
      }
      // ? HTTP/2 stream reset by the peer
      if (
         $this->stream !== 0
         && ($this->H2 === null || ($this->H2->Streams[$this->stream] ?? null) !== $this->Stream)
      ) {
         $this->disconnect();
         return;
      }
      // ? HTTP/2 flow-control stall deadline — parked bytes the peer never
      //   drains (zero window, no WINDOW_UPDATE) hold worker memory: past
      //   the transport write deadline without progress, reset the stream
      //   and tear down. The clock lives on the Stream and is advanced by
      //   every REAL consumption inside the decoder's drain() (and by the
      //   write that first parks bytes) — so partial WINDOW_UPDATE progress
      //   is never a stall, and a fully drained backlog can never poison a
      //   later one with a stale timestamp.
      if (
         $this->stream !== 0 && $this->Stream !== null
         && $this->Stream->backlog !== ''
         && (time() - $this->Stream->drained) > TCP_Server_CLI::$maxWriteWallTime
      ) {
         $this->abort();
         return;
      }

      // ! The stream is alive by design — exempt from the transport idle reaper
      $Connection->used = time();

      // ? Draining — the producer already detached (close()); this timer
      //   only watches the parked tail: no tick, no heartbeat
      if ($this->draining) {
         return;
      }

      // @ User tick — contained: the Timer loop swallows Throwables
      //   silently, which would leave a broken producer as a zombie stream
      //   (no heartbeat, reaper-exempt, rescheduled forever)
      if ($this->Tick !== null && (time() - $this->ticked) >= $this->interval) {
         $this->ticked = time();
         try {
            ($this->Tick)($this);
         }
         catch (Throwable $Throwable) {
            Throwables::notify($Throwable, ['origin' => 'sse.tick']);
            $this->close();
            return;
         }

         // ?! The tick closure may have closed the stream — PHPStan cannot
         //   see the `$this->closed` mutation through the closure call.
         if ($this->closed) { // @phpstan-ignore if.alwaysFalse
            return;
         }
      }

      // @ Heartbeat — the tick may have sent events meanwhile
      if ($this->heartbeat > 0 && (time() - $this->wrote) >= $this->heartbeat) {
         $this->ping();
      }
   }

   /**
    * End the stream gracefully: terminal wire bytes, then teardown. The
    * HTTP/1.1 connection closes (it was dedicated to the stream); the
    * HTTP/2 connection stays open for other streams.
    */
   public function close (): void
   {
      // ? Already draining — the supervisor owns the rest of the teardown
      if ($this->draining) {
         return;
      }
      if ($this->opened === false || $this->closed) {
         $this->disconnect();
         return;
      }

      $Connection = $this->Connection;

      // # HTTP/2 — end the stream, keep the connection
      if ($this->stream !== 0) {
         $H2 = $this->H2;
         $Stream = $this->Stream;

         if (
            $H2 !== null && $Stream !== null && $Connection !== null
            && ($H2->Streams[$this->stream] ?? null) === $Stream
         ) {
            $Stream->sustained = false;

            // ?: Window-starved backlog — pump() emits the END_STREAM tail
            //    and releases the stream (notifying this unit) when credit
            //    arrives. The producer detaches NOW (Close hook), but the
            //    supervisor SURVIVES as a drain watchdog: without it, a
            //    peer that never restores credit would keep the parked
            //    bytes, the stream and this unit mapped forever — the
            //    stall deadline in supervise() bounds that in time.
            if ($Stream->backlog !== '') {
               $this->draining = true;
               $this->notify();
               return;
            }

            $frames = $H2->outbox
               . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, $this->stream, '');
            $H2->outbox = '';

            // @ Release BEFORE the write — writing() can close the
            //   connection synchronously (decoder teardown clears the
            //   whole stream map and zeroes `opened`); releasing after
            //   would then double-decrement the count
            $Stream->close();
            unset($H2->Streams[$this->stream]);
            $H2->opened--;

            $Connection->writing($Connection->Socket, strlen($frames), $frames);
         }

         $this->disconnect();
         return;
      }

      // # HTTP/1.1 — terminal chunk, then close the dedicated connection
      $this->disconnect();

      if ($Connection !== null && $Connection->status <= Connections::STATUS_ESTABLISHED) {
         $Connection->writing($Connection->Socket, 5, "0\r\n\r\n");

         // ?: Let a stalled tail drain before closing the socket
         if ($Connection->pendingBuffer !== '') {
            $Connection->closeAfterDrain = true;
            return;
         }

         $Connection->close();
      }
   }

   /**
    * Teardown only — no wire writes (Disconnecting: invoked by
    * `Connection::close()` on any close path). Idempotent.
    */
   public function disconnect (): void
   {
      // ?
      if ($this->closed) {
         return;
      }
      $this->closed = true;

      // @ Stop the supervisor
      if ($this->timer !== 0) {
         Timer::del($this->timer);
         $this->timer = 0;
      }

      // # Hook (exactly once)
      $this->notify();
   }

   /**
    * Run the application `Close` hook exactly once — contained: it is
    * invoked from transport cleanup (Connection::close(), Stream::close(),
    * decoder GOAWAY/fail loops) and from the drain handoff in close(); an
    * application exception here must never abort protocol bookkeeping.
    */
   private function notify (): void
   {
      $Close = $this->Close;
      $this->Close = null;
      $this->Tick = null;
      // ?
      if ($Close === null) {
         return;
      }

      // @
      try {
         $Close($this);
      }
      catch (Throwable $Throwable) {
         Throwables::notify($Throwable, ['origin' => 'sse.close']);
      }
   }

   /**
    * Merge the application Cache-Control policy with the mandatory
    * `no-cache`. Directive-aware and quote-safe: `no-cache` only counts as
    * present when it is a directive NAME — not a substring of an extension
    * name (`x-no-cache=1`) nor text inside a quoted value
    * (`private="a, no-cache"`), including behind quoted-pairs (`\"`).
    */
   private function merge (string $cache): string
   {
      // ?
      if ($cache === '') {
         return 'no-cache';
      }

      // @ Tokenize on top-level commas (quoted strings may carry commas)
      $directives = [];
      $token = '';
      $quoted = false;
      $length = strlen($cache);
      for ($offset = 0; $offset < $length; $offset++) {
         $char = $cache[$offset];

         // ? Quoted-pair (RFC 9110 §5.6.4) — the escaped octet is data:
         //   `\"` never closes the string, `\,` is never a separator
         if ($quoted && $char === '\\' && $offset + 1 < $length) {
            $token .= $char . $cache[$offset + 1];
            $offset++;
            continue;
         }
         if ($char === '"') {
            $quoted = ! $quoted;
         }
         if ($char === ',' && $quoted === false) {
            $directives[] = $token;
            $token = '';
            continue;
         }

         $token .= $char;
      }
      $directives[] = $token;

      // @ Match the directive NAME (the part before any `=`)
      foreach ($directives as $directive) {
         $name = trim(explode('=', $directive, 2)[0]);

         if (strcasecmp($name, 'no-cache') === 0) {
            // :? The application already states it
            return $cache;
         }
      }

      // :
      return "{$cache}, no-cache";
   }

   /**
    * Reset the HTTP/2 stream (RST_STREAM CANCEL), release it from the
    * decoder and tear this unit down — the resource-protection exit used
    * on backlog-cap breach and flow-control stall.
    */
   private function abort (): void
   {
      $H2 = $this->H2;
      $Stream = $this->Stream;
      $Connection = $this->Connection;

      // ? Stream already released — teardown only
      if (
         $H2 === null || $Stream === null || $Connection === null
         || ($H2->Streams[$this->stream] ?? null) !== $Stream
      ) {
         $this->disconnect();
         return;
      }

      $frames = $H2->outbox . Frame::pack(
         HTTP2::FRAME_RST_STREAM, 0, $this->stream, pack('N', Errors::Cancel->value)
      );
      $H2->outbox = '';

      // @ Release BEFORE the write — writing() can close the connection
      //   synchronously (decoder teardown clears the whole stream map and
      //   zeroes `opened`); releasing after would double-decrement the
      //   count. close() notifies this unit (Owner) exactly once.
      $Stream->close();
      unset($H2->Streams[$this->stream]);
      $H2->opened--;

      $Connection->writing($Connection->Socket, strlen($frames), $frames);
   }

   /**
    * Write one already-serialized event-stream payload to the transport.
    */
   private function write (string $payload): bool
   {
      // ? Transport gone
      $Connection = $this->Connection;
      if ($Connection === null || $Connection->status > Connections::STATUS_ESTABLISHED) {
         $this->disconnect();
         return false;
      }

      // # HTTP/2 — DATA frames bounded by the connection + stream send
      //   windows; starved bytes park in the backlog and flow on
      //   WINDOW_UPDATE via the decoder's pump()
      if ($this->stream !== 0) {
         $H2 = $this->H2;
         $Stream = $this->Stream;

         // ? Stream reset by the peer
         if (
            $H2 === null || $Stream === null
            || ($H2->Streams[$this->stream] ?? null) !== $Stream
         ) {
            $this->disconnect();
            return false;
         }

         // ? Outbound backlog cap — a flow-control-starved peer (one that
         //   stops sending WINDOW_UPDATE) must not grow unbounded in
         //   memory. The budget is per CONNECTION: every stream's parked
         //   bytes count against it, so concurrent slow streams cannot
         //   multiply the transport pendingBuffer cap by the stream limit.
         //   On breach, reset THIS stream (CANCEL) and tear this unit down.
         $backlogged = strlen($payload);
         foreach ($H2->Streams as $Sibling) {
            $backlogged += strlen($Sibling->backlog);
         }
         if ($backlogged > TCP_Server_CLI::$maxPendingBytes) {
            $this->abort();
            return false;
         }

         // ! Stall clock — a write that PARKS bytes into an empty backlog
         //   opens a fresh flow-control period (drain() itself advances the
         //   clock on every real consumption)
         $vacant = $Stream->backlog === '';

         $Stream->backlog .= $payload;
         [$frames, ] = $H2->drain($Stream, $this->stream);

         $buffer = "{$H2->outbox}{$frames}";
         $H2->outbox = '';

         if ($vacant && $Stream->backlog !== '') {
            $Stream->drained = time();
         }

         $this->wrote = time();

         // :
         return $Connection->writing($Connection->Socket, strlen($buffer), $buffer);
      }

      // # HTTP/1.1 — one chunk per payload
      $chunk = dechex(strlen($payload)) . "\r\n{$payload}\r\n";

      $this->wrote = time();

      // :
      return $Connection->writing($Connection->Socket, strlen($chunk), $chunk);
   }
}
