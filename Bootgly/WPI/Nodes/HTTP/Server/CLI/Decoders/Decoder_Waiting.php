<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP\Server\CLI\Decoders;


use Bootgly\WPI\Interfaces\TCP\Server\Packages;
use Bootgly\WPI\Nodes\HTTP\Server\CLI as Server;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Decoders;


class Decoder_Waiting extends Decoders
{
   // * Metadata
   private static int $decoded;
   // @ Request Content
   private static int $read;


   public static function decode (Packages $Package, string $buffer, int $size) : int
   {
      // @ Get callbacks
      $Request = Server::$Request;
      $Content = &$Request->Content;

      // @ Check if Request Content is waiting data
      if ($Content->waiting) {
         // * Metadata
         self::$decoded ??= time();
         self::$read ??= 0;
         // <<
         $Content = &$Request->Content;

         // ? Valid HTTP Client Body Timeout
         /**
         * Validate if the client is sending the rest of the Content data 
         * within a 60-second interval.
         */
         if ((time() - self::$decoded) >= 60 && self::$read === $Content->downloaded) {
            Server::$Decoder = new Decoder_;
            return Server::$Decoder::decode($Package, $buffer, $size);
         }

         // @
         if ($Content->downloaded === null) {
            $Content->raw = \substr($buffer, $Content->position, $Content->length);
         }
         else {
            $Content->raw .= $buffer;
         }

         $Content->downloaded += $size;
         self::$read = $Content->downloaded;

         if ($Content->length > $Content->downloaded) {
            return 0;
         }

         $Content->waiting = false;

         return $Content->length;
      }

      Server::$Decoder = new Decoder_;
      return Server::$Decoder::decode($Package, $buffer, $size);
   }
}
