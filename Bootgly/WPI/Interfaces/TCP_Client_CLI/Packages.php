<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI;


use const PHP_EOL;
use function feof;
use function fread;
use function fwrite;
use function get_resource_type;
use function is_resource;
use function microtime;
use function strlen;
use function substr;
use Throwable;

use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;


class Packages implements WPI\Connections\Packages
{
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }

   // * Config
   // ...

   // * Data
   // # IO
   public string $output;
   public string $input;

   // * Metadata
   public int $written;
   public int $read;
   // # Stats
   public int $writes;
   public int $reads;
   /** @var array<string,int> */
   public array $errors;
   // # Expiration
   public bool $expired;

   public Connection $Connection;


   public function __construct (Connection &$Connection)
   {
      $this->Connection = $Connection;

      // * Config
      // ...

      // * Data
      // # IO
      $this->output ='';
      $this->input = '';

      // * Metadata
      $this->written = 0;         // Output Data length (bytes written).
      $this->read = 0;            // Input Data length (bytes read).
      // # Stats
      $this->writes = 0;          // Socket Write count
      $this->reads = 0;           // Socket Read count
      $this->errors['write'] = 0; // Socket Writing errors
      $this->errors['read'] = 0;  // Socket Reading errors
      // # Expiration
      $this->expired = false;
   }

   /**
    * Handle failed package operation.
    * 
    * @param resource $Socket
    * @param string $operation
    * @param mixed $result
    *
    * @return bool
    */
   public function fail ($Socket, string $operation, mixed $result): bool
   {
      try {
         $eof = @feof($Socket);
      }
      catch (Throwable) {
         $eof = false;
      }

      // @ Check connection reset?
      if ($eof) {
         // $this->log(
         //    'Failed to ' . $operation . ' package: End-of-file!' . PHP_EOL,
         //    self::LOG_WARNING_LEVEL
         // );

         $this->Connection->close();

         return true;
      }

      // @ Check connection close intention?
      if ($result === 0) {
         #$this->Logger->log(debug: 'Failed to ' . $operation . ' package: 0 byte handled!' . PHP_EOL);
      }

      if (is_resource($Socket) && get_resource_type($Socket) === 'stream') {
         $this->Logger->log(
            warning: 'Failed to ' . $operation . ' package: closing connection...' . PHP_EOL
         );

         $this->Connection->close();
      }

      Connections::$errors[$operation]++;

      return false;
   }
   /**
    * Write data to server.
    *
    * The output buffer is authoritative: every call consumes exactly the
    * bytes the kernel accepted, and an unsent suffix STAYS buffered for the
    * next write-ready event — a short `fwrite()` is progress, never
    * completion. The completion hook fires only when the buffer drains.
    *
    * @param resource $Socket
    * @param null|int<0, max> $length
    *
    * @return bool
    */
   public function writing (&$Socket, null|int $length = null): bool
   {
      // !
      $buffer = $this->output;
      $pending = strlen($buffer);
      $target = $length !== null && $length < $pending ? $length : $pending;
      $written = 0;

      // ? Nothing queued — a spurious write-ready event completes nothing
      if ($target === 0) {
         return true;
      }

      // @
      try {
         while ($written < $target) {
            $sent = @fwrite($Socket, substr($buffer, $written, $target - $written));

            if ($sent === false) {
               return $this->fail($Socket, 'write', $written);
            }
            // ? Zero progress — the kernel buffer is full. Yield back to the
            //   readiness loop instead of spinning; the caller's absolute
            //   deadline bounds a peer that never drains.
            if ($sent === 0) {
               $deadline = $this->Connection->Client?->deadline;
               if ($deadline !== null && microtime(true) >= $deadline) {
                  return $this->fail($Socket, 'write', $written);
               }
               break;
            }

            $written += $sent;
         }
      }
      catch (Throwable) {
         return $this->fail($Socket, 'write', $written);
      }

      // @ Consume exactly the accepted bytes — the suffix stays queued for
      //   the next write-ready event
      $this->output = substr($buffer, $written);

      // @ Set Stats
      if (Connections::$stats && $written > 0) {
         // Global
         Connections::$writes++;
         Connections::$written += $written;
         // Per client — $this->Connection IS the registry entry for $Socket;
         // direct access skips two hash lookups + cast per write.
         $this->Connection->writes++;
      }

      // ?: Unfinished — stay armed for the next write-ready event; the
      //    completion hook must never fire on a partial write
      if ($this->output !== '') {
         return true;
      }

      // # Hook
      if (Client::$onDataWrite) {
         (Client::$onDataWrite)($Socket, $this->Connection, $this);
      }

      return true;
   }
   /**
    * Read data from server.
    * 
    * @param resource $Socket
    * @param null|int<1,max> $length
    * @param null|int<0,max> $timeout
    *
    * @return bool
    */
   public function reading (
      &$Socket, null|int $length = null, null|int $timeout = null
   ): bool
   {
      // !
      $input = '';
      $received = 0; // Bytes received from server
      $total = $length ?? 0; // Total length of packet = the expected length of packet or 0

      $started = 0;
      if ($length > 0 || $timeout > 0) {
         $started = microtime(true);
      }

      // @
      try {
         do {
            $buffer = @fread($Socket, $length ?? 65535); // @phpstan-ignore-line
            #$buffer = @stream_socket_recvfrom($Socket, $length ?? 65535);

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

            // @ TLS may buffer additional decrypted data that stream_select() cannot see.
            //   Try one more non-blocking read to drain any remaining SSL-layer bytes.
            //   Plain TCP has no hidden buffer layer — skip the extra syscall there
            //   (this was one full wasted fread per read on unencrypted connections).
            if ($this->Connection->encrypted) {
               $extra = @fread($Socket, 65535);
               if ($extra !== false && $extra !== '') {
                  $input .= $extra;
                  $received += strlen($extra);
               }
            }

            break;
         }
         while ($received < $total || $total === 0);
      }
      catch (Throwable) {
         $buffer = false;
      }

      // @ Check connection close intention by server?
      // Close connection if input data is empty to avoid unnecessary loop?
      // TODO remove?
      if ($buffer === '') {
         return false;
      }

      // @ Check issues
      if ($buffer === false) {
         return $this->fail($Socket, 'read', $buffer);
      }

      // @ Set Input
      $this->input = $input;

      // @ Set Stats (disable to max performance in benchmarks)
      if (Connections::$stats) {
         // Global
         Connections::$reads++;
         Connections::$read += $received;
         // Per client
         #Connections::$Connections[(int) $Socket]['reads']++;
      }

      // # Hook
      if (Client::$onDataRead) {
         (Client::$onDataRead)($Socket, $this->Connection, $this);
      }

      return true;
   }

   public function write (&$Socket, null|int $length = null): bool
   {
      return false;
   }
   public function read (&$Socket): void
   {}
}
