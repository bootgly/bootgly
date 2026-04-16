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


use function count;
use function key;

use const Bootgly\WPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


class Decoder_ extends Decoders
{
   public static function decode (Packages $Package, string $buffer, int $size): int
   {
      static $inputs = []; // @ Instance local cache

      // ? Check local cache and return
      if ($size <= 2048 && isSet($inputs[$buffer])) {
         Server::$Request = $inputs[$buffer];

         if ($Package->changed) {
            Server::$Request->reboot();

            Server::$Request->address = $Package->Connection->ip;
            Server::$Request->port = $Package->Connection->port;
            Server::$Request->scheme = $Package->Connection->encrypted ? 'https' : 'http';
         }

         return $size;
      }

      // !
      $WPI = WPI;
      // ?! Handle Package cache
      if ($Package->changed) {
         $WPI->Request = new Request;
      }
      // !
      /** @var Request $Request */
      $Request = $WPI->Request;

      // @
      $length = $Request->decode($Package, $buffer, $size);

      // @ Write to local cache
      // Skip caching when Body is waiting for more data (chunked/streaming)
      if ($length > 0 && $length <= 2048 && ! $Request->Body->waiting) {
         $inputs[$buffer] = clone $Request;

         if (count($inputs) > 512) {
            unset($inputs[key($inputs)]);
         }
      }

      return $length; // @ Return Request length (0 = invalid)
   }
}
