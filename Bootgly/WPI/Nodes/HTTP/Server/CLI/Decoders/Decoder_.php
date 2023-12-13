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
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;


class Decoder_ extends Decoders
{
   public static function decode (Packages $Package, string $buffer, int $size) : int
   {
      static $inputs = []; // @ Instance local cache

      // @ Check local cache and return
      if ($size <= 512 && isset($inputs[$buffer])) {
         Server::$Request = $inputs[$buffer];

         if ($Package->changed) {
            Server::$Request->reboot();
         }

         return $size;
      }

      // @ Get callbacks
      $Request = Server::$Request;

      // @ Handle Package cache
      if ($Package->changed) {
         $Request = Server::$Request = new Request;
      }

      // @ Input HTTP Request
      $length = $Request->input($Package, $buffer, $size);

      // @ Write to local cache
      if ($length > 0 && $length <= 512) {
         $inputs[$buffer] = clone $Request;

         if (\count($inputs) > 512) {
            unset($inputs[\key($inputs)]);
         }
      }

      return $length; // @ Return Request length (0 = invalid)
   }
}
