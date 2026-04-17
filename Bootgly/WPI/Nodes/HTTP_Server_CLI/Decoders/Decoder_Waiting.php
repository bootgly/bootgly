<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use function substr;
use function time;

use const Bootgly\WPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


class Decoder_Waiting extends Decoders
{
   // * Metadata
   private int $decoded = 0;
   // @ Request Body
   private int $read = 0;


   public function init (): void
   {
      $this->decoded = time();
      $this->read = 0;
   }


   public function decode (Packages $Package, string $buffer, int $size): int
   {
      // !
      $WPI = WPI;
      /** @var Server $Server */
      $Server = $WPI->Server;

      /** @var Server\Request $Request */
      $Request = $WPI->Request;
      $Body = $Request->Body;

      // @ Check if Request Body is waiting data
      if ($Body->waiting) {
         // * Metadata
         if ($this->decoded === 0) {
            $this->decoded = time();
         }

         // ? Valid HTTP Client Body Timeout
         /**
         * Validate if the client is sending the rest of the Content data 
         * within a 60-second interval and if the total data has been received.
         */
         $elapsed = time() - $this->decoded;
         if ($elapsed >= 60 && $this->read === $Body->downloaded) {
            $Package->Decoder = null;
            return $Server::$Decoder->decode($Package, $buffer, $size);
         }

         // ... Continue reading the Request Body
         if ($Body->downloaded === null) {
            $offset = $Body->position ?? 0;
            $Body->raw = substr($buffer, $offset, $Body->length);
         }
         else {
            $Body->raw .= $buffer;
         }

         $Body->downloaded += $size;
         $this->read = $Body->downloaded;

         // ! Reject if received data exceeds declared Content-Length (memory exhaustion guard)
         if ($Body->downloaded > $Body->length) {
            $Body->waiting = false;

            $Package->Decoder = null;
            return $Server::$Decoder->decode($Package, $buffer, $size);
         }

         if ($Body->length > $Body->downloaded) {
            return 0;
         }

         $Body->waiting = false;

         return $Body->length ?? 0;
      }

      $Package->Decoder = null;
      return $Server::$Decoder->decode($Package, $buffer, $size);
   }
}
