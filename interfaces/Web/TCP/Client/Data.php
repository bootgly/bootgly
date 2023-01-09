<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Client\Connections;


use Bootgly\CLI\_\ {
   Logger\Logging
};

use Bootgly\Web\TCP\Client;


class Data
{
   use Logging;


   public Client $Client;

   // * Meta
   public int $read;
   public int $written;
   // @ Stats
   public int $reads;
   public int $writes;
   public array $errors;


   public function __construct (Client &$Client)
   {
      $this->Client = $Client;

      // * Meta
      $this->read = 0;            // Input Data length (bytes read).
      $this->written = 0;         // Output Data length (bytes written).
      // @ Stats
      $this->reads = 0;           // Socket Read count
      $this->writes = 0;          // Socket Write count
      $this->errors['read'] = 0;  // Socket Reading errors
      $this->errors['write'] = 0; // Socket Writing errors
   }

   public function write (string $data, ? int $length = null)
   {
      try {
         while (true) {
            $written = @fwrite($this->Client->Socket, $data, $length);

            if ($written === false) {
               break;
            }

            if ($written < $length) {
               $data = substr($data, $written);
               $length -= $written;
            } else {
               break;
            }
         }
      } catch (\Throwable) {
         $written = false;
      }

      // @ Check issues
      if ($written === 0 || $written === false) {
         try {
            $eof = @feof($this->Client->Socket);
         } catch (\Throwable) {
            $eof = false;
         }

         // @ Check connection close/reset by server?
         if ($eof) {
            // $this->log('Failed to write data: End-of-file!' . PHP_EOL);
            return false;
         }

         // @ Check connection close intention by peer?
         if ($written === 0) {
            // $this->log('Failed to write data: 0 bytes written!' . PHP_EOL);
         }

         $this->errors['write']++;

         return false;
      }

      // @ Write Stats
      // Global
      $this->writes++;
      $this->written += $written;
   }

   public function read ()
   {
      return fgets($this->Client->Socket, 1024);
   }
}