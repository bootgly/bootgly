<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


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
use function fwrite;
use function get_resource_type;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function microtime;
use function strlen;
use function substr;
use Throwable;

use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages as Server_Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


abstract class Packages extends Server_Packages implements WPI\Connections\Packages
{
   use LoggableEscaped;


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


   public Connection $Connection;


   public function __construct (Connection &$Connection)
   {
      $this->Logger = new Logger(channel: __CLASS__);
      $this->Connection = $Connection;

      parent::__construct();

      // * Metadata
      // # Stream
      $this->downloading = [];
      $this->uploading = [];
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
         #$this->log('Failed to ' . $operation . ' package: End-of-File!' . PHP_EOL);
         Connections::$errors[$operation]++;
         $this->Connection->close();
         return true;
      }

      if (is_resource($Socket) === false || get_resource_type($Socket) !== 'stream') {
         #$this->log('Failed to ' . $operation . ' package: closing connection...' . PHP_EOL);
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
         #$this->log('Failed to read buffer: input data is empty!' . PHP_EOL, self::LOG_WARNING_LEVEL);
         $this->Connection->close();
         return false;
      }

      // @ Check issues
      if ($buffer === false) {
         return $this->fail($Socket, 'read');
      }

      // @ On success
      $this->changed = ($this->input !== $input);

      // @ Handle cache and set Input
      if ($this->cache === false || $this->changed === true) {
         $this->input = $input;
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
      $inputLength = $received; // Store original buffer length for pipelining
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
         $state = ($this->Decoder ?? Server::$Decoder)->decode($this, $input, $received);
      }

      if ($state === States::Complete) {
         $this->write($Socket);

         // @ Recommendation #3: write deferred to event loop. Do NOT
         //   pipeline (next request would race with the unflushed bytes)
         //   and do NOT close synchronously — `writing()` will close on
         //   drain via `closeAfterDrain`.
         if ($this->pendingBuffer !== '') {
            if ($this->closeAfterWrite) {
               $this->closeAfterDrain = true;
               $this->closeAfterWrite = false;
            }
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

               $this->changed = true;
               $this->input = $remaining;
               $this->consumed = 0;
               $this->rejected = false;

               $decoded = ($this->Decoder ?? Server::$Decoder)->decode($this, $remaining, $remainingLength);

               if ($decoded !== States::Complete) {
                  break; // Incomplete or rejected \u2014 stop pipelining
               }

               $this->write($Socket);

               // @ Pipeline write deferred — stop and let event loop drain.
               //   PHPStan cannot infer that `write()` mutates `$pendingBuffer`
               //   via `writing()`, so it narrows the property to `''` here.
               if ($this->pendingBuffer !== '') { // @phpstan-ignore notIdentical.alwaysFalse
                  if ($this->closeAfterWrite) { // @phpstan-ignore if.alwaysFalse
                     $this->closeAfterDrain = true;
                     $this->closeAfterWrite = false;
                  }
                  return true;
               }

               if ($this->closeAfterWrite) { // @phpstan-ignore if.alwaysFalse
                  $this->closeAfterWrite = false;
                  $this->Connection->close();
                  return true;
               }

               $offset += $this->consumed; // @phpstan-ignore smaller.invalid
            }
         }
      }
      // @ Consume test handler on reject (decoder rejected, encoder never ran)
      else if ($state === States::Rejected && isset(SAPI::$Suite)) {
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
      // ! Recommendation #3: Backpressure-aware async write state machine.
      //   Two entry modes:
      //   - Forward: caller passes encoded response in `$buffer`. We try
      //     fwrite once; on EAGAIN-like zero-write we stash the un-sent
      //     bytes in `$pendingBuffer`, register EVENT_WRITE, and return
      //     true. Caller (reading()) honors `$pendingBuffer !== ''` and
      //     stops pipelining.
      //   - Resume: event loop calls us back with empty `$buffer` after
      //     the socket signals writable. We drain `$pendingBuffer` from
      //     `$pendingOffset`. On full drain we del EVENT_WRITE and apply
      //     `$closeAfterDrain`. Replaces the old synchronous
      //     `stream_select(..., 200_000)` retry that closed legitimate
      //     slow clients after 50 zero-writes.

      // @ Resume mode: drain stashed bytes from a previous stall.
      if ($this->pendingBuffer !== '') {
         // Deadline guard: stalled longer than maxWriteWallTime \u2192 close.
         if ($this->pendingDeadline > 0.0 && microtime(true) > $this->pendingDeadline) {
            $this->reset($Socket);
            $this->Connection->close();
            return false;
         }

         // Optional: caller passed extra bytes alongside resume. Append
         //   them subject to the per-connection memory cap.
         if ($buffer !== '') {
            $remaining = strlen($this->pendingBuffer) - $this->pendingOffset + strlen($buffer);
            if ($remaining > Server::$maxPendingBytes) {
               $this->reset($Socket);
               $this->Connection->close();
               return false;
            }
            $this->pendingBuffer .= $buffer;
         }

         $buffer = ($this->pendingOffset === 0)
            ? $this->pendingBuffer
            : substr($this->pendingBuffer, $this->pendingOffset);
         $length = strlen($buffer);
      }

      // !
      $length ??= strlen($buffer);
      if ($length === 0 || $buffer === '') {
         // Nothing to send and nothing pending: ensure event-loop registration is cleared.
         $this->reset($Socket);
         if ($this->closeAfterDrain) {
            $this->closeAfterDrain = false;
            $this->Connection->close();
         }
         return true;
      }

      $written = 0;
      $failed = false;
      $sent = 0; // Bytes sent to client per write loop iteration

      // @
      try {
         while ($buffer) {
            $sent = @fwrite($Socket, $buffer, $length); // @phpstan-ignore-line

            if ($sent === false) {
               $failed = true;
               break;
            }
            if ($sent === 0) {
               // ! Backpressure: defer to the event loop instead of
               //   busy-spinning or closing. Stash the un-sent tail and
               //   request EVENT_WRITE; the loop will reenter `writing()`
               //   when the socket is writable.
               $newPendingLen = strlen($buffer);
               if ($newPendingLen > Server::$maxPendingBytes) {
                  $this->reset($Socket);
                  $this->Connection->close();
                  return false;
               }

               $this->pendingBuffer = $buffer;
               $this->pendingOffset = 0;
               if ($this->pendingDeadline === 0.0) {
                  $this->pendingDeadline = microtime(true) + (float) Server::$maxWriteWallTime;
               }
               if (! $this->writeRegistered && isset(Server::$Event)) {
                  Server::$Event->add($Socket, Server::$Event::EVENT_WRITE, $this);
                  $this->writeRegistered = true;
               }

               // Stats for the partial write (if any).
               if ($written > 0 && Connections::$stats) {
                  Connections::$writes++;
                  Connections::$written += $written;
                  if ( isSet(Connections::$Connections[(int) $Socket]) ) {
                     Connections::$Connections[(int) $Socket]->writes++;
                  }
               }
               return true;
            }

            $written += $sent;

            if ($sent < $length) {
               $buffer = substr($buffer, $sent);
               $length -= $sent;
               continue;
            }

            if ( count($this->uploading) ) {
               $written += $this->uploading($Socket);
               // If uploading() stalled, it set pendingBuffer + EVENT_WRITE.
               if ($this->pendingBuffer !== '') {
                  if ($written > 0 && Connections::$stats) {
                     Connections::$writes++;
                     Connections::$written += $written;
                     if ( isSet(Connections::$Connections[(int) $Socket]) ) {
                        Connections::$Connections[(int) $Socket]->writes++;
                     }
                  }
                  return true;
               }
            }

            break;
         };
      }
      catch (Throwable) {
         $sent = false;
      }

      // @ Full drain reached this point: cleanup deferred-write state
      //   (idempotent if no EVENT_WRITE was ever registered).
      $this->reset($Socket);

      // @ Close Connection
      if ($sent === false) {
         $this->Connection->close();
      }
      // @ Fail
      if ($failed) {
         return $this->fail($Socket, 'write');
      }

      // @ Set Stats
      if (Connections::$stats) {
         // Global
         Connections::$writes++;
         Connections::$written += $written;
         // Per client
         if ( isSet(Connections::$Connections[(int) $Socket]) ) {
            Connections::$Connections[(int) $Socket]->writes++;
         }
      }

      // @ Apply close-after-drain (deferred close intent from reading()).
      if ($this->closeAfterDrain) {
         $this->closeAfterDrain = false;
         $this->Connection->close();
      }

      return true;
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
      $queued = $this->uploading[0] ?? null;
      if (! is_array($queued)) {
         return 0;
      }

      if (! array_key_exists('file', $queued) || ! is_string($queued['file']) || $queued['file'] === '') {
         return 0;
      }
      $file = $queued['file'];

      $Handler = @fopen($file, 'r');
      if ($Handler === false) {
         return 0;
      }
      $parts = (array) ($queued['parts'] ?? []);
      $pads = (array) ($queued['pads'] ?? []);
      $close = array_key_exists('close', $queued) ? (bool) $queued['close'] : false;

      $written = 0;

      foreach ($parts as $index => $part) {
         if (! is_array($part)) {
            continue;
         }

         $offsetValue = $part['offset'] ?? null;
         $lengthValue = $part['length'] ?? null;
         if (! is_int($offsetValue) || ! is_int($lengthValue)) {
            continue;
         }

         $offset = $offsetValue;
         $length = $lengthValue;

         $pad = $pads[$index] ?? null;
         $prepend = '';
         $append = '';

         if (is_array($pad)) {
            $prependValue = $pad['prepend'] ?? null;
            $appendValue = $pad['append'] ?? null;
            $prepend = is_string($prependValue) ? $prependValue : '';
            $append = is_string($appendValue) ? $appendValue : '';
         }

         // @ Move pointer of file to offset
         try {
            @fseek($Handler, $offset, SEEK_SET);
         }
         catch (Throwable) {
            return $written;
         }

         // @ Prepend
         if ($prepend !== '') {
            try {
               $sent = @fwrite($Socket, $prepend);
            }
            catch (Throwable) {
               break;
            }

            if ($sent === false) break;

            $written += $sent;
            // TODO check if the data has been completely sent
         }

         // @ Set over / rate
         $over = 0;
         $rate = 1 * 1024 * 1024; // 1 MB (1048576) = Max rate to read/send data file by loop

         if ($length < $rate) {
            $rate = $length;
         }
         else if ($length > $rate) {
            $over = $length % $rate;
         }

         // @ Upload File
         if ($over > 0) {
            $written += $this->upload($Socket, $Handler, $over, $over);
            // TODO check if the data has been completely sent
            $length -= $over;
         }

         $written += $this->upload($Socket, $Handler, $rate, $length); // @phpstan-ignore-line

         // @ Append
         if ($append !== '') {
            try {
               $sent = @fwrite($Socket, $append);
            }
            catch (Throwable) {
               break;
            }

            if ($sent === false) break;

            $written += $sent;
            // TODO check if the data has been completely sent
         }
      }

      // @ Try to close the file Handler
      try {
         @fclose($Handler);
      }
      catch (Throwable) {}

      // @ Unset current uploading
      unSet($this->uploading[0]);

      // @ Try to close the Socket if requested
      if ($close) {
         try {
            $this->Connection->close();
         }
         catch (Throwable) {}
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
      $written = 0;

      while ($written < $length) {
         // ! Stream
         // @ Read buffer using Handler
         try {
            $buffer = @fread($Handler, $rate);
         }
         catch (Throwable) {
            break;
         }

         if ($buffer === false) break;

         $read = strlen($buffer);

         // @ Write part of data (if exists) to Client
         while ($read) {
            // ! Socket
            try {
               $sent = @fwrite($Socket, $buffer, $read); // @phpstan-ignore-line
            }
            catch (Throwable) {
               break;
            }

            if ($sent === false) break;
            if ($sent === 0) {
               // ! Recommendation #3: defer instead of synchronously
               //   closing. Stash the un-sent slice as `pendingBuffer`
               //   and request EVENT_WRITE; `writing()` will drain it on
               //   loop reentry. NOTE: bytes still on disk past this
               //   fread chunk are not auto-resumed in this revision \u2014
               //   callers that need full-file delivery across stalls
               //   should keep `$rate >= $length` (single fread).
               if ($read > Server::$maxPendingBytes) {
                  $this->Connection->close();
                  return $written;
               }

               $this->pendingBuffer = $buffer;
               $this->pendingOffset = 0;
               if ($this->pendingDeadline === 0.0) {
                  $this->pendingDeadline = microtime(true) + (float) Server::$maxWriteWallTime;
               }
               if (! $this->writeRegistered && isset(Server::$Event)) {
                  Server::$Event->add($Socket, Server::$Event::EVENT_WRITE, $this);
                  $this->writeRegistered = true;
               }
               return $written;
            }

            $written += $sent;

            if ($sent < $read) {
               $buffer = substr($buffer, $sent);
               $read -= $sent;
               continue;
            }

            break;
         }

         // @ Check Handler EOF (End-Of-File)
         try {
            $end = @feof($Handler);
         }
         catch (Throwable) {
            break;
         }

         if ($end) break;
      }

      return $written;
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
