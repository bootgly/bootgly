<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI;


use const PHP_INT_MAX;
use function ctype_digit;
use function is_array;
use function max;
use function min;
use function ord;
use function pack;
use function strlen;
use function strpbrk;
use function strtolower;
use function substr;
use function unpack;

use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Modules\HTTP2\Settings;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Session\Stream;


/**
 * HTTP/2 client connection engine (RFC 9113) — a pure, socketless state
 * machine, one instance per h2-negotiated TCP connection.
 *
 * Bytes in (`feed()`) → completion records out (`$done`); frames out ride
 * the `$outbox` string, which the transport owner moves into the socket
 * output. The engine never touches sockets, Requests or Responses — it is
 * the role-inverted mirror of the server's `Decoder_HTTP2`.
 */
final class Session
{
   // @ Connection-specific request fields stripped before HPACK (RFC 9113 §8.2.2)
   protected const array FORBIDDEN = [
      'connection' => true,
      'expect' => true,
      'host' => true,
      'keep-alive' => true,
      'proxy-connection' => true,
      'te' => true,
      'transfer-encoding' => true,
      'upgrade' => true
   ];

   // * Config
   /** Local cap on concurrent locally-initiated streams (mirror of the server engine). */
   public static int $streams = 128;
   /** Advertised + enforced SETTINGS_MAX_HEADER_LIST_SIZE. */
   public static int $list = 16384;
   /** Inbound octets consumed before a WINDOW_UPDATE replenish. */
   public static int $replenish = 32768;
   /** Per-stream response byte cap (head + body); 0 = unbounded. */
   public int $limit = 0;

   // * Data
   /** Our advertised settings (push=false, streams=128, list=16384). */
   public Settings $Local;
   /** The server's settings, applied from its SETTINGS frames. */
   public Settings $Remote;
   /** Response header-block decoding (per-connection dynamic table). */
   public HPACK $HPACK;
   /** @var array<int, Stream> */
   public protected(set) array $Streams = [];

   // * Metadata
   // # Transport
   /** Frames awaiting write — the consumer moves this into the socket output. */
   public string $outbox = '';
   /** Partial-frame carry between feeds. */
   public string $buffer = '';
   // # Completion
   /** @var array<int, array{stream: int, code: int, headerRaw: string, body: string, error: null|Errors, retryable: bool}> */
   public array $done = [];
   // # Flow control (connection level)
   /** Send window (peer-replenished). */
   public int $window;
   /** Receive window we granted the peer (RFC 9113 §6.9). */
   public int $supply;
   /** Inbound octets pending replenishment (since our last WINDOW_UPDATE). */
   public int $pending;
   // # Streams
   /** Next odd (locally-initiated) stream id (RFC 9113 §5.1.1). */
   public protected(set) int $next = 1;
   /** Open stream count. */
   public protected(set) int $opened = 0;
   /** Spare stream capacity (pool protocol-awareness hook). */
   public int $capacity {
      get => ($this->closing || $this->error !== null)
         ? 0
         : max(0, min(self::$streams, $this->Remote->streams) - $this->opened);
   }
   // # Connection state
   /** Peer (server) SETTINGS received (RFC 9113 §3.4). */
   public protected(set) bool $settled = false;
   /** GOAWAY received or sent. */
   public protected(set) bool $closing = false;
   /** Last stream id the peer promised to process (from its GOAWAY). */
   public protected(set) int $goaway = PHP_INT_MAX;
   /** Connection error — set once, the engine is dead afterwards. */
   public protected(set) null|Errors $error = null;
   // # Header block continuation
   /** Stream id awaiting CONTINUATION frames (0 = none). */
   private int $expected = 0;
   /** Accumulated header block fragments. */
   private string $fragments = '';
   /** END_STREAM flag carried by the opening HEADERS frame. */
   private bool $ending = false;


   public function __construct ()
   {
      // * Data
      $Local = new Settings;
      $Local->push = false;
      $Local->streams = self::$streams;
      $Local->list = self::$list;
      $this->Local = $Local;
      $this->Remote = new Settings;
      $this->HPACK = new HPACK($Local->table);

      // * Metadata
      // @ The client preface + our SETTINGS lead every connection (RFC 9113 §3.4)
      $settings = Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, $Local->pack());
      $this->outbox = HTTP2::PREFACE . $settings;
      $this->window = 65535;
      $this->supply = $Local->window;
      $this->pending = 0;
   }

   /**
    * Open a locally-initiated stream: queue HEADERS (+CONTINUATION) and as
    * much of the request body as the send windows admit — the unsent tail
    * is parked in the stream backlog and drained by later credit.
    *
    * @param array<string, string|array<int, string>> $fields Regular fields, any case (connection-specific ones are stripped).
    *
    * @return int The stream id, or `0` when no stream can be opened.
    */
   public function open (string $method, string $scheme, string $authority, string $path, array $fields, string $body = ''): int
   {
      // ? No spare capacity, closing handshake or dead connection
      if ($this->capacity <= 0) {
         return 0;
      }

      // ! Header list: pseudo-headers first (RFC 9113 §8.3.1), then regular
      //   fields lowercased, connection-specific ones stripped (§8.2.2)
      $list = [
         [':method', $method],
         [':scheme', $scheme],
         [':authority', $authority],
         [':path', $path]
      ];
      $size = 0;
      foreach ($list as [$name, $value]) {
         $size += strlen($name) + strlen($value) + 32;
      }
      foreach ($fields as $name => $value) {
         $name = strtolower($name);
         // ? Connection-specific fields are forbidden in HTTP/2 (RFC 9113 §8.2.2)
         if (isSet(self::FORBIDDEN[$name])) {
            continue;
         }

         // @@ Multi-value fields become one entry per value (`cookie` included)
         foreach (is_array($value) ? $value : [$value] as $each) {
            $list[] = [$name, $each];
            $size += strlen($name) + strlen($each) + 32;
         }
      }

      // ? Peer header-list cap (RFC 9113 §10.5.1) — local failure, nothing
      //   is sent and no stream id is spent
      if ($size > $this->Remote->list) {
         return 0;
      }

      // ! Allocate the stream (odd, strictly increasing ids — RFC 9113 §5.1.1)
      $id = $this->next;
      $this->next += 2;
      $this->opened++;

      $Stream = new Stream($id, $this->Remote->window, $this->Local->window);
      $Stream->headless = ($method === 'HEAD');
      $this->Streams[$id] = $Stream;

      // @ HEADERS (+CONTINUATION when the block exceeds the peer frame size)
      $block = HPACK::encode($list);
      $limit = $this->Remote->frame;
      $flags = HTTP2::FLAG_END_HEADERS;
      if ($body === '') {
         $flags |= HTTP2::FLAG_END_STREAM;
      }

      $length = strlen($block);
      if ($length <= $limit) {
         $this->outbox .= Frame::pack(HTTP2::FRAME_HEADERS, $flags, $id, $block);
      }
      else {
         // @ END_STREAM stays on HEADERS; END_HEADERS moves to the last CONTINUATION
         $this->outbox .= Frame::pack(
            HTTP2::FRAME_HEADERS,
            $flags & ~HTTP2::FLAG_END_HEADERS,
            $id,
            substr($block, 0, $limit)
         );
         for ($offset = $limit; $offset < $length; $offset += $limit) {
            $this->outbox .= Frame::pack(
               HTTP2::FRAME_CONTINUATION,
               $offset + $limit >= $length ? HTTP2::FLAG_END_HEADERS : 0,
               $id,
               substr($block, $offset, $limit)
            );
         }
      }

      // @ Body: as much DATA as the send windows admit; the tail is parked
      if ($body !== '') {
         $Stream->backlog = $body;
         $this->drain($id, $Stream);
      }

      // :
      return $id;
   }

   /**
    * Consume peer bytes: parse frames, update state, queue answers into
    * `$outbox` and move finished streams into `$done`.
    *
    * @return bool `false` on a connection error (`$error` set, GOAWAY queued).
    */
   public function feed (string $bytes): bool
   {
      // ? Dead connection
      if ($this->error !== null) {
         return false;
      }

      // ! Work buffer: carried partial bytes + this feed
      if ($this->buffer === '') {
         $work = $bytes;
      }
      else {
         $work = "{$this->buffer}{$bytes}";
         $this->buffer = '';
      }
      $length = strlen($work);
      $offset = 0;

      // @@ Frame loop — the 9-octet header parse is inlined on purpose (see
      //    the Frame docblock: decoding allocates no per-frame object)
      while ($length - $offset >= 9) {
         /** @var array{word: int, flags: int, stream: int} $head */
         $head = unpack('Nword/Cflags/Nstream', $work, $offset);
         $type = $head['word'] & 0xff;
         $payload = $head['word'] >> 8;
         $flags = $head['flags'];
         $stream = $head['stream'] & 0x7fffffff;

         // ? Frame larger than our advertised SETTINGS_MAX_FRAME_SIZE
         if ($payload > $this->Local->frame) {
            return $this->fail(Errors::FrameSize);
         }
         // ? Frame payload not fully buffered yet
         if ($length - $offset < 9 + $payload) {
            break;
         }

         $data = $payload === 0 ? '' : substr($work, $offset + 9, $payload);
         $offset += 9 + $payload;

         // ? A header block in progress admits only its own CONTINUATION
         if ($this->expected !== 0 && ($type !== HTTP2::FRAME_CONTINUATION || $stream !== $this->expected)) {
            return $this->fail(Errors::Protocol);
         }
         // ? The server preface is a non-ACK SETTINGS frame (RFC 9113 §3.4)
         if (
            $this->settled === false
            && ($type !== HTTP2::FRAME_SETTINGS || ($flags & HTTP2::FLAG_ACK) !== 0)
         ) {
            return $this->fail(Errors::Protocol);
         }

         switch ($type) {
            case HTTP2::FRAME_DATA:
               // ? DATA on stream 0 or on a stream that never existed — even
               //   ids are server-initiated and we advertise push=false
               if ($stream === 0 || $stream >= $this->next || ($stream & 1) === 0) {
                  return $this->fail(Errors::Protocol);
               }

               // ? Receive flow control, connection level (RFC 9113 §6.9) —
               //   the whole payload counts, padding included, even for
               //   recently-closed streams
               $this->supply -= $payload;
               if ($this->supply < 0) {
                  return $this->fail(Errors::FlowControl);
               }
               // @ Connection-level replenish
               $this->pending += $payload;
               if ($this->pending >= self::$replenish) {
                  $this->outbox .= Frame::pack(
                     HTTP2::FRAME_WINDOW_UPDATE, 0, 0, pack('N', $this->pending)
                  );
                  $this->supply += $this->pending;
                  $this->pending = 0;
               }

               // ? DATA on a recently-closed stream — ignore (flow accounted)
               $Stream = $this->Streams[$stream] ?? null;
               if ($Stream === null) {
                  break;
               }
               // ? DATA before the response head / after END_STREAM
               if ($Stream->headed === false || $Stream->ended) {
                  $this->reset($stream, Errors::Protocol);
                  break;
               }

               // ? Receive flow control, stream level
               $Stream->supply -= $payload;
               if ($Stream->supply < 0) {
                  return $this->fail(Errors::FlowControl);
               }

               // ? Padding stripping
               if (($flags & HTTP2::FLAG_PADDED) !== 0) {
                  if ($payload === 0) {
                     return $this->fail(Errors::Protocol);
                  }
                  $padding = ord($data[0]);
                  if ($padding + 1 > $payload) {
                     return $this->fail(Errors::Protocol);
                  }
                  $data = substr($data, 1, $payload - 1 - $padding);
               }

               // @ Accumulate the response body
               $Stream->body .= $data;

               // ? Per-stream response byte cap — local cancel
               if ($this->limit > 0 && strlen($Stream->head) + strlen($Stream->body) > $this->limit) {
                  $this->reset($stream, Errors::Cancel);
                  break;
               }

               // ?: END_STREAM — the response is complete
               if (($flags & HTTP2::FLAG_END_STREAM) !== 0) {
                  $this->finish($stream, $Stream);
                  break;
               }

               // @ Stream-level replenish while the body is still flowing
               $Stream->pending += $payload;
               if ($Stream->pending >= self::$replenish) {
                  $this->outbox .= Frame::pack(
                     HTTP2::FRAME_WINDOW_UPDATE, 0, $stream, pack('N', $Stream->pending)
                  );
                  $Stream->supply += $Stream->pending;
                  $Stream->pending = 0;
               }
               break;

            case HTTP2::FRAME_HEADERS:
               // ? HEADERS on stream 0 (RFC 9113 §6.2)
               if ($stream === 0) {
                  return $this->fail(Errors::Protocol);
               }

               // ? Padding stripping
               if (($flags & HTTP2::FLAG_PADDED) !== 0) {
                  if ($payload === 0) {
                     return $this->fail(Errors::Protocol);
                  }
                  $padding = ord($data[0]);
                  $data = substr($data, 1);
                  if ($padding > strlen($data)) {
                     return $this->fail(Errors::Protocol);
                  }
                  if ($padding > 0) {
                     $data = substr($data, 0, -$padding);
                  }
               }
               // ? Priority field stripping (deprecated — RFC 9113 §5.3)
               if (($flags & HTTP2::FLAG_PRIORITY) !== 0) {
                  if (strlen($data) < 5) {
                     return $this->fail(Errors::FrameSize);
                  }
                  $data = substr($data, 5);
               }

               // ? HEADERS for a stream we never opened — the client never
               //   accepts pushes (Local advertises push=false). Locally
               //   cancelled streams (odd id below $next) still get their
               //   block DECODED (HPACK state) and then discarded.
               if (
                  isSet($this->Streams[$stream]) === false
                  && (($stream & 1) === 0 || $stream >= $this->next)
               ) {
                  return $this->fail(Errors::Protocol);
               }

               // !
               $this->ending = ($flags & HTTP2::FLAG_END_STREAM) !== 0;

               // ?: Block complete in one frame?
               if (($flags & HTTP2::FLAG_END_HEADERS) !== 0) {
                  if ($this->resolve($stream, $data) === false) {
                     return false;
                  }
                  break;
               }

               // @ Await CONTINUATION frames
               $this->expected = $stream;
               $this->fragments = $data;
               break;

            case HTTP2::FRAME_CONTINUATION:
               // ? Stray CONTINUATION (no block in progress)
               if ($this->expected === 0) {
                  return $this->fail(Errors::Protocol);
               }

               $this->fragments .= $data;
               // ? Compressed accumulation cap — CONTINUATION flood guard
               if (strlen($this->fragments) > 2 * self::$list) {
                  return $this->fail(Errors::EnhanceYourCalm);
               }

               // ?: Block complete?
               if (($flags & HTTP2::FLAG_END_HEADERS) !== 0) {
                  $this->expected = 0;
                  $block = $this->fragments;
                  $this->fragments = '';
                  if ($this->resolve($stream, $block) === false) {
                     return false;
                  }
               }
               break;

            case HTTP2::FRAME_SETTINGS:
               if ($stream !== 0) {
                  return $this->fail(Errors::Protocol);
               }
               // ?: Peer acknowledged our settings
               if (($flags & HTTP2::FLAG_ACK) !== 0) {
                  if ($payload !== 0) {
                     return $this->fail(Errors::FrameSize);
                  }
                  break;
               }

               // @ Apply, adjusting every open send window by the
               //   INITIAL_WINDOW_SIZE delta (RFC 9113 §6.9.2)
               $window = $this->Remote->window;
               $error = $this->Remote->parse($data);
               if ($error !== null) {
                  return $this->fail($error);
               }
               $delta = $this->Remote->window - $window;
               if ($delta !== 0) {
                  foreach ($this->Streams as $Stream) {
                     $Stream->window += $delta;
                     // ? The delta may not push a window past 2^31-1
                     //   (RFC 9113 §6.9.2 — connection error)
                     if ($Stream->window > 2147483647) {
                        return $this->fail(Errors::FlowControl);
                     }
                  }
               }
               $this->settled = true;

               $this->outbox .= Frame::pack(HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK, 0);

               // @ Grown windows may unblock parked request tails
               $this->pump();
               break;

            case HTTP2::FRAME_PING:
               if ($stream !== 0) {
                  return $this->fail(Errors::Protocol);
               }
               if ($payload !== 8) {
                  return $this->fail(Errors::FrameSize);
               }
               // ?: Answer non-ACK pings with the same opaque payload
               if (($flags & HTTP2::FLAG_ACK) === 0) {
                  $this->outbox .= Frame::pack(HTTP2::FRAME_PING, HTTP2::FLAG_ACK, 0, $data);
               }
               break;

            case HTTP2::FRAME_WINDOW_UPDATE:
               if ($payload !== 4) {
                  return $this->fail(Errors::FrameSize);
               }
               /** @var array{1: int} $update */
               $update = unpack('N', $data);
               $increment = $update[1] & 0x7fffffff;
               // ? Zero increment is a protocol error (RFC 9113 §6.9)
               if ($increment === 0) {
                  if ($stream === 0) {
                     return $this->fail(Errors::Protocol);
                  }
                  $this->reset($stream, Errors::Protocol);
                  break;
               }

               // ?: Connection-level credit
               if ($stream === 0) {
                  $this->window += $increment;
                  // ? Window overflow (RFC 9113 §6.9.1)
                  if ($this->window > 2147483647) {
                     return $this->fail(Errors::FlowControl);
                  }
                  $this->pump();
                  break;
               }

               // ? WINDOW_UPDATE on a closed / untracked stream — ignore
               $Stream = $this->Streams[$stream] ?? null;
               if ($Stream === null) {
                  break;
               }

               $Stream->window += $increment;
               if ($Stream->window > 2147483647) {
                  $this->reset($stream, Errors::FlowControl);
                  break;
               }
               $this->pump();
               break;

            case HTTP2::FRAME_RST_STREAM:
               if ($stream === 0) {
                  return $this->fail(Errors::Protocol);
               }
               if ($payload !== 4) {
                  return $this->fail(Errors::FrameSize);
               }

               // ? RST_STREAM on an untracked stream — ignore
               $Stream = $this->Streams[$stream] ?? null;
               if ($Stream === null) {
                  break;
               }

               /** @var array{1: int} $reason */
               $reason = unpack('N', $data);
               $code = $reason[1];
               // @ REFUSED_STREAM guarantees no processing — retryable (§8.7)
               $this->done[$stream] = [
                  'stream' => $stream,
                  'code' => $Stream->code,
                  'headerRaw' => $Stream->head,
                  'body' => $Stream->body,
                  'error' => Errors::tryFrom($code) ?? Errors::Internal,
                  'retryable' => $code === Errors::RefusedStream->value
               ];
               unset($this->Streams[$stream]);
               $this->opened--;
               break;

            case HTTP2::FRAME_GOAWAY:
               if ($stream !== 0) {
                  return $this->fail(Errors::Protocol);
               }
               // ? Last-Stream-ID + Error Code are mandatory (RFC 9113 §6.8)
               if ($payload < 8) {
                  return $this->fail(Errors::FrameSize);
               }

               /** @var array{1: int} $last */
               $last = unpack('N', $data);
               $this->closing = true;
               $this->goaway = $last[1] & 0x7fffffff;

               // @ Streams above the peer's last processed id were never
               //   acted upon (RFC 9113 §6.8) — safe to retry elsewhere
               foreach ($this->Streams as $id => $Stream) {
                  if ($id > $this->goaway) {
                     $this->done[$id] = [
                        'stream' => $id,
                        'code' => 0,
                        'headerRaw' => '',
                        'body' => '',
                        'error' => Errors::RefusedStream,
                        'retryable' => true
                     ];
                     unset($this->Streams[$id]);
                     $this->opened--;
                  }
               }
               break;

            case HTTP2::FRAME_PUSH_PROMISE:
               // ? We advertise SETTINGS_ENABLE_PUSH=0 (RFC 9113 §6.6)
               return $this->fail(Errors::Protocol);

            default:
               // @ Unknown frame types must be ignored (RFC 9113 §4.1)
         }
      }

      // @ Stash any partial frame for the next feed
      if ($offset < $length) {
         $this->buffer = substr($work, $offset);
      }

      // :
      return true;
   }

   /**
    * Cancel a stream locally (timeout / response cap): queue RST_STREAM and
    * fail the stream with a non-retryable completion record.
    */
   public function reset (int $stream, Errors $error): void
   {
      // ? Unknown / already-released stream
      $Stream = $this->Streams[$stream] ?? null;
      if ($Stream === null) {
         return;
      }

      // @ Tell the peer, then fail the stream locally
      $this->outbox .= Frame::pack(
         HTTP2::FRAME_RST_STREAM, 0, $stream, pack('N', $error->value)
      );

      $this->done[$stream] = [
         'stream' => $stream,
         'code' => $Stream->code,
         'headerRaw' => $Stream->head,
         'body' => $Stream->body,
         'error' => $error,
         'retryable' => false
      ];
      unset($this->Streams[$stream]);
      $this->opened--;
   }

   /**
    * Graceful local shutdown: queue GOAWAY(None) and stop opening streams;
    * in-flight streams may still complete.
    */
   public function close (): void
   {
      // ? Already closing or dead
      if ($this->closing) {
         return;
      }

      // @ The client never accepts pushes, so the last peer-initiated
      //   stream id is always 0 (RFC 9113 §6.8)
      $this->outbox .= Frame::pack(
         HTTP2::FRAME_GOAWAY, 0, 0, pack('NN', 0, Errors::None->value)
      );
      $this->closing = true;
   }

   // ---

   /**
    * Resolve a complete inbound header block: decode HPACK, then apply the
    * response head / trailers rules (RFC 9113 §8.3.2 / §8.1).
    *
    * @return bool `false` on a connection error (`fail()` already ran).
    */
   private function resolve (int $stream, string $block): bool
   {
      // @ HPACK decompression is connection state — failures are fatal
      $fields = $this->HPACK->decode($block, self::$list);
      if ($fields === null) {
         return $this->fail(Errors::Compression);
      }

      // ? Locally cancelled stream (reset between HEADERS and END_HEADERS,
      //   or a response racing a local RST) — the block was decoded to keep
      //   the HPACK dynamic table in sync; its content is discarded
      $Stream = $this->Streams[$stream] ?? null;
      if ($Stream === null) {
         return true;
      }

      // ?: Trailers — HEADERS on an already-headed stream
      if ($Stream->headed) {
         // ? Trailers must end the stream (RFC 9113 §8.1)
         if ($this->ending === false) {
            $this->reset($stream, Errors::Protocol);
            return true;
         }
         // ? No pseudo-headers in trailers
         foreach ($fields as [$name, $value]) {
            if ($name === '' || $name[0] === ':') {
               $this->reset($stream, Errors::Protocol);
               return true;
            }
         }

         // @ Trailer content is discarded (v1) — the stream just finishes
         $this->finish($stream, $Stream);
         return true;
      }

      // @ Response head (RFC 9113 §8.3.2): `:status` alone, digits only,
      //   never after a regular field
      $code = null;
      $regular = false;
      $head = '';
      $size = null;

      foreach ($fields as [$name, $value]) {
         if ($name === '') {
            $this->reset($stream, Errors::Protocol);
            return true;
         }

         // ? Field values must not smuggle line breaks into the synthesized
         //   head (RFC 9113 §8.2.1 — CR/LF/NUL are never valid)
         if (strpbrk("{$name}{$value}", "\r\n\0") !== false) {
            $this->reset($stream, Errors::Protocol);
            return true;
         }

         if ($name[0] === ':') {
            // ? Request/unknown pseudo-headers, duplicates, non-digit codes
            //   and pseudo-headers after a regular field are all malformed
            if ($regular || $name !== ':status' || $code !== null || ctype_digit($value) === false) {
               $this->reset($stream, Errors::Protocol);
               return true;
            }
            $code = (int) $value;
            continue;
         }

         $regular = true;

         if ($name === 'content-length') {
            // ? Digit-only; multiple different declarations are malformed
            if (ctype_digit($value) === false || ($size !== null && $size !== (int) $value)) {
               $this->reset($stream, Errors::Protocol);
               return true;
            }
            $size = (int) $value;
         }

         // @ Synthesize the head (multi-values stay as separate lines)
         $head .= "$name: $value\r\n";
      }

      // ? `:status` is mandatory (RFC 9113 §8.3.2)
      if ($code === null) {
         $this->reset($stream, Errors::Protocol);
         return true;
      }

      // ?: 1xx interim head — discarded entirely; the final head follows
      //   in another HEADERS on the same, still-open stream (RFC 9110 §15.2)
      if ($code >= 100 && $code <= 199) {
         // ? An interim response cannot end the stream
         if ($this->ending) {
            $this->reset($stream, Errors::Protocol);
         }
         return true;
      }

      // @ Final head
      $Stream->code = $code;
      $Stream->head = $head;
      $Stream->length = $size;
      $Stream->headed = true;

      // ?: END_STREAM on HEADERS — the response is complete
      if ($this->ending) {
         $this->finish($stream, $Stream);
      }

      // :
      return true;
   }

   /**
    * Finish a stream (END_STREAM via DATA or HEADERS): verify the declared
    * content-length and move the completion record into `$done`.
    */
   private function finish (int $stream, Stream $Stream): void
   {
      $Stream->ended = true;

      // ? The response completed while the request body tail was still
      //   parked — tell the peer to stop expecting it (RFC 9113 §8.1)
      if ($Stream->backlog !== '') {
         $Stream->backlog = '';
         $this->outbox .= Frame::pack(
            HTTP2::FRAME_RST_STREAM, 0, $stream, pack('N', Errors::None->value)
         );
      }

      // ? Declared content-length must match the received body (RFC 9113
      //   §8.1.1) — HEAD responses and 204/304 heads describe a body that
      //   is deliberately not transferred
      if (
         $Stream->length !== null
         && $Stream->length !== strlen($Stream->body)
         && $Stream->headless === false
         && $Stream->code !== 204
         && $Stream->code !== 304
      ) {
         $this->reset($stream, Errors::Protocol);
         return;
      }

      // @ Completion record
      $this->done[$stream] = [
         'stream' => $stream,
         'code' => $Stream->code,
         'headerRaw' => $Stream->head,
         'body' => $Stream->body,
         'error' => null,
         'retryable' => false
      ];
      unset($this->Streams[$stream]);
      $this->opened--;
   }

   /**
    * Drain one stream's parked request tail into DATA frames bounded by
    * the connection + stream send windows and the peer frame size;
    * END_STREAM rides the frame that empties the backlog.
    */
   private function drain (int $id, Stream $Stream): void
   {
      // !
      $limit = $this->Remote->frame;

      // @@
      while ($Stream->backlog !== '' && $this->window > 0 && $Stream->window > 0) {
         $send = min($this->window, $Stream->window, $limit, strlen($Stream->backlog));
         $payload = substr($Stream->backlog, 0, $send);
         $Stream->backlog = substr($Stream->backlog, $send);
         $this->window -= $send;
         $Stream->window -= $send;

         $this->outbox .= Frame::pack(
            HTTP2::FRAME_DATA,
            $Stream->backlog === '' ? HTTP2::FLAG_END_STREAM : 0,
            $id,
            $payload
         );
      }
   }

   /**
    * Drain window-blocked request tails after WINDOW_UPDATE / SETTINGS credit.
    */
   private function pump (): void
   {
      // ?
      if ($this->window <= 0) {
         return;
      }

      // @@
      foreach ($this->Streams as $id => $Stream) {
         if ($Stream->backlog === '' || $Stream->window <= 0) {
            continue;
         }

         $this->drain($id, $Stream);

         // ? Connection credit spent
         if ($this->window <= 0) {
            break;
         }
      }
   }

   /**
    * Connection error: queue GOAWAY, fail every in-flight stream and kill
    * the engine (`$error` set — every later `feed()` returns `false`).
    */
   private function fail (Errors $error): false
   {
      // @ The client never accepts pushes, so the last peer-initiated
      //   stream id is always 0 (RFC 9113 §6.8)
      $this->outbox .= Frame::pack(
         HTTP2::FRAME_GOAWAY, 0, 0, pack('NN', 0, $error->value)
      );

      $this->error = $error;
      $this->closing = true;
      $this->buffer = '';
      $this->expected = 0;
      $this->fragments = '';

      // @ Fail every in-flight stream — a stream the server never started
      //   answering is safe to retry on another connection
      foreach ($this->Streams as $id => $Stream) {
         $this->done[$id] = [
            'stream' => $id,
            'code' => $Stream->code,
            'headerRaw' => $Stream->head,
            'body' => $Stream->body,
            'error' => $error,
            'retryable' => $Stream->headed === false
         ];
      }
      $this->Streams = [];
      $this->opened = 0;

      // :
      return false;
   }
}
