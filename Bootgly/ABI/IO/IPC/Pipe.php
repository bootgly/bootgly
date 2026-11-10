<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\IPC;


use const PHP_INT_MAX;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_DGRAM;
use const STREAM_SOCK_STREAM;
use function fclose;
use function fread;
use function fwrite;
use function intdiv;
use function pcntl_signal_dispatch;
use function stream_set_blocking;
use function stream_set_chunk_size;
use function stream_socket_pair;
use Generator;
use Throwable;

use Bootgly\ABI\Events\Selector;
use Bootgly\ABI\Events\Selector\Refusal;


class Pipe
{
   // * Config
   public bool $blocking;
   public readonly int $type;

   // * Data
   /** @var array<resource> */
   private array $pair;

   // * Metadata
   public bool $paired;


   public function __construct (int $type = STREAM_SOCK_STREAM)
   {
      // * Config
      $this->blocking = false;
      $this->type = $type;

      // * Data
      // $this->pair;

      // * Metadata
      $this->paired = false;
   }

   public function open (): bool
   {
      // !
      $pair = stream_socket_pair(
         STREAM_PF_UNIX,
         $this->type,
         STREAM_IPPROTO_IP
      );
      // ?
      if ($pair === false) {
         return false;
      }

      // * Config
      $blocking = $this->blocking;
      // * Data
      $this->pair = $pair;
      // * Metadata
      $this->paired = true;

      try {
         // @ Set non-blocking to pipes
         // Read pipe
         stream_set_blocking($this->pair[0], $blocking);
         // Write pipe
         stream_set_blocking($this->pair[1], $blocking);
      }
      catch (Throwable) {
         $this->paired = false;
      }

      return $this->paired;
   }

   /**
    * Read the pipe as it becomes readable — a loop that yields once per
    * wait: the chunk read (or `false` when that read failed — the loop goes
    * on), `null` when the timeout passed with nothing to read, or `false`
    * as the last value when the read end cannot be waited on (closed, or a
    * negative timeout) — only then the loop ends. Pending signals are
    * dispatched between waits, and a signal that interrupts a wait resumes
    * it without extending the timeout.
    *
    * A timeout never ends the loop: the consumer leaves it (`break`) when it
    * has waited enough. Without a timeout the loop polls, so the consumer
    * paces it or leaves it at the first `null`.
    *
    * @param int $length The most bytes to read per chunk.
    * @param null|int $timeout How long each wait lasts, in microseconds; `null` polls without waiting.
    *
    * @return Generator<int,string|false|null>
    */
   public function reading (int $length = 1024, null|int $timeout = null): Generator
   {
      // * Data
      $Stream = $this->pair[0];

      // ?
      if ($timeout !== null && $timeout < 0) {
         yield false;

         return;
      }

      // !
      $Selector = new Selector;
      try {
         $Selector->watch($Stream);
      }
      catch (Refusal) {
         yield false;

         return;
      }

      // @@
      while (true) {
         pcntl_signal_dispatch();

         // ! The deadline of this wait is fixed before it, so resuming after
         //   a signal never extends it.
         $deadline = 0;
         if ($timeout !== null) {
            $tick = $Selector->Clock->tick;

            // ? A timeout too long to count in nanoseconds waits as long as
            //   the clock goes.
            $deadline = $timeout > intdiv(PHP_INT_MAX - $tick, 1_000)
               ? PHP_INT_MAX
               : $tick + $timeout * 1_000;
         }

         try {
            $ready = $Selector->wait($deadline);

            while ($ready === 0 && $Selector->interrupted) {
               pcntl_signal_dispatch();

               $ready = $Selector->wait($deadline);
            }
         }
         catch (Refusal) {
            yield false;

            break;
         }

         // :
         if ($ready === 0) {
            yield null;

            continue;
         }

         yield $this->read(length: $length);
      }
   }
   public function read (int $length = 1024): string|false
   {
      if ($length < 1) {
         return false;
      }

      try {
         // ! PHP stream reads default to 8192 bytes. On datagram sockets a
         //   short read discards the unread suffix, so size the receive chunk
         //   to the caller's frame buffer before reading.
         if ($this->type === STREAM_SOCK_DGRAM) {
            @stream_set_chunk_size($this->pair[0], $length);
            // `stream_set_chunk_size()` returns the previous size. Reapply it
            // once and require the requested value to have become effective.
            if (@stream_set_chunk_size($this->pair[0], $length) !== $length) {
               return false;
            }
         }
         $read = @fread($this->pair[0], $length);
      }
      catch (Throwable) {
         $read = false;
      }

      return $read;
   }

   /**
    * Write data to the write pipe
    * 
    * @param string $data
    * @param null|int $length
    *
    * @return int|false
    */
   public function write (string $data, null|int $length = null): int|false
   {
      if ($length !== null && $length < 1) {
         return false;
      }

      try {
         $written = @fwrite($this->pair[1], $data, $length);
      }
      catch (Throwable) {
         $written = false;
      }

      return $written;
   }

   public function close (bool $read = true, bool $write = true): bool
   {
      $closed0 = false;
      $closed1 = false;

      // @ Close the ends of the communication channel
      try {
         if ($read) {
            $closed0 = fclose($this->pair[0]);
         }

         if ($write) {
            $closed1 = fclose($this->pair[1]);
         }
      }
      catch (Throwable) {
         $closed0 = false;
         $closed1 = false;
      }

      return $closed0 && $closed1;
   }

   public function __destruct ()
   {
      $this->close();
   }
}
