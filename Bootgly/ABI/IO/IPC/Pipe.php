<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\IPC;


use function stream_socket_pair;
use function stream_set_blocking;
use function stream_select;
use function fread;
use function fwrite;
use function fclose;
use function pcntl_signal_dispatch;
use Throwable;
use Generator;

use Bootgly\ABI\IO\IPC;


class Pipe implements IPC
{
   // * Config
   public bool $blocking;

   // * Data
   /** @var array<resource> */
   private array $pair;

   // * Metadata
   public bool $paired;


   public function __construct ()
   {
      // * Config
      $this->blocking = false;

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
         STREAM_SOCK_STREAM,
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

   public function reading (int $length = 1024, null|int $timeout = null): Generator
   {
      // * Config
      // ...
      // * Data
      $read = [$this->pair[0]];
      $write = null;
      $except = null;

      // @
      while (true) {
         pcntl_signal_dispatch();

         try {
            $streams = stream_select($read, $write, $except, 0, $timeout);
         }
         catch (Throwable $Throwable) {
            $streams = false;
         }

         // :
         if ($streams === false) {
            yield false;

            break;
         }
         else if ($streams === 0) {
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
