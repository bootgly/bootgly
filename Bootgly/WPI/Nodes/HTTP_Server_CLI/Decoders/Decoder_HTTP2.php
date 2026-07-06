<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use function array_unshift;
use function count;
use function ctype_digit;
use function fclose;
use function fopen;
use function fread;
use function fseek;
use function fwrite;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function min;
use function ord;
use function pack;
use function strlen;
use function strncmp;
use function strpbrk;
use function strspn;
use function substr;
use function time;
use function unpack;
use Throwable;

use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Feeding;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Modules\HTTP2\Settings;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Stream;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


/**
 * HTTP/2 connection decoder (RFC 9113).
 *
 * One stateful instance per connection, installed on `$Package->Decoder`
 * by the cleartext prior-knowledge probe (`Request::decode()`) or by the
 * TLS-ALPN installer (`HTTP_Server_CLI::configure()`). The instance is
 * also the per-connection protocol unit (`$Package->decoded`), so
 * `Connection::close()` tears it down through `Disconnecting`.
 *
 * `decode()` consumes frames incrementally and returns `States::Complete`
 * once per stream that finished (END_STREAM + valid head), so the existing
 * pipelining loop in `Packages::reading()` dispatches N streams per TCP
 * read. Control frames are answered through `$outbox`, which is flushed
 * together with the next response — typically one single `fwrite` for
 * SETTINGS + ACK + HEADERS + DATA.
 */
class Decoder_HTTP2 extends Decoders implements Disconnecting, Feeding
{
   // @ Connection-specific fields forbidden in HTTP/2 requests (RFC 9113 §8.2.2)
   protected const array FORBIDDEN = [
      'connection' => true,
      'keep-alive' => true,
      'proxy-connection' => true,
      'transfer-encoding' => true,
      'upgrade' => true
   ];
   // @ Lowercase HTTP token chars allowed in HTTP/2 regular field names
   protected const string FIELD_NAME = "!#$%&'*+-.0123456789^_`abcdefghijklmnopqrstuvwxyz|~";
   // @ RFC field values allow HTAB; every other C0 control and DEL is invalid
   protected const string FIELD_VALUE_CTLS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0a\x0b\x0c\x0d\x0e\x0f"
      . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";
   // @ Pseudo-header values are typed tokens/URI components; any CTL is invalid
   protected const string PSEUDO_VALUE_CTLS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
      . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

   // * Config
   /** @var int Advertised + enforced SETTINGS_MAX_CONCURRENT_STREAMS */
   public static int $streams = 128;
   /** @var int Advertised + enforced decoded header list cap (SETTINGS_MAX_HEADER_LIST_SIZE) */
   public static int $list = 16384;
   /** @var int Stream resets tolerated per 10s window before GOAWAY (CVE-2023-44487) */
   public static int $resets = 64;
   /**
    * @var int Inbound octets consumed before a WINDOW_UPDATE replenishes the
    * peer (default: half the initial window). Bodies are currently consumed
    * on arrival, so credit flows back as fast as this threshold is crossed;
    * raising it toward the window size delays credit and applies real
    * receive-side backpressure.
    */
   public static int $replenish = 32768;

   // * Data
   public Settings $Local;
   public Settings $Remote;
   public HPACK $HPACK;
   /** @var array<int, Stream> */
   public array $Streams;

   // * Metadata
   // # Transport
   // @ Partial-frame carry between reads
   public string $buffer;
   // @ Pending control frames, batched into the next write
   public string $outbox;
   // # Flow control
   // @ Connection-level send window (peer-replenished)
   public int $window;
   // @ Connection-level receive window we granted the peer (RFC 9113 §6.9) —
   //   decremented by every DATA payload, credited back by the
   //   WINDOW_UPDATEs we emit; a peer driving it negative is punished
   //   with FLOW_CONTROL_ERROR.
   public int $supply;
   // @ Inbound octets pending replenishment (since our last WINDOW_UPDATE)
   public int $pending;
   // # Streams
   // @ Stream id currently dispatched to the router (0 = none)
   public int $current;
   // @ Highest client-initiated stream id seen
   public int $last;
   // @ Open stream count
   public int $opened;
   // # Connection state
   public bool $prefaced;
   // @ Whether the client's initial SETTINGS arrived (must directly follow
   //   the preface — RFC 9113 §3.4)
   public bool $settled;
   public bool $closing;
   // # Header block continuation
   // @ Stream id awaiting CONTINUATION frames (0 = none)
   protected int $expected;
   // @ Accumulated header block fragments
   protected string $fragments;
   // @ END_STREAM flag carried by the opening HEADERS frame
   protected bool $ending;
   // @ Whether the pending block is trailers on an already-open stream
   protected bool $trailing;
   // @ Whether the pending HEADERS carried a self-dependent priority field
   //   (RFC 9113 §5.3.1) — resolved as a stream error after HPACK decode
   protected bool $circular;
   // # Rapid-reset accounting
   protected int $churn;
   protected int $since;
   // # Transport back-reference (for Disconnecting teardown)
   protected null|TCP_Packages $Package;


   public function __construct ()
   {
      // * Data
      $Local = new Settings;
      $Local->streams = static::$streams;
      $Local->list = static::$list;
      $this->Local = $Local;
      $this->Remote = new Settings;
      $this->HPACK = new HPACK($Local->table);
      $this->Streams = [];

      // * Metadata
      $this->buffer = '';
      $this->outbox = '';
      $this->window = 65535;
      $this->supply = $Local->window;
      $this->pending = 0;
      $this->current = 0;
      $this->last = 0;
      $this->opened = 0;
      $this->prefaced = false;
      $this->settled = false;
      $this->closing = false;
      $this->expected = 0;
      $this->fragments = '';
      $this->ending = false;
      $this->trailing = false;
      $this->circular = false;
      $this->churn = 0;
      $this->since = 0;
      $this->Package = null;
   }

   public function decode (Packages $Package, string $buffer, int $size): States
   {
      /** @var TCP_Packages $Package */
      $this->Package ??= $Package;
      // ! Assemble the work buffer: carried partial bytes + this read.
      //   `$carried` maps work-buffer offsets back into this read's input —
      //   `Packages::reading()` pipelining slices the ORIGINAL input by
      //   `$Package->consumed`.
      if ($this->buffer === '') {
         $work = $buffer;
         $carried = 0;
      }
      else {
         $work = "{$this->buffer}{$buffer}";
         $carried = strlen($this->buffer);
         $this->buffer = '';
      }
      $length = $carried + $size;
      $offset = 0;

      // ? Connection preface (RFC 9113 §3.4) — also sent over TLS-ALPN
      if ($this->prefaced === false) {
         if ($length < 24) {
            $this->buffer = $work;
            $Package->consumed = $size;
            return States::Incomplete;
         }
         if (strncmp($work, HTTP2::PREFACE, 24) !== 0) {
            return $this->fail($Package, Errors::Protocol, 'invalid preface');
         }

         $offset = 24;
         $this->prefaced = true;
         // @ Our SETTINGS lead every HTTP/2 connection (RFC 9113 §3.4)
         $this->outbox .= Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, $this->Local->pack());
      }

      // @@ Frame loop
      while ($length - $offset >= 9) {
         /** @var array{word: int, flags: int, stream: int} $head */
         $head = unpack('Nword/Cflags/Nstream', $work, $offset);
         $type = $head['word'] & 0xff;
         $payload = $head['word'] >> 8;
         $flags = $head['flags'];
         $stream = $head['stream'] & 0x7fffffff;

         // ? Frame larger than our advertised SETTINGS_MAX_FRAME_SIZE
         if ($payload > $this->Local->frame) {
            return $this->fail($Package, Errors::FrameSize, 'frame size exceeded');
         }
         // ? Frame payload not fully buffered yet
         if ($length - $offset < 9 + $payload) {
            break;
         }

         $data = $payload === 0 ? '' : substr($work, $offset + 9, $payload);
         $offset += 9 + $payload;

         // ? A header block in progress admits only its own CONTINUATION
         if ($this->expected !== 0 && ($type !== HTTP2::FRAME_CONTINUATION || $stream !== $this->expected)) {
            return $this->fail($Package, Errors::Protocol, 'expected CONTINUATION');
         }

         // ? The preface must be followed by the client's SETTINGS (RFC 9113 §3.4)
         if (
            $this->settled === false
            && ($type !== HTTP2::FRAME_SETTINGS || ($flags & HTTP2::FLAG_ACK) !== 0)
         ) {
            return $this->fail($Package, Errors::Protocol, 'SETTINGS must follow the preface');
         }

         switch ($type) {
            case HTTP2::FRAME_DATA:
               // ? DATA requires an existing stream
               if ($stream === 0 || $stream > $this->last) {
                  return $this->fail($Package, Errors::Protocol, 'DATA on idle stream');
               }
               $Stream = $this->Streams[$stream] ?? null;

               // ? Receive flow control (RFC 9113 §6.9): DATA beyond the
               //   window we granted (initial + our WINDOW_UPDATEs) is a
               //   connection error. Padding counts.
               $this->supply -= $payload;
               if ($this->supply < 0) {
                  return $this->fail($Package, Errors::FlowControl, 'connection window exceeded');
               }

               // @ Connection-level replenish — credit flows back as the
               //   body is consumed (bodies are consumed on arrival)
               $this->pending += $payload;
               if ($this->pending >= static::$replenish) {
                  $this->outbox .= Frame::pack(
                     HTTP2::FRAME_WINDOW_UPDATE, 0, 0, pack('N', $this->pending)
                  );
                  $this->supply += $this->pending;
                  $this->pending = 0;
               }

               // ? DATA on a closed / half-closed (remote) stream
               if ($Stream === null || $Stream->ended) {
                  $this->reset($stream, Errors::StreamClosed);
                  break;
               }

               // ? Stream-level receive window (RFC 9113 §6.9) — the whole
               //   payload counts, padding included
               $Stream->supply -= $payload;
               if ($Stream->supply < 0) {
                  $this->reset($stream, Errors::FlowControl);
                  break;
               }

               // ? Padding validation
               if (($flags & HTTP2::FLAG_PADDED) !== 0) {
                  if ($payload === 0) {
                     return $this->fail($Package, Errors::Protocol, 'padded empty DATA');
                  }
                  $padding = ord($data[0]);
                  if ($padding + 1 > $payload) {
                     return $this->fail($Package, Errors::Protocol, 'padding exceeds payload');
                  }
                  $data = substr($data, 1, $payload - 1 - $padding);
               }

               // @ Accumulate the request body, bounded by the body cap
               $Stream->body .= $data;
               if (strlen($Stream->body) > Request::$maxBodySize) {
                  $this->deny($stream, 413);
                  break;
               }

               // @ Stream-level replenish while the body is still flowing
               $Stream->pending += $payload;
               if (($flags & HTTP2::FLAG_END_STREAM) === 0) {
                  if ($Stream->pending >= static::$replenish) {
                     $this->outbox .= Frame::pack(
                        HTTP2::FRAME_WINDOW_UPDATE, 0, $stream, pack('N', $Stream->pending)
                     );
                     $Stream->supply += $Stream->pending;
                     $Stream->pending = 0;
                  }
                  break;
               }

               // @ END_STREAM — the request is complete
               $Stream->ended = true;
               if ($this->dispatch($Package, $Stream)) {
                  $Package->consumed = $offset - $carried;
                  return States::Complete;
               }
               break;

            case HTTP2::FRAME_HEADERS:
               if ($stream === 0) {
                  return $this->fail($Package, Errors::Protocol, 'HEADERS on stream 0');
               }

               // ? Padding / priority field stripping
               if (($flags & HTTP2::FLAG_PADDED) !== 0) {
                  if ($payload === 0) {
                     return $this->fail($Package, Errors::Protocol, 'padded empty HEADERS');
                  }
                  $padding = ord($data[0]);
                  $data = substr($data, 1);
                  if ($padding > strlen($data)) {
                     return $this->fail($Package, Errors::Protocol, 'padding exceeds payload');
                  }
                  if ($padding > 0) {
                     $data = substr($data, 0, -$padding);
                  }
               }
               $this->circular = false;
               if (($flags & HTTP2::FLAG_PRIORITY) !== 0) {
                  // @ Priority signaling is deprecated (RFC 9113 §5.3) — the
                  //   tree is ignored, but a stream depending on itself is
                  //   still a stream error (§5.3.1), resolved after HPACK.
                  if (strlen($data) < 5) {
                     return $this->fail($Package, Errors::FrameSize, 'short priority field');
                  }
                  /** @var array{1: int} $dependency */
                  $dependency = unpack('N', $data);
                  if (($dependency[1] & 0x7fffffff) === $stream) {
                     $this->circular = true;
                  }
                  $data = substr($data, 5);
               }

               // # Trailers: HEADERS on an already-open stream
               $Stream = $this->Streams[$stream] ?? null;
               if ($Stream !== null) {
                  // ? Trailers must end the stream (RFC 9113 §8.1)
                  if ($Stream->ended || ($flags & HTTP2::FLAG_END_STREAM) === 0) {
                     return $this->fail($Package, Errors::Protocol, 'HEADERS on open stream without END_STREAM');
                  }
                  $this->trailing = true;
               }
               else {
                  // ? New stream ids are odd and strictly increasing
                  if (($stream & 1) === 0 || $stream <= $this->last) {
                     return $this->fail($Package, Errors::Protocol, 'invalid stream id');
                  }
                  $this->trailing = false;
               }

               $this->ending = ($flags & HTTP2::FLAG_END_STREAM) !== 0;

               // ?: Block complete in one frame?
               if (($flags & HTTP2::FLAG_END_HEADERS) !== 0) {
                  $state = $this->resolve($Package, $stream, $data);
                  if ($state !== null) {
                     if ($state === States::Complete) {
                        $Package->consumed = $offset - $carried;
                     }
                     return $state;
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
                  return $this->fail($Package, Errors::Protocol, 'stray CONTINUATION');
               }

               $this->fragments .= $data;
               // ? Compressed accumulation cap — CONTINUATION flood guard
               if (strlen($this->fragments) > 2 * static::$list) {
                  return $this->fail($Package, Errors::EnhanceYourCalm, 'header block too large');
               }

               // ?: Block complete?
               if (($flags & HTTP2::FLAG_END_HEADERS) !== 0) {
                  $this->expected = 0;
                  $state = $this->resolve($Package, $stream, $this->fragments);
                  $this->fragments = '';
                  if ($state !== null) {
                     if ($state === States::Complete) {
                        $Package->consumed = $offset - $carried;
                     }
                     return $state;
                  }
               }
               break;

            case HTTP2::FRAME_SETTINGS:
               if ($stream !== 0) {
                  return $this->fail($Package, Errors::Protocol, 'SETTINGS on a stream');
               }
               // ?: Peer acknowledged our settings
               if (($flags & HTTP2::FLAG_ACK) !== 0) {
                  if ($payload !== 0) {
                     return $this->fail($Package, Errors::FrameSize, 'SETTINGS ACK with payload');
                  }
                  break;
               }

               // @ Apply, adjusting every open send window by the delta
               $this->settled = true;
               $window = $this->Remote->window;
               $error = $this->Remote->parse($data);
               if ($error !== null) {
                  return $this->fail($Package, $error, 'invalid SETTINGS');
               }
               $delta = $this->Remote->window - $window;
               if ($delta !== 0) {
                  foreach ($this->Streams as $Stream) {
                     $Stream->window += $delta;
                  }
               }

               $this->outbox .= Frame::pack(HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK, 0);

               // @ Grown windows may unblock parked response tails
               if ($delta > 0) {
                  $this->pump($Package);
               }
               break;

            case HTTP2::FRAME_PING:
               if ($stream !== 0) {
                  return $this->fail($Package, Errors::Protocol, 'PING on a stream');
               }
               if ($payload !== 8) {
                  return $this->fail($Package, Errors::FrameSize, 'PING payload must be 8 octets');
               }
               // ?: Answer non-ACK pings with the same opaque payload
               if (($flags & HTTP2::FLAG_ACK) === 0) {
                  $this->outbox .= Frame::pack(HTTP2::FRAME_PING, HTTP2::FLAG_ACK, 0, $data);
               }
               break;

            case HTTP2::FRAME_WINDOW_UPDATE:
               if ($payload !== 4) {
                  return $this->fail($Package, Errors::FrameSize, 'WINDOW_UPDATE payload must be 4 octets');
               }
               /** @var array{1: int} $update */
               $update = unpack('N', $data);
               $increment = $update[1] & 0x7fffffff;
               // ? Zero increment is a protocol error (RFC 9113 §6.9)
               if ($increment === 0) {
                  if ($stream === 0) {
                     return $this->fail($Package, Errors::Protocol, 'zero WINDOW_UPDATE');
                  }
                  $this->reset($stream, Errors::Protocol);
                  break;
               }

               if ($stream === 0) {
                  $this->window += $increment;
                  // ? Window overflow (RFC 9113 §6.9.1)
                  if ($this->window > 2147483647) {
                     return $this->fail($Package, Errors::FlowControl, 'connection window overflow');
                  }
                  $this->pump($Package);
                  break;
               }

               // ? WINDOW_UPDATE on an idle stream is a connection error
               //   (RFC 9113 §5.1); on a closed one it is ignored (§6.9).
               if ($stream > $this->last) {
                  return $this->fail($Package, Errors::Protocol, 'WINDOW_UPDATE on idle stream');
               }

               $Stream = $this->Streams[$stream] ?? null;
               if ($Stream !== null) {
                  $Stream->window += $increment;
                  if ($Stream->window > 2147483647) {
                     $this->reset($stream, Errors::FlowControl);
                  }
                  // ? Parked tails live in `backlog` (raw) OR `chunks` (file
                  //   segments) — clients replenishing only the stream window
                  //   (connection window pre-expanded) must resume both.
                  else if ($Stream->backlog !== '' || $Stream->chunk < count($Stream->chunks)) {
                     $this->pump($Package);
                  }
               }
               break;

            case HTTP2::FRAME_RST_STREAM:
               if ($stream === 0) {
                  return $this->fail($Package, Errors::Protocol, 'RST_STREAM on stream 0');
               }
               if ($payload !== 4) {
                  return $this->fail($Package, Errors::FrameSize, 'RST_STREAM payload must be 4 octets');
               }
               // ? RST on an idle (never opened) stream
               if ($stream > $this->last) {
                  return $this->fail($Package, Errors::Protocol, 'RST_STREAM on idle stream');
               }

               // @ Rapid-reset mitigation (CVE-2023-44487): count client
               //   aborts of unanswered streams inside a 10s window.
               $Stream = $this->Streams[$stream] ?? null;
               if ($Stream !== null) {
                  $Stream->close();
                  unset($this->Streams[$stream]);
                  $this->opened--;

                  if ($Stream->responded === false && $this->count($Package) !== null) {
                     return States::Rejected;
                  }
               }
               break;

            case HTTP2::FRAME_PRIORITY:
               if ($stream === 0) {
                  return $this->fail($Package, Errors::Protocol, 'PRIORITY on stream 0');
               }
               // ? Length 5 is mandatory; the tree itself is deprecated — ignore
               if ($payload !== 5) {
                  $this->reset($stream, Errors::FrameSize);
                  break;
               }
               // ? A stream cannot depend on itself (RFC 9113 §5.3.1)
               /** @var array{1: int} $dependency */
               $dependency = unpack('N', $data);
               if (($dependency[1] & 0x7fffffff) === $stream) {
                  if ($stream > $this->last) {
                     $this->last = $stream;
                  }
                  $this->reset($stream, Errors::Protocol);
               }
               break;

            case HTTP2::FRAME_PUSH_PROMISE:
               // ? Clients cannot promise streams (RFC 9113 §8.4)
               return $this->fail($Package, Errors::Protocol, 'PUSH_PROMISE from client');

            case HTTP2::FRAME_GOAWAY:
               if ($stream !== 0) {
                  return $this->fail($Package, Errors::Protocol, 'GOAWAY on a stream');
               }
               // ? Last-Stream-ID + Error Code are mandatory (RFC 9113 §6.8)
               if ($payload < 8) {
                  return $this->fail($Package, Errors::FrameSize, 'GOAWAY payload must be >= 8 octets');
               }
               $this->closing = true;
               // ?: Nothing in flight — close now
               if ($this->opened === 0) {
                  $Package->reject($this->outbox);
                  $this->outbox = '';
                  return States::Rejected;
               }
               break;

            default:
               // @ Unknown frame types must be ignored (RFC 9113 §4.1)
         }
      }

      // @ Stash any partial frame for the next read
      if ($offset < $length) {
         $this->buffer = substr($work, $offset);
      }

      // @ Nothing dispatched this pass — push pending control frames out
      $this->flush($Package);

      $Package->consumed = $size;
      return States::Incomplete;
   }

   /**
    * Absorb un-dispatched remainder bytes when the read loop returns early
    * (deferred write). They are prepended to the next `decode()` pass.
    */
   public function feed (string $buffer): void
   {
      $this->buffer .= $buffer;
   }

   /**
    * Transport teardown (via `Packages::$decoded`): best-effort GOAWAY so
    * the peer learns which streams were processed, then release state.
    */
   public function disconnect (): void
   {
      // ? Already told the peer (connection error / GOAWAY drained)
      if ($this->closing === false && $this->Package !== null) {
         $Socket = $this->Package->Connection->Socket;

         if (is_resource($Socket)) {
            $goaway = Frame::pack(
               HTTP2::FRAME_GOAWAY, 0, 0, pack('NN', $this->last, Errors::None->value)
            );

            try {
               @fwrite($Socket, "{$this->outbox}{$goaway}");
            }
            catch (Throwable) {
               // ...
            }
         }
      }

      // @ Release connection state
      $this->closing = true;
      $this->outbox = '';
      $this->buffer = '';
      $this->Streams = [];
      $this->opened = 0;
   }

   // ---

   /**
    * Resolve a complete header block: decode HPACK, then open a stream or
    * validate trailers. Returns `null` to continue the frame loop, a final
    * `States` to stop (`Complete` = a request was dispatched).
    */
   protected function resolve (Packages $Package, int $stream, string $block): null|States
   {
      /** @var TCP_Packages $Package */
      $this->expected = 0;

      // @ HPACK decompression is connection state — failures are fatal
      //   (decode first, even for a doomed stream, to keep the dynamic
      //   table synchronized with the peer)
      $fields = $this->HPACK->decode($block, static::$list);
      if ($fields === null) {
         return $this->fail($Package, Errors::Compression, 'header block decode failed');
      }

      // ? Self-dependent priority on the HEADERS is a stream error (§5.3.1)
      if ($this->circular) {
         $this->circular = false;
         $this->last = $stream;
         return $this->reset($stream, Errors::Protocol);
      }

      // ?: Trailers — validated (no pseudo-headers) and discarded
      if ($this->trailing) {
         $this->trailing = false;
         foreach ($fields as [$name, $value]) {
            if ($name === '' || $name[0] === ':') {
               $this->reset($stream, Errors::Protocol);
               return null;
            }
         }

         $Stream = $this->Streams[$stream];
         $Stream->ended = true;
         if ($this->dispatch($Package, $Stream)) {
            return States::Complete;
         }
         return null;
      }

      // ? Concurrency cap — refuse the excess stream, keep the connection
      if ($this->opened >= static::$streams || $this->closing) {
         $this->last = $stream;
         $this->reset($stream, Errors::RefusedStream);
         if ($this->count($Package) !== null) {
            return States::Rejected;
         }
         return null;
      }

      $this->last = $stream;

      // @ Pseudo-header and field validation (RFC 9113 §8.3 / §8.2)
      $method = null;
      $scheme = null;
      $target = null;
      $authority = null;
      $regular = false;
      $map = [];
      $size = null;

      foreach ($fields as [$name, $value]) {
         if ($name === '') {
            return $this->reset($stream, Errors::Protocol);
         }

         // # Pseudo-headers: known set, no duplicates, none after regular fields
         if ($name[0] === ':') {
            if (strpbrk($value, self::PSEUDO_VALUE_CTLS) !== false) {
               return $this->reset($stream, Errors::Protocol);
            }
            if ($regular) {
               return $this->reset($stream, Errors::Protocol);
            }
            switch ($name) {
               case ':method':
                  if ($method !== null) {
                     return $this->reset($stream, Errors::Protocol);
                  }
                  $method = $value;
                  break;
               case ':scheme':
                  if ($scheme !== null) {
                     return $this->reset($stream, Errors::Protocol);
                  }
                  $scheme = $value;
                  break;
               case ':path':
                  if ($target !== null) {
                     return $this->reset($stream, Errors::Protocol);
                  }
                  $target = $value;
                  break;
               case ':authority':
                  if ($authority !== null) {
                     return $this->reset($stream, Errors::Protocol);
                  }
                  $authority = $value;
                  break;
               default:
                  return $this->reset($stream, Errors::Protocol);
            }
            continue;
         }

         $regular = true;

         // ? Field names must be lowercase HTTP tokens (RFC 9113 §8.2.1)
         if (strspn($name, self::FIELD_NAME) !== strlen($name)) {
            return $this->reset($stream, Errors::Protocol);
         }
         // ? Decoded values must not carry invalid CTLs into Header->raw
         if (strpbrk($value, self::FIELD_VALUE_CTLS) !== false) {
            return $this->reset($stream, Errors::Protocol);
         }
         // ? Connection-specific fields are forbidden (RFC 9113 §8.2.2)
         if (isSet(self::FORBIDDEN[$name])) {
            return $this->reset($stream, Errors::Protocol);
         }
         // ? `te` admits only "trailers" (RFC 9113 §8.2.2)
         if ($name === 'te' && $value !== 'trailers') {
            return $this->reset($stream, Errors::Protocol);
         }

         if ($name === 'content-length') {
            // ? Single, digit-only declaration
            if ($size !== null || ctype_digit($value) === false) {
               return $this->reset($stream, Errors::Protocol);
            }
            $size = (int) $value;
         }

         // @ Accumulate (duplicates become arrays — Header::get() joins them)
         if (isSet($map[$name])) {
            if (is_array($map[$name]) === false) {
               $map[$name] = [$map[$name]];
            }
            $map[$name][] = $value;
         }
         else {
            $map[$name] = $value;
         }
      }

      // ? Mandatory pseudo-headers (RFC 9113 §8.3.1)
      if ($method === null || $method === '' || $scheme === null || $scheme === '' || $target === null || $target === '') {
         return $this->reset($stream, Errors::Protocol);
      }
      // ? Transport scheme parity: h2c serves `http`, TLS-ALPN serves `https`.
      if ($scheme !== ($Package->Connection->encrypted ? 'https' : 'http')) {
         return $this->reset($stream, Errors::Protocol);
      }
      // ? Match HTTP/1.1 request-target cap before routing.
      if (strlen($target) > 8192) {
         return $this->deny($stream, 414);
      }
      // ? Method policy mirrors the hardened HTTP/1.1 parser: only framework-
      //   supported methods reach routing/middleware. Everything else is
      //   answered at the stream boundary so HTTP/2 cannot bypass HTTP/1.1
      //   method gates (TRACE, extension verbs, etc.).
      switch ($method) {
         case 'GET':
         case 'HEAD':
         case 'POST':
         case 'PUT':
         case 'PATCH':
         case 'DELETE':
         case 'OPTIONS':
            break;
         case 'TRACE':
         case 'CONNECT':
            return $this->deny($stream, 501);
         default:
            return $this->deny($stream, 405, [
               ['allow', 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS']
            ]);
      }

      // @ `:authority` is authoritative for the host (RFC 9113 §8.3.1)
      if ($authority !== null && $authority !== '') {
         $map['host'] = $authority;
      }
      else if (isSet($map['host']) && is_array($map['host']) === false) {
         $authority = $map['host'];
      }
      else {
         return $this->reset($stream, Errors::Protocol);
      }

      // ? Host allowlist (same policy as HTTP/1.1 decode)
      if (Request::$allowedHosts !== [] && Request::allow($authority) === false) {
         return $this->deny($stream, 400);
      }

      // ? Request body caps apply from the declared length on
      if ($size !== null && $size > Request::$maxBodySize) {
         return $this->deny($stream, 413);
      }

      // @ Open the stream
      $Stream = new Stream($stream, $this->Remote->window, $this->Local->window);
      $Stream->method = $method;
      $Stream->target = $target;
      $Stream->scheme = $scheme;
      $Stream->authority = $authority;
      $Stream->fields = $map;
      $Stream->length = $size;
      $this->Streams[$stream] = $Stream;
      $this->opened++;

      // ?: END_STREAM on HEADERS — bodyless request, dispatch now
      if ($this->ending) {
         $Stream->ended = true;
         if ($this->dispatch($Package, $Stream)) {
            return States::Complete;
         }
      }

      // :
      return null;
   }

   /**
    * Materialize a completed stream as the current Bootgly Request.
    *
    * @return bool `true` when the router must run (`States::Complete`).
    */
   protected function dispatch (Packages $Package, Stream $Stream): bool
   {
      /** @var TCP_Packages $Package */
      // ? Declared content-length must match the received body (RFC 9113 §8.1.1)
      if ($Stream->length !== null && $Stream->length !== strlen($Stream->body)) {
         $this->reset($Stream->id, Errors::Protocol);
         return false;
      }

      $fields = $Stream->fields;

      // @ Test-harness dispatch: mirror `Frame::parse()`'s header hook
      if (SAPI::$Environment === Environments::Test && isSet($fields['x-bootgly-test'])) {
         $value = $fields['x-bootgly-test'];
         SAPI::$testIndexHeader = (is_array($value) === false && ctype_digit($value)) ? (int) $value : null;
         unset($fields['x-bootgly-test']);
      }

      // @ Build the per-stream Request (h2 never pools Requests)
      $Request = new Request;
      $Request->adopt(
         Package: $Package,
         method: $Stream->method,
         URI: $Stream->target,
         fields: $fields,
         body: $Stream->body,
         stream: $Stream->id
      );

      Server::$Request = $Request;
      $this->current = $Stream->id;

      // :
      return true;
   }

   /**
    * Send a canned status response and close the stream (no router pass).
    *
    * @param array<int, array{0: string, 1: string}> $fields
    */
   protected function deny (int $stream, int $code, array $fields = []): null|States
   {
      array_unshift($fields, [':status', (string) $code]);

      $this->outbox .= Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         $stream,
         HPACK::encode($fields)
      );

      // @ RST only when the peer may still send on the stream — after our
      //   END_STREAM an already-ended stream is fully closed without it.
      $Stream = $this->Streams[$stream] ?? null;
      if ($Stream !== null) {
         $Stream->close();
         unset($this->Streams[$stream]);
         $this->opened--;

         if ($Stream->ended === false) {
            $this->outbox .= Frame::pack(
               HTTP2::FRAME_RST_STREAM, 0, $stream, pack('N', Errors::Cancel->value)
            );
         }
      }

      // :
      return null;
   }

   /**
    * Emit RST_STREAM and release the stream (stream error — connection lives).
    *
    * Returns `null` so a malformed-request check can `return $this->reset(...)`
    * straight out of `resolve()` and continue the frame loop.
    */
   protected function reset (int $stream, Errors $error): null
   {
      $this->outbox .= Frame::pack(
         HTTP2::FRAME_RST_STREAM, 0, $stream, pack('N', $error->value)
      );

      if (isSet($this->Streams[$stream])) {
         $this->Streams[$stream]->close();
         unset($this->Streams[$stream]);
         $this->opened--;
      }

      // :
      return null;
   }

   /**
    * Drain window-blocked response tails (`Stream::$backlog`) after
    * WINDOW_UPDATE / SETTINGS credit, releasing streams that finish.
    */
   protected function pump (Packages $Package): void
   {
      /** @var TCP_Packages $Package */
      // ?
      if ($this->window <= 0) {
         return;
      }

      // @@
      foreach ($this->Streams as $id => $Stream) {
         if (
            ($Stream->backlog === '' && $Stream->chunk >= count($Stream->chunks))
            || $Stream->window <= 0
         ) {
            continue;
         }

         [$frames, $done] = $this->drain($Stream, $id);
         $this->outbox .= $frames;

         if ($done) {
            $Stream->close();
            unset($this->Streams[$id]);
            $this->opened--;
         }

         // ?! `drain()` spends connection-window credit — PHPStan cannot see
         //   the `$this->window` mutation through the call.
         if ($this->window <= 0) { // @phpstan-ignore smallerOrEqual.alwaysFalse
            break;
         }
      }

      $this->flush($Package);
   }

   /**
    * Drain one stream's parked response tail (raw `backlog` + file/pad
    * `chunks`) into DATA frames, bounded by the connection + stream send
    * windows and the peer frame size. File segments are read lazily per
    * window credit — never materialized whole.
    *
    * Called by `pump()` (window credit arrived) and by
    * `Encoder_HTTP2::frame()` (first drain, DATA rides with HEADERS).
    *
    * @return array{0: string, 1: bool} Packed DATA frames + whether the tail finished.
    */
   public function drain (Stream $Stream, int $stream): array
   {
      // !
      $frames = '';
      $limit = $this->Remote->frame;

      // @@
      while ($this->window > 0 && $Stream->window > 0) {
         // # Raw body tail
         if ($Stream->backlog !== '') {
            $send = min($this->window, $Stream->window, strlen($Stream->backlog));
            $payload = substr($Stream->backlog, 0, $send);
            $Stream->backlog = substr($Stream->backlog, $send);
            $this->window -= $send;
            $Stream->window -= $send;

            $done = $Stream->backlog === '' && $Stream->chunk >= count($Stream->chunks);
            for ($offset = 0; $offset < $send; $offset += $limit) {
               $chunk = substr($payload, $offset, min($limit, $send - $offset));
               $frames .= Frame::pack(
                  HTTP2::FRAME_DATA,
                  ($done && $offset + $limit >= $send) ? HTTP2::FLAG_END_STREAM : 0,
                  $stream,
                  $chunk
               );
            }
            continue;
         }

         // ?: All segments sent
         $segment = $Stream->chunks[$Stream->chunk] ?? null;
         if ($segment === null) {
            break;
         }

         // # In-memory segment (multipart/range pads)
         if (is_string($segment['data'] ?? null)) {
            $data = $segment['data'];
            $position = is_int($segment['position'] ?? null) ? $segment['position'] : 0;
            $remaining = strlen($data) - $position;
            if ($remaining <= 0) {
               $Stream->chunk++;
               continue;
            }

            $send = min($this->window, $Stream->window, $remaining);
            $payload = substr($data, $position, $send);
            $position += $send;
            $Stream->chunks[$Stream->chunk]['position'] = $position;
            if ($position >= strlen($data)) {
               $Stream->chunk++;
            }

            $this->window -= $send;
            $Stream->window -= $send;
            $done = $Stream->chunk >= count($Stream->chunks);

            for ($offset = 0; $offset < $send; $offset += $limit) {
               $chunk = substr($payload, $offset, min($limit, $send - $offset));
               $frames .= Frame::pack(
                  HTTP2::FRAME_DATA,
                  ($done && $offset + $limit >= $send) ? HTTP2::FLAG_END_STREAM : 0,
                  $stream,
                  $chunk
               );
            }
            continue;
         }

         // # File segment
         $file = $segment['file'] ?? null;
         $length = $segment['length'] ?? null;
         if (is_string($file) === false || is_int($length) === false) {
            $Stream->chunk++;
            continue;
         }

         $position = is_int($segment['position'] ?? null) ? $segment['position'] : 0;
         $remaining = $length - $position;
         if ($remaining <= 0) {
            $Handler = $segment['handler'] ?? null;
            if (is_resource($Handler)) {
               @fclose($Handler);
            }
            $Stream->chunk++;
            continue;
         }

         // ?! Lazy open on first credit; reopen is impossible mid-segment
         //   (the handler stays parked in the segment between drains)
         $Handler = $segment['handler'] ?? null;
         if (is_resource($Handler) === false) {
            $Handler = @fopen($file, 'r');
            if ($Handler === false) {
               $Stream->close();
               $frames .= Frame::pack(
                  HTTP2::FRAME_RST_STREAM, 0, $stream, pack('N', Errors::Internal->value)
               );
               return [$frames, true];
            }
            $base = is_int($segment['offset'] ?? null) ? $segment['offset'] : 0;
            if (@fseek($Handler, $base + $position) !== 0) {
               @fclose($Handler);
               $Stream->close();
               $frames .= Frame::pack(
                  HTTP2::FRAME_RST_STREAM, 0, $stream, pack('N', Errors::Internal->value)
               );
               return [$frames, true];
            }
            $Stream->chunks[$Stream->chunk]['handler'] = $Handler;
         }

         $send = min($this->window, $Stream->window, $remaining);
         $sent = 0;
         while ($sent < $send) {
            /** @var int<1, max> $bytes `$sent < $send` bounds the difference */
            $bytes = min($limit, $send - $sent);
            try {
               $payload = @fread($Handler, $bytes);
            }
            catch (Throwable) {
               $payload = false;
            }
            if ($payload === false || $payload === '') {
               $Stream->close();
               $frames .= Frame::pack(
                  HTTP2::FRAME_RST_STREAM, 0, $stream, pack('N', Errors::Internal->value)
               );
               return [$frames, true];
            }

            $read = strlen($payload);
            $sent += $read;
            $position += $read;
            $this->window -= $read;
            $Stream->window -= $read;
            $Stream->chunks[$Stream->chunk]['position'] = $position;

            if ($position >= $length) {
               @fclose($Handler);
               unset($Stream->chunks[$Stream->chunk]['handler']);
               $Stream->chunk++;
            }

            $done = $Stream->chunk >= count($Stream->chunks);
            $frames .= Frame::pack(
               HTTP2::FRAME_DATA,
               $done ? HTTP2::FLAG_END_STREAM : 0,
               $stream,
               $payload
            );
         }
      }

      // :
      return [
         $frames,
         $Stream->backlog === '' && $Stream->chunk >= count($Stream->chunks)
      ];
   }

   /**
    * Count one reset toward the rapid-reset budget; exceed → GOAWAY.
    */
   protected function count (Packages $Package): null|States
   {
      /** @var TCP_Packages $Package */
      $now = time();
      if ($now - $this->since > 10) {
         $this->since = $now;
         $this->churn = 0;
      }

      // ?:
      if (++$this->churn > static::$resets) {
         return $this->fail($Package, Errors::EnhanceYourCalm, 'stream churn');
      }

      // :
      return null;
   }

   /**
    * Connection error: emit pending frames + GOAWAY, close the transport.
    */
   protected function fail (Packages $Package, Errors $error, string $debug = ''): States
   {
      /** @var TCP_Packages $Package */
      $goaway = Frame::pack(
         HTTP2::FRAME_GOAWAY, 0, 0, pack('NN', $this->last, $error->value) . $debug
      );

      $this->closing = true;
      $raw = "{$this->outbox}{$goaway}";
      $this->outbox = '';
      $this->Streams = [];
      $this->opened = 0;

      // @ reject() writes the raw bytes, closes the socket and flags the
      //   Package as rejected for the read loop.
      $Package->reject($raw);

      // :
      return States::Rejected;
   }

   /**
    * Push pending control frames through the backpressure-aware writer.
    */
   protected function flush (Packages $Package): void
   {
      /** @var TCP_Packages $Package */
      // ?
      if ($this->outbox === '') {
         return;
      }

      $raw = $this->outbox;
      $this->outbox = '';
      $Package->writing($Package->Connection->Socket, buffer: $raw);
   }
}
