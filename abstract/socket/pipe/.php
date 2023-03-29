<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Generator;


class Pipe
{
   // * Config
   // ! Stream
   public ? int $timeout;

   // * Data
   private array $pair;

   // * Meta
   public bool $paired;


   public function __construct (? int $timeout = null, ? bool $blocking = false, ? array $pair = null)
   {
      // * Config
      // ! Stream
      $this->timeout = $timeout;

      // * Data
      $this->pair = $pair ?? stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

      // * Meta
      $this->paired = true;


      try {
         // @ Set non-blocking to pipes
         // Read pipe
         stream_set_blocking($this->pair[0], $blocking);
         // Write pipe
         stream_set_blocking($this->pair[1], $blocking);
      } catch (\Throwable) {
         $this->paired = false;
      }
   }

   public function reading (int $length = 1024, ? int $timeout = null) : Generator
   {
      $read = [@$this->pair[0]];
      $write = null;
      $except = null;

      $microseconds = $timeout ?? $this->timeout ?? null;

      // @ Read output from pair
      while (true) {
         pcntl_signal_dispatch();

         try {
            $streams = @stream_select($read, $write, $except, 0, $microseconds);
         } catch (\Throwable) {
            $streams = false;
         }

         // @ Check result
         if ($streams === false) {
            yield false;

            break;
         } elseif ($streams === 0) {
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
      } catch (\Throwable) {
         $read = false;
      }

      if ($read === false) {
         // TODO check errors
      }

      return $read;
   }

   public function write (string $data, ? int $length = null) : int|false
   {
      try {
         $written = @fwrite($this->pair[1], $data, $length);
      } catch (\Throwable) {
         $written = false;
      }

      if ($written === false) {
         // TODO check errors
      }

      return $written;
   }

   public function __destruct ()
   {
      // @ Close the ends of the communication channel
      try {
         @fclose($this->pair[0]);
         @fclose($this->pair[1]);
      } catch (\Throwable) {
         // ...
      }
   }
}
