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


use Throwable;
use Generator;

use Bootgly\ABI\IO\IPC;


class Pipe implements IPC
{
   // * Config
   // ...

   // * Data
   private array $pair;

   // * Meta
   public bool $paired;


   public function __construct (bool $blocking)
   {
      // * Config
      // ...

      // * Data
      $this->pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

      // * Meta
      $this->paired = true;

      try {
         // @ Set non-blocking to pipes
         // Read pipe
         stream_set_blocking($this->pair[0], $blocking);
         // Write pipe
         stream_set_blocking($this->pair[1], $blocking);
      } catch (Throwable) {
         $this->paired = false;
      }
   }

   public function reading (int $length = 1024, ? int $timeout = null) : Generator
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
         } catch (Throwable $Throwable) {
            $streams = false;
         }

         // :
         if ($streams === false) {
            yield false;

            break;
         } else if ($streams === 0) {
            yield null;

            continue;
         }

         yield $this->read(length: $length);
      }
   }
   public function read (int $length = 1024) : string|false
   {
      try {
         $read = @fread($this->pair[0], $length);
      } catch (Throwable) {
         $read = false;
      }

      return $read;
   }

   public function write (string $data, ? int $length = null) : int|false
   {
      try {
         $written = @fwrite($this->pair[1], $data, $length);
      } catch (Throwable) {
         $written = false;
      }

      return $written;
   }

   public function close (bool $read = true, bool $write = true) : bool
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
      } catch (Throwable) {
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
