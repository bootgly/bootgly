<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const PHP_EOL;
use const SEEK_SET;
use function array_key_exists;
use function array_key_first;
use function count;
use function dirname;
use function disk_free_space;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function fwrite;
use function get_resource_type;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function max;
use function microtime;
use function min;
use function strlen;
use function substr;
use Throwable;

use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Feeding;
use Bootgly\WPI\Endpoints\Servers\Packages as Server_Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


abstract class Packages extends Server_Packages implements WPI\Connections\Packages
{
   // ? Lazy: constructed on first read only. The transport hot path logs
   //   nothing, so an eager per-connection Logger graph (Logger + Handlers +
   //   Processors + Stream) is pure allocation churn under connection churn.
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


   // * Metadata
   // # Stream
   /** @var array<int, array<string, mixed>> */
   public array $downloading;
   /** @var array<int, array<string, mixed>> */
   public array $uploading;
   // # Connection management
   public bool $closeAfterWrite;
   // # Backpressure (Recommendation #3 — async write state machine)
   //   Bytes that fwrite() could not push to the socket; held until the
   //   event loop signals EVENT_WRITE readiness. Empty string means no
   //   deferred write is in flight.
   public string $pendingBuffer = '';
   //   Drain cursor inside `$pendingBuffer`. Always 0 after `reset()`.
   public int $pendingOffset = 0;
   //   Absolute UNIX timestamp (microtime) past which the deferred write
   //   is considered stalled and the connection is force-closed.
   public float $pendingDeadline = 0.0;
   //   Deferred close-after-write intent. Set when `closeAfterWrite` was
   //   true at the moment a write deferred; the actual close happens once
   //   `$pendingBuffer` fully drains.
   public bool $closeAfterDrain = false;
   //   Whether this Package has already requested EVENT_WRITE notification
   //   from the event loop. Idempotent on `Select::add`, but tracking it
   //   lets us issue a matching `del` on full drain.
   public bool $writeRegistered = false;
   //   Streamed-response files associated with the response head that has
   //   not entered writing() yet. Raw::encode() sets this flag together with
   //   `$uploading`, so the first head is not mistaken for a response queued
   //   behind its own file body.
   public bool $uploadAwaiting = false;
   //   File descriptors belonging to a later streamed response. They are
   //   claimed by the next writing() call and travel with that response's
   //   buffered head in `$pendingResponses`.
   /** @var array<int, array<string, mixed>> */
   public array $stagedUploading = [];
   //   Response buffers received while an earlier streamed body still owns
   //   the wire. Each entry keeps its own upload descriptors so file→file
   //   pipelining preserves head/body ordering.
   /** @var array<int, array{buffer:string, uploads:array<int, array<string, mixed>>}> */
   public array $pendingResponses = [];
   //   Cursor retained when the public upload() helper is used directly
   //   instead of through the managed `$uploading` response queue.
   /** @var resource|null */
   protected $directUploadHandler = null;
   protected int $directUploadRate = 0;
   protected int $directUploadRemaining = 0;
   //   Internal failure signal for uploading(), whose public return contract
   //   remains the number of bytes accepted by the socket.
   protected bool $outputFailed = false;
   protected bool $uploadClosing = false;
   protected bool $uploadCloseRequested = false;
   // # Receive carry (per-connection request reassembly)
   //   Unconsumed inbound bytes retained at the end of a receive event —
   //   an incomplete request head or frame fragment. The next event
   //   prepends them to the fresh read before decoding (`$carried` on the
   //   base Packages flags such reassembled events). Empty string means
   //   nothing is carried. Raw TCP (no registered decoder) never retains.
   public protected(set) string $carry = '';


   public Connection $Connection;


   public function __construct (Connection &$Connection)
   {
      $this->Connection = $Connection;

      parent::__construct();

      // * Metadata
      // # Stream
      $this->downloading = [];
      $this->uploading = [];
      $this->stagedUploading = [];
      $this->pendingResponses = [];
      $this->uploadAwaiting = false;
      // # Connection management
      $this->closeAfterWrite = false;
   }

   /**
    * Fail to read/write data
    *
    * @param resource $Socket 
    * @param string $operation 
    *
    * @return bool 
    */
   public function fail ($Socket, string $operation): bool
   {
      try {
         $EOF = @feof($Socket);
      }
      catch (Throwable) {
         $EOF = false;
      }

      // @ Check connection reset (End-of-File)?
      if ($EOF) {
         #$this->Logger->log(debug: 'Failed to ' . $operation . ' package: End-of-File!' . PHP_EOL);
         Connections::$errors[$operation]++;
         $this->Connection->close();
         return true;
      }

      if (is_resource($Socket) === false || get_resource_type($Socket) !== 'stream') {
         #$this->Logger->log(debug: 'Failed to ' . $operation . ' package: closing connection...' . PHP_EOL);
         Connections::$errors[$operation]++;
         $this->Connection->close();
         return true;
      }

      return false;
   }

   /**
    * Read data from the client
    *
    * @param resource $Socket 
    * @param null|int<1, max> $length
    * @param null|int<0, max> $timeout
    *
    * @return bool 
    */
   public function reading (
      &$Socket, null|int $length = null, null|int $timeout = null
   ): bool
   {
      // ! TLS is advanced only from reactor readiness. The accepted socket
      //   remains nonblocking, so a silent or fragmented ClientHello never
      //   monopolizes the worker. One write-ready retry lets OpenSSL flush its
      //   server flight; writing() removes that watcher before retrying so a
      //   peer that still owes input cannot create a writable-socket spin.
      if ($this->Connection->handshaking) {
         $negotiation = $this->Connection->handshake();

         if ($negotiation === 0) {
            if (
               Server::$Event->add(
                  $Socket,
                  Server::$Event::EVENT_WRITE,
                  $this
               ) === false
            ) {
               $this->Connection->close();
               return false;
            }

            // ! Track the TLS retry watcher under the SAME flag as ordinary
            //   backpressure (audit M5). Registering it untracked meant only
            //   `writing()` could remove it, and only while `handshaking` was
            //   still true — so a handshake completed by THIS read dispatch
            //   left a level-triggered writable socket registered forever, and
            //   `reset()` skipped it because the flag was false.
            $this->writeRegistered = true;
         }
         else {
            // @ Negotiation settled (succeeded or failed): drop the one-shot
            //   watcher here, so completion never depends on a later write
            //   dispatch observing `handshaking`.
            $this->reset($Socket);
         }

         return $negotiation !== false;
      }

      // !
      $input = '';
      $received = 0; // Bytes received from client
      $total = $length ?? 0; // Total length of packet = the expected length of packet or 0
      // * Metadata
      $started = 0;
      if ($length > 0 || $timeout > 0) {
         $started = microtime(true);
      }

      // @
      try {
         do {
            $buffer = @fread($Socket, $length ?? 65536); // @phpstan-ignore-line

            if ($buffer === false) break;
            if ($buffer === '') {
               if (! $timeout > 0 || microtime(true) - $started >= $timeout) {
                  $this->expired = true;
                  break;
               }

               continue; // TODO check EOF?
            }

            $input .= $buffer;

            $bytes = strlen($buffer);
            $received += $bytes;

            if ($length) {
               $length -= $bytes;
               continue;
            }

            break;
         }
         while ($received < $total || $total === 0);
      }
      catch (Throwable) {
         $buffer = false;
      }

      // @ Check connection close intention by peer?
      // Close connection if input data is empty to avoid unnecessary loop
      if ($buffer === '') {
         #$this->Logger->log(warning: 'Failed to read buffer: input data is empty!' . PHP_EOL);
         $this->Connection->close();
         return false;
      }

      // @ Check issues
      if ($buffer === false) {
         return $this->fail($Socket, 'read');
      }

      // @ Reassemble the receive carry: bytes a previous event retained
      //   (an incomplete request head or frame fragment) prepend the fresh
      //   read, so decoders always see one contiguous request stream. Raw
      //   TCP (no decoder) never retains — the common path pays one
      //   empty-string check.
      if ($this->carry === '') {
         if ($this->carried) {
            $this->carried = false;
         }
      }
      else {
         $input = "{$this->carry}{$input}";
         $this->carry = '';
         $this->carried = true;
      }

      // @ Set Stats (disable to max performance in benchmarks)
      if (Connections::$stats) {
         // Global
         Connections::$reads++;
         Connections::$read += $received;
         // Per client
         #Connections::$Connections[(int) $Socket]->reads++;
      }

      // @ Write data
      // ! Event input length for decoding/pipelining: the assembled length
      //   on reassembled events; the fresh read length otherwise (stats
      //   above keep counting only fresh bytes via `$received`).
      $inputLength = $this->carried ? strlen($input) : $received;
      // @ Decoder outcome:
      //   `States::Complete`   \u2192 byte count via `$this->consumed`, write response.
      //   `States::Incomplete` \u2192 wait for more bytes (no response).
      //   `States::Rejected`   \u2192 explicit rejection; socket already closed
      //                          by `reject()`. Replaces the previous
      //                          `!is_resource($Socket)` socket-state inference.
      //   When no decoder is registered (raw TCP), there is no framing layer:
      //   write whatever was fread and skip pipelining.
      $state = States::Complete;
      $this->rejected = false;
      // @ `$this->consumed` is always written by every `decode()` outcome
      //   (Complete/Incomplete/Rejected); no pre-reset needed.
      if (Server::$Decoder) { // @ Decode Application Data if exists
         $Decoder = $this->Decoder ?? Server::$Decoder;
         $state = $Decoder->decode($this, $input, $inputLength);
      }
      else {
         $Decoder = null;
         // @ Raw transport (no framing layer): the SAPI handler consumes
         //   `$this->input` by reference (`callbacks`), so retention and
         //   the repeat compare live HERE. Framed protocols decode the
         //   `$input` argument directly and never read the retained copy —
         //   the decoder owns its byte-keyed caches (per-connection L0 and
         //   the shared L1), so the transport no longer pays a per-event
         //   compare + store for them.
         $this->changed = ($this->input !== $input);
         if ($this->cache === false || $this->changed === true) {
            $this->input = $input;
         }
      }

      if ($state === States::Complete) {
         $this->write($Socket);

         // @ Recommendation #3: write deferred to event loop. A deferred
         //   write must NOT stop pipelining: the peer may have already sent
         //   every pipelined request in this read, so no future read event
         //   would ever revisit the tail — stopping silently drops it.
         //   Follow-up responses append to `pendingBuffer` in order via
         //   `writing()` resume mode, bounded by `$maxPendingBytes` + the
         //   stall deadline (the same semantics framed protocols — Feeding,
         //   HTTP/2 — always required to avoid deadlocking their peers).
         //   Only a close intent stops here, deferred to `closeAfterDrain`
         //   so the queued bytes drain first.
         if ($this->pendingBuffer !== '' && $this->closeAfterWrite) {
            $this->closeAfterDrain = true;
            $this->closeAfterWrite = false;
            return true;
         }

         // @ Close connection if signaled (Connection: close, HTTP/1.0, etc.)
         if ($this->closeAfterWrite) {
            $this->closeAfterWrite = false;
            $this->Connection->close();
            return true;
         }

         // @ Pipeline: process remaining requests in the same buffer
         $consumed = $this->consumed;
         if (Server::$Decoder && $consumed > 0 && $consumed < $inputLength) {
            $offset = $consumed;

            while ($offset < $inputLength) {
               $remaining = substr($input, $offset);
               $remainingLength = $inputLength - $offset;

               // ! Pipelined sub-request isolation is the decoder's own
               //   contract now (`reset()`/`assume()` per dispatch) — the
               //   transport no longer maintains `changed`/`input` here.
               $this->consumed = 0;
               $this->rejected = false;

               $Decoder = $this->Decoder ?? Server::$Decoder;
               $decoded = $Decoder->decode($this, $remaining, $remainingLength);

               if ($decoded !== States::Complete) {
                  if ($decoded === States::Incomplete) {
                     if (
                        $Decoder instanceof Feeding
                        && $this->consumed < $remainingLength
                     ) {
                        $Decoder->feed(substr($remaining, $this->consumed));
                        $this->consumed = $remainingLength;
                     }

                     // @ Retain the undecoded tail (from the event offset)
                     //   for the next event's reassembly \u2014 no-op when the
                     //   decoder consumed or absorbed everything. Rejected
                     //   decodes never retain: the connection is closing.
                     $this->retain($input, $offset + $this->consumed, $inputLength);
                  }
                  break; // Incomplete or rejected \u2014 stop pipelining
               }

               // ? Forward-progress guard: a Complete decode that consumed
               //   zero bytes can never advance the pipeline offset — the
               //   worker would relive the same bytes forever. No current
               //   decoder produces this; treat it as a decoder defect and
               //   close instead of livelocking (or silently dropping the
               //   remaining requests).
               //   PHPStan cannot infer that `decode()` mutates
               //   `$this->consumed` through the `$this` argument, so it
               //   narrows the property to the pre-decode `0` literal.
               if ($this->consumed <= 0) { // @phpstan-ignore smallerOrEqual.alwaysTrue
                  $this->Logger->log(
                     error: 'Pipelined decode completed without consuming bytes — closing connection.' . PHP_EOL
                  );
                  $this->Connection->close();
                  return true;
               }

               // ? `write()` propagates `writing() === false`: the deferred
               //   write layer closed the connection (pending-memory cap or
               //   stall deadline) — stop decoding for a dead connection.
               if ($this->write($Socket) === false) { // @phpstan-ignore deadCode.unreachable
                  return true;
               }

               // @ Pipeline write deferred — keep pipelining: follow-up
               //   responses append to `pendingBuffer` in order (see the
               //   top-level twin). Only a close intent stops the loop,
               //   deferred to `closeAfterDrain` so queued bytes drain first.
               if ($this->pendingBuffer !== '' && $this->closeAfterWrite) {
                  $this->closeAfterDrain = true;
                  $this->closeAfterWrite = false;
                  return true;
               }

               if ($this->closeAfterWrite) {
                  $this->closeAfterWrite = false;
                  $this->Connection->close();
                  return true;
               }

               $offset += $this->consumed;
            }
         }
      }
      else if ($state === States::Incomplete) {
         $Decoder = $this->Decoder ?? $Decoder;
         if ($Decoder instanceof Feeding && $this->consumed < $inputLength) {
            $Decoder->feed(substr($input, $this->consumed));
            $this->consumed = $inputLength;
         }

         // @ Retain the undecoded tail for the next event's reassembly —
         //   fixes fragmented request heads (previously dropped: the shared
         //   decoder had no per-connection carry). No-op when the decoder
         //   consumed or absorbed everything (stateful body decoders,
         //   Feeding protocols).
         $this->retain($input, $this->consumed, $inputLength);
      }
      // @ Consume test handler on reject (decoder rejected, encoder never ran)
      // ?! The explicit enum check is kept for readability — after the
      //   Complete/Incomplete branches only Rejected remains.
      else if ($state === States::Rejected && isset(SAPI::$Suite)) { // @phpstan-ignore identical.alwaysTrue
         // @ Index-based dispatch installs the handler per-request from
         //   the `X-Bootgly-Test` header \u2014 there is no FIFO slot to pop.
         //   We only shift for legacy/no-header priming requests, but
         //   never unconditionally: if the harness is active and the
         //   request was indexed (currentTestIndex set), skip.
         if (SAPI::$currentTestIndex === null) {
            $base = array_key_first(SAPI::$Tests);
            if ($base !== null) {
               SAPI::boot(reset: true, base: $base, key: 'response');
            }
         }
      }

      return true;
   }
   /**
    * Retain the unconsumed tail of the current receive event as the
    * connection's carry, reassembled in front of the next read.
    *
    * Single compaction per event: when nothing was consumed the whole
    * input string is adopted by refcount (zero copy); `substr()` runs only
    * for a true mid-buffer tail. Callers skip retention on close intents —
    * a closing connection never revisits its bytes.
    *
    * @param string $input The full (possibly reassembled) event input
    * @param int $offset Total bytes consumed from `$input` by this event
    * @param int $length Length of `$input`
    */
   protected function retain (string $input, int $offset, int $length): void
   {
      // ? Everything consumed — nothing to carry.
      if ($offset >= $length) {
         return;
      }

      // !
      $carry = ($offset === 0) ? $input : substr($input, $offset);

      // ? Memory cap: a peer that never completes a request cannot grow
      //   the carry unbounded (defense in depth — `Frame::parse` rejects
      //   oversized heads deterministically well below this).
      if (strlen($carry) > Server::$maxPendingBytes) {
         $this->carry = '';
         $this->Connection->close();
         return;
      }

      // :
      $this->carry = $carry;
   }
   // ---
   /**
    * Write data to the client
    *
    * @param resource $Socket 
    * @param int<0,max>|null $length 
    *
    * @return bool 
    */
   public function write (&$Socket, null|int $length = null): bool
   {
      // !
      $Encoder = $this->Encoder ?? Server::$Encoder;
      if ($Encoder) { // @ Encode Application Data if exists
         $buffer = $Encoder::encode($this, $length);
      }
      else {
         /** @var string $buffer */
         $buffer = (SAPI::$Handler)(...$this->callbacks);
      }

      // :
      return $this->writing($Socket, length: $length, buffer: $buffer);
   }
   /**
    * Write a response buffer to the client socket in loop
    *
    * @param resource $Socket 
    * @param int<0,max>|null $length 
    * @param string $buffer 
    *
    * @return bool 
    */
   public function writing (&$Socket, null|int $length = null, string $buffer = ''): bool
   {
      if ($this->Connection->handshaking) {
         // ! EVENT_WRITE is level-triggered for an ordinary TCP socket. Keep
         //   it strictly one-shot during negotiation; read readiness remains
         //   registered for the next fragmented handshake record.
         //   Cleared through reset() so the watcher and its tracking flag can
         //   never disagree (audit M5).
         $this->reset($Socket);

         return $this->Connection->handshake() !== false;
      }

      $available = strlen($buffer);

      // @ Fast lane — the ordered writer is fully idle and this is a plain
      //   whole-buffer response: one fwrite, inline stats, zero state-machine
      //   bookkeeping. An engaged output owner, a prefix `$length` or a zero
      //   write falls through to the ordered writer below unchanged.
      //   Skipping the stall-deadline check is sound only while an idle gate
      //   implies no deadline: `$pendingDeadline > 0` is installed exclusively
      //   by defer() together with a non-empty `$pendingBuffer`, and only
      //   reset() clears that pair — keep that coupling when editing either.
      if (
         $available !== 0
         && ($length === null || $length === $available)
         && $this->pendingBuffer === ''
         && $this->uploading === []
         && $this->pendingResponses === []
         && $this->stagedUploading === []
         && $this->directUploadRemaining === 0
         && ! $this->uploadAwaiting
         && ! $this->writeRegistered
      ) {
         try {
            $sent = @fwrite($Socket, $buffer, $available); // @phpstan-ignore-line
         }
         catch (Throwable) {
            $sent = false;
         }

         if ($sent === $available) {
            // ! Always-on: `expire()` activity signal (idle reaping).
            $this->Connection->writes++;
            if (Connections::$stats) {
               Connections::$writes++;
               Connections::$written += $sent;
            }

            if ($this->closeAfterDrain) {
               $this->closeAfterDrain = false;
               $this->Connection->close();
            }

            // :
            return true;
         }
         if ($sent === false) {
            return $this->abort($Socket);
         }
         if ($sent > 0) {
            // ? Short write: the unsent suffix moves to EVENT_WRITE ownership.
            if ($this->defer($Socket, substr($buffer, $sent)) === false) {
               return $this->abort($Socket);
            }

            $this->account($sent);
            return true;
         }
         // ? Zero write: the ordered writer gets its single retry below.
      }

      // @ `$length` is a public prefix contract used by direct transport
      //   callers. Preserve it explicitly, while rejecting impossible values
      //   instead of asking fwrite() to read past the supplied string.
      if ($length !== null) {
         if ($length > $available) {
            return $this->abort($Socket);
         }
         if ($length < $available) {
            $buffer = substr($buffer, 0, $length);
         }
      }

      // ! One ordered nonblocking writer owns every HTTP/1 response segment:
      //   the current in-memory slice, the unread tail of an active file, and
      //   later response heads. A later head can never enter the advertised
      //   body of the active file; it stays in `$pendingResponses` until the
      //   file cursor reaches the end of every part and padding phase.
      if (
         $this->pendingDeadline > 0.0
         && microtime(true) > $this->pendingDeadline
      ) {
         return $this->abort($Socket);
      }

      $this->outputFailed = false;
      $this->uploadClosing = false;

      // @ Raw::encode() installs the first file queue before returning its
      //   response head. That head owns the queue. Later streamed responses
      //   stage their files separately and are queued as one ordered job.
      $ownsUpload = $buffer !== '' && $this->uploadAwaiting;
      $uploads = [];
      if ($ownsUpload) {
         $this->uploadAwaiting = false;
      }
      else {
         $uploads = $this->stagedUploading;
         $this->stagedUploading = [];
      }

      if ($buffer !== '' && ! $ownsUpload) {
         $busy = $this->pendingBuffer !== ''
            || $this->uploading !== []
            || $this->directUploadRemaining > 0
            || $this->pendingResponses !== [];

         // Preserve the established normal-response pipeline contract:
         // without a file boundary, contiguous deferred responses share one
         // pendingBuffer. A file-associated response must remain a separate
         // job so its head precedes its own file bytes.
         if (
            $this->pendingBuffer !== ''
            && $this->uploading === []
            && $this->directUploadRemaining === 0
            && $this->pendingResponses === []
            && $uploads === []
         ) {
            if ($this->measure() + strlen($buffer) > Server::$maxPendingBytes) {
               return $this->abort($Socket);
            }

            $pending = $this->pendingOffset === 0
               ? $this->pendingBuffer
               : substr($this->pendingBuffer, $this->pendingOffset);
            $this->pendingBuffer = $pending . $buffer;
            $this->pendingOffset = 0;
            $buffer = '';
         }
         else if ($busy) {
            if ($this->queue($Socket, $buffer, $uploads) === false) {
               return false;
            }
            $buffer = '';
         }
         else if ($uploads !== []) {
            $this->uploading = $uploads;
            $this->uploadCloseRequested = false;
         }
      }

      // @ Resume the exact already-read slice first. Clear its owner before
      //   transmitting so a new stall can atomically install only the new
      //   unsent suffix.
      $resuming = $this->pendingBuffer !== '';
      if ($resuming) {
         $buffer = $this->pendingOffset === 0
            ? $this->pendingBuffer
            : substr($this->pendingBuffer, $this->pendingOffset);
         $this->pendingBuffer = '';
         $this->pendingOffset = 0;
      }

      $written = 0;

      while (true) {
         if ($buffer !== '') {
            $sent = 0;
            $complete = $this->transmit($Socket, $buffer, $sent);
            if ($complete === false) {
               $this->account($written + $sent);
               return $this->abort($Socket);
            }
            $written += $sent;
            $buffer = '';

            if ($complete === null) {
               $this->account($written);
               return true;
            }
         }

         // @ Direct upload() callers use the same disk-tail invariant as
         //   managed HTTP file responses. The pending slice drains first;
         //   only then may this retained handler produce its next chunk.
         if ($this->directUploadRemaining > 0) {
            if (
               ! is_resource($this->directUploadHandler)
               || $this->directUploadRate <= 0
            ) {
               $this->account($written);
               return $this->abort($Socket);
            }

            $Result = $this->pump(
               $Socket,
               $this->directUploadHandler,
               $this->directUploadRate,
               $this->directUploadRemaining
            );
            $read = $Result['read'];
            $written += $Result['written'];

            if (
               $Result['success'] === false
               || $read < 0
               || $read > $this->directUploadRemaining
            ) {
               $this->account($written);
               return $this->abort($Socket);
            }

            $this->directUploadRemaining -= $read;
            if ($Result['deferred']) {
               $this->account($written);
               return true;
            }
            if ($this->directUploadRemaining !== 0) {
               $this->account($written);
               return $this->abort($Socket);
            }

            $this->directUploadHandler = null;
            $this->directUploadRate = 0;
         }

         // @ A drained in-memory file slice is not a completed upload. Resume
         //   the retained descriptor/cursor before selecting any later job.
         if ($this->uploading !== []) {
            $written += $this->uploading($Socket);

            if ($this->outputFailed) { // @phpstan-ignore-line (set by uploading())
               $this->account($written);
               return false;
            }
            if ($this->pendingBuffer !== '') { // @phpstan-ignore-line (set by uploading())
               $this->account($written);
               return true;
            }
            if ($this->uploadClosing) { // @phpstan-ignore-line (set by uploading())
               break;
            }
         }

         if ($this->pendingResponses === []) {
            break;
         }

         $key = array_key_first($this->pendingResponses);
         $Response = $this->pendingResponses[$key];
         unset($this->pendingResponses[$key]);

         $buffer = $Response['buffer'];
         $this->uploading = $Response['uploads'];
         $this->uploadCloseRequested = false;
      }

      // @ Only the terminal drain drops EVENT_WRITE/deadline. File cursor and
      //   response jobs are both empty here (or close=true discarded them).
      $this->reset($Socket);
      $this->account($written);

      if ($this->closeAfterDrain) {
         $this->closeAfterDrain = false;
         $this->uploadClosing = false;
         $this->Connection->close();
      }

      return true;
   }
   /**
    * Transmit one owned in-memory slice.
    *
    * A zero or short write transfers ownership of the unsent suffix to
    * pendingBuffer and returns successfully; false means an unrecoverable
    * transport/event-registration failure.
    *
    * @param resource $Socket
    */
   protected function transmit (&$Socket, string $buffer, int &$written): null|bool
   {
      $written = 0;
      $length = strlen($buffer);
      if ($length === 0) {
         return true;
      }

      try {
         $sent = @fwrite($Socket, $buffer, $length); // @phpstan-ignore-line
      }
      catch (Throwable) {
         $sent = false;
      }

      if ($sent === false) {
         return false;
      }

      $written = $sent;
      if ($sent < $length) {
         if ($this->defer($Socket, substr($buffer, $sent)) === false) {
            return false;
         }

         return null;
      }

      return true;
   }
   /**
    * Transfer an unsent in-memory suffix to EVENT_WRITE ownership.
    *
    * @param resource $Socket
    */
   protected function defer (&$Socket, string $buffer): bool
   {
      if ($buffer === '') {
         return true;
      }

      $pending = $this->pendingBuffer === ''
         ? ''
         : (
            $this->pendingOffset === 0
               ? $this->pendingBuffer
               : substr($this->pendingBuffer, $this->pendingOffset)
         );
      $buffer = $pending . $buffer;

      if ($this->measure() - strlen($pending) + strlen($buffer) > Server::$maxPendingBytes) {
         return false;
      }

      $this->pendingBuffer = $buffer;
      $this->pendingOffset = 0;
      if ($this->pendingDeadline === 0.0) {
         $this->pendingDeadline = microtime(true) + (float) Server::$maxWriteWallTime;
      }

      if (! $this->writeRegistered && isset(Server::$Event)) {
         try {
            $registered = Server::$Event->add(
               $Socket,
               Server::$Event::EVENT_WRITE,
               $this
            );
         }
         catch (Throwable) {
            $registered = false;
         }

         if ($registered === false) {
            return false;
         }
         $this->writeRegistered = true;
      }

      return true;
   }
   /**
    * Queue a later response and its file descriptors behind the current body.
    *
    * @param resource $Socket
    * @param array<int, array<string, mixed>> $uploads
    */
   protected function queue (&$Socket, string $buffer, array $uploads): bool
   {
      if ($this->measure() + strlen($buffer) > Server::$maxPendingBytes) {
         $this->abort($Socket);
         return false;
      }

      $this->pendingResponses[] = [
         'buffer' => $buffer,
         'uploads' => $uploads,
      ];

      return true;
   }
   /**
    * Measure all resident output bytes governed by maxPendingBytes.
    */
   protected function measure (): int
   {
      $bytes = max(
         0,
         strlen($this->pendingBuffer) - $this->pendingOffset,
      );

      foreach ($this->pendingResponses as $Response) {
         $bytes += strlen($Response['buffer']);
      }

      return $bytes;
   }
   /**
    * Account one successful write batch.
    */
   protected function account (int $written): void
   {
      if ($written <= 0) {
         return;
      }

      // ! Always-on: this is the expire() activity signal.
      $this->Connection->writes++;
      if (Connections::$stats) {
         Connections::$writes++;
         Connections::$written += $written;
      }
   }
   /**
    * Fail closed and release every output owner.
    *
    * @param resource $Socket
    */
   protected function abort (&$Socket): false
   {
      $this->release();
      $this->uploading = [];
      $this->stagedUploading = [];
      $this->pendingResponses = [];
      $this->uploadAwaiting = false;
      $this->uploadClosing = false;
      $this->uploadCloseRequested = false;
      $this->closeAfterDrain = false;
      $this->outputFailed = true;
      $this->reset($Socket);
      $this->Connection->close();

      return false;
   }
   /**
    * Release direct-upload cursor ownership, if any.
    */
   protected function release (): void
   {
      // upload() does not own its caller-supplied handler. Dropping this
      // reference lets the caller's lifecycle decide when to close it while
      // ensuring a closed Connection cannot pin it until cycle collection.
      $this->directUploadHandler = null;
      $this->directUploadRate = 0;
      $this->directUploadRemaining = 0;
   }
   /**
    * Reset deferred-write state and drop any EVENT_WRITE registration.
    *
    * @param resource $Socket
    */
   protected function reset (&$Socket): void
   {
      $this->pendingBuffer = '';
      $this->pendingOffset = 0;
      $this->pendingDeadline = 0.0;

      if ($this->writeRegistered) {
         if (isset(Server::$Event)) {
            try {
               Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);
            }
            catch (Throwable) {}
         }
         $this->writeRegistered = false;
      }
   }
   public function read (&$Socket): void
   {
      // N/A
   }

   // ! Stream
   /**
    * Download file from the client
    *
    * @param resource $Socket 
    *
    * @return int|false
    * @throws Throwable
    */
   public function downloading ($Socket): int|false
   {
      $queued = $this->downloading[0] ?? null;
      if (! is_array($queued)) {
         return false;
      }

      if (! array_key_exists('file', $queued) || ! is_string($queued['file']) || $queued['file'] === '') {
         return false;
      }
      $file = $queued['file'];

      $Handler = @fopen($file, 'w+');
      if ($Handler === false) {
         return false;
      }

      if (! array_key_exists('length', $queued) || ! is_int($queued['length'])) {
         @fclose($Handler);
         return false;
      }
      $length = $queued['length'];

      $close = array_key_exists('close', $queued) ? (bool) $queued['close'] : false;

      $read = 0; // int Socket read in bytes

      // @ Check free space in dir of file
      try {
         if (disk_free_space(dirname($file)) < $length) {
            return false;
         }
      }
      catch (Throwable) {
         return false;
      }

      // @ Set over / rate
      $over = 0;
      $rate = 1 * 1024 * 1024; // 1 MB (1048576) = Max rate to read/send data file by loop

      if ($length > 0 && $length < $rate) {
         $rate = $length;
      }
      else if ($length > $rate) {
         $over = $length % $rate;
      }

      // @ Download File
      if ($over > 0) {
         $read += $this->download($Socket, $Handler, $over, $over);
         $length -= $over;
      }

      $read += $this->download($Socket, $Handler, $rate, $length);
   
      // @ Try to close the file Handler
      try {
         @fclose($Handler);
      }
      catch (Throwable) {}

      // @ Unset current downloading
      unSet($this->downloading[0]);

      // @ Try to close the Socket if requested
      if ($close) {
         try {
            $this->Connection->close();
         }
         catch (Throwable) {}
      }
   
      return $read;
   }
   /**
    * Upload file to the client
    *
    * @param resource $Socket 
    *
    * @return int
    * @throws Throwable
    */
   public function uploading ($Socket): int
   {
      $written = 0;

      while ($this->uploading !== []) {
         $key = array_key_first($this->uploading);
         $queued = &$this->uploading[$key];
         if (! is_array($queued)) { // @phpstan-ignore-line (public queue may be corrupted at runtime)
            $this->abort($Socket);
            return $written;
         }
         if (
            ! array_key_exists('file', $queued)
            || ! is_string($queued['file'])
            || $queued['file'] === ''
         ) {
            $this->abort($Socket);
            return $written;
         }
         $file = $queued['file'];

         $partsValue = $queued['parts'] ?? null;
         if (! is_array($partsValue)) {
            $this->abort($Socket);
            return $written;
         }
         $queued['parts'] = $partsValue;

         $padsValue = $queued['pads'] ?? [];
         if (! is_array($padsValue)) {
            $this->abort($Socket);
            return $written;
         }
         $queued['pads'] = $padsValue;

         $parts = &$queued['parts'];
         $pads = &$queued['pads'];

         while ($parts !== []) {
            $partKey = array_key_first($parts);
            if (! is_array($parts[$partKey])) {
               $this->abort($Socket);
               return $written;
            }

            $part = &$parts[$partKey];
            $pad = $pads[$partKey] ?? [];
            if (! is_array($pad)) {
               $this->abort($Socket);
               return $written;
            }

            // @ Multipart prepend. Once transmit() accepts or defers the
            //   suffix, pendingBuffer owns every remaining padding byte.
            $prependValue = $pad['prepend'] ?? null;
            $prepend = is_string($prependValue) ? $prependValue : '';
            if ($prepend !== '') {
               $sent = 0;
               $complete = $this->transmit($Socket, $prepend, $sent);
               $written += $sent;
               $pad['prepend'] = '';
               $pads[$partKey] = $pad;

               if ($complete === false) {
                  $this->abort($Socket);
                  return $written;
               }
               if ($complete === null) {
                  return $written;
               }
            }

            $offsetValue = $part['offset'] ?? null;
            $lengthValue = $part['length'] ?? null;
            if (
               ! is_int($offsetValue)
               || ! is_int($lengthValue)
               || $offsetValue < 0
               || $lengthValue < 0
            ) {
               $this->abort($Socket);
               return $written;
            }

            // @ File phase. pump() reports bytes read separately from bytes
            //   accepted by the socket. The cursor advances by all bytes read:
            //   an unsent suffix is already owned by pendingBuffer and must
            //   never be read from disk a second time.
            if ($lengthValue > 0) {
               try {
                  $Handler = @fopen($file, 'rb');
               }
               catch (Throwable) {
                  $Handler = false;
               }

               if ($Handler === false) {
                  $this->abort($Socket);
                  return $written;
               }

               try {
                  try {
                     $stat = @fstat($Handler);
                  }
                  catch (Throwable) {
                     $stat = false;
                  }

                  if ($stat === false) {
                     $this->abort($Socket);
                     return $written;
                  }

                  $Identity = [
                     'dev' => $stat['dev'],
                     'ino' => $stat['ino'],
                     'size' => $stat['size'],
                     'mtime' => $stat['mtime'],
                     'ctime' => $stat['ctime'],
                  ];

                  $known = $queued['_identity'] ?? null;
                  if ($known === null) {
                     $queued['_identity'] = $Identity;
                  }
                  else if (! is_array($known) || $known !== $Identity) {
                     // The path now names a different or modified file. Close
                     // rather than mix two representations under one length.
                     $this->abort($Socket);
                     return $written;
                  }

                  try {
                     $seeked = @fseek($Handler, $offsetValue, SEEK_SET);
                  }
                  catch (Throwable) {
                     $seeked = -1;
                  }

                  if ($seeked !== 0) {
                     $this->abort($Socket);
                     return $written;
                  }

                  $rate = min(1024 * 1024, $lengthValue);
                  $Result = $this->pump(
                     $Socket,
                     $Handler,
                     $rate,
                     $lengthValue
                  );
               }
               finally {
                  try {
                     @fclose($Handler);
                  }
                  catch (Throwable) {}
               }

               $read = $Result['read'];
               $written += $Result['written'];

               if ($read < 0 || $read > $lengthValue) {
                  $this->abort($Socket);
                  return $written;
               }

               $part['offset'] = $offsetValue + $read;
               $part['length'] = $lengthValue - $read;

               if ($Result['success'] === false) {
                  $this->abort($Socket);
                  return $written;
               }
               if ($Result['deferred']) {
                  return $written;
               }
               if ($part['length'] !== 0) {
                  // A successful, nondeferred pump owns the requested range.
                  // Anything less is a premature EOF and ambiguous framing.
                  $this->abort($Socket);
                  return $written;
               }
            }

            // @ Multipart append follows the exact same ownership transfer.
            $pad = $pads[$partKey] ?? [];
            if (! is_array($pad)) {
               $this->abort($Socket);
               return $written;
            }
            $appendValue = $pad['append'] ?? null;
            $append = is_string($appendValue) ? $appendValue : '';
            if ($append !== '') {
               $sent = 0;
               $complete = $this->transmit($Socket, $append, $sent);
               $written += $sent;
               $pad['append'] = '';
               $pads[$partKey] = $pad;

               if ($complete === false) {
                  $this->abort($Socket);
                  return $written;
               }
               if ($complete === null) {
                  return $written;
               }
            }

            unset($parts[$partKey]);
            unset($pads[$partKey]);
            unset($part);
         }

         $close = array_key_exists('close', $queued)
            ? (bool) $queued['close']
            : false;
         $this->uploadCloseRequested = $this->uploadCloseRequested || $close;

         unset($this->uploading[$key]);
         unset($queued);
      }

      if ($this->uploadCloseRequested) {
         // close=true belongs to the complete streamed representation, not
         // the first blocked slice or first file record.
         $this->uploadCloseRequested = false;
         $this->uploading = [];
         $this->stagedUploading = [];
         $this->pendingResponses = [];
         $this->uploadAwaiting = false;
         $this->closeAfterDrain = true;
         $this->uploadClosing = true;
      }

      return $written;
   }
   // ---
   /**
    * Download data from the client
    *
    * @param resource $Socket 
    * @param resource $Handler 
    * @param int $rate 
    * @param int $length 
    *
    * @return int 
    */
   public function download (&$Socket, &$Handler, int $rate, int $length): int
   {
      $read = 0;
      $stored = 0;

      while ($stored < $length) {
         // ! Socket
         // @ Read buffer from Client
         try {
            $buffer = @fread($Socket, $rate); // @phpstan-ignore-line
         }
         catch (Throwable) {
            break;
         }

         if ($buffer === false) break;

         $read += strlen($buffer);

         // @ Write part of data (if exists) using Handler
         while ($read) {
            // ! File
            try {
               $written = @fwrite($Handler, $buffer, $read); // @phpstan-ignore-line
            }
            catch (Throwable) {
               break;
            }

            if ($written === false) break;
            if ($written === 0) continue;

            $stored += $written;

            if ($written < $read) {
               $buffer = substr($buffer, $written);
               $read -= $written;
               continue;
            }

            break;
         }

         // @ Check Socket EOF (End-Of-File)
         try {
            $end = @feof($Socket);
         }
         catch (Throwable) {
            break;
         }

         if ($end) break;
      }

      return $stored;
   }
   /**
    * Upload data to the client.
    *
    * @param resource $Socket 
    * @param resource $Handler 
    * @param int<1,max> $rate 
    * @param int $length 
    *
    * @return int 
    */
   public function upload (&$Socket, &$Handler, int $rate, int $length): int
   {
      if ($this->directUploadRemaining > 0) {
         $this->abort($Socket);
         return 0;
      }

      $Result = $this->pump($Socket, $Handler, $rate, $length);
      if ($Result['success'] === false) {
         $this->abort($Socket);
         return $Result['written'];
      }

      $remaining = $length - $Result['read'];
      if ($remaining < 0) {
         $this->abort($Socket);
         return $Result['written'];
      }

      if ($Result['deferred'] && $remaining > 0) {
         // The current read slice is owned by pendingBuffer; retain only the
         // caller's handler reference and unread length for the next callback.
         $this->directUploadHandler = $Handler;
         $this->directUploadRate = $rate;
         $this->directUploadRemaining = $remaining;
      }

      return $Result['written'];
   }
   /**
    * Pump a bounded file range into the ordered socket writer.
    *
    * `read` counts bytes whose ownership left the file descriptor. They were
    * either accepted by the socket or retained in pendingBuffer. `written`
    * counts only bytes accepted now and is therefore safe for transport stats.
    *
    * @param resource $Socket
    * @param resource $Handler
    * @return array{written:int,read:int,success:bool,deferred:bool}
    */
   protected function pump (
      &$Socket,
      &$Handler,
      int $rate,
      int $length
   ): array
   {
      $written = 0;
      $owned = 0;
      $deferred = false;

      if ($rate <= 0 || $length < 0) {
         return [
            'written' => 0,
            'read' => 0,
            'success' => false,
            'deferred' => false,
         ];
      }

      while ($owned < $length) {
         /** @var int<1, max> $size */
         $size = min($rate, $length - $owned);

         try {
            $buffer = @fread($Handler, $size);
         }
         catch (Throwable) {
            $buffer = false;
         }

         if ($buffer === false || $buffer === '') {
            return [
               'written' => $written,
               'read' => $owned,
               'success' => false,
               'deferred' => false,
            ];
         }

         $read = strlen($buffer);
         $owned += $read;

         $sent = 0;
         $complete = $this->transmit($Socket, $buffer, $sent);
         if ($complete === false) {
            $written += $sent;

            return [
               'written' => $written,
               'read' => $owned,
               'success' => false,
               'deferred' => false,
            ];
         }
         $written += $sent;

         if ($complete === null) {
            $deferred = true;
            break;
         }
      }

      return [
         'written' => $written,
         'read' => $owned,
         'success' => true,
         'deferred' => $deferred,
      ];
   }

   public function reject (string $raw): void
   {
      // ! Mark the package as rejected so callers (Decoder_, Request::decode,
      //   Frame::parse) can return `States::Rejected` without a separate
      //   per-decoder boolean flag. Cleared at the start of each `reading()`
      //   cycle.
      $this->rejected = true;

      try {
         @fwrite($this->Connection->Socket, $raw);
      }
      catch (Throwable) {
         // ...
      }

      $this->Connection->close();
   }
}
