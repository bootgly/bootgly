<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\nodes\HTTP\Server\Decoders;


use Bootgly\WPI\interfaces\TCP\Server\Packages;
use Bootgly\WPI\nodes\HTTP\Server;
use Bootgly\WPI\nodes\HTTP\Server\Decoders;
use Bootgly\WPI\nodes\HTTP\Server\Request;


class _Decoder extends Decoders
{
   public static function decode (Packages $Package, string $buffer, int $size)
   {
      static $inputs = []; // @ Instance local cache

      // @ Check local cache and return
      if ($size <= 512 && isset($inputs[$buffer])) {
         Server::$Request = $inputs[$buffer];
         return $size;
      }

      // @ Instance callbacks
      $Request = Server::$Request;

      // TODO move to another decoder
      // @ Check if Request Content is waiting data
      if ($Request->Content->waiting) {
         // @ Finish filling the Request Content raw with TCP read buffer
         $Content = &$Request->Content;

         $Content->raw .= $buffer;
         $Content->downloaded += $size;

         if ($Content->length > $Content->downloaded) {
            return 0;
         }

         $Content->waiting = false;

         return $Content->length;
      }

      // @ Handle Package cache
      if ($Package->changed) {
         $Request = Server::$Request = new Request;
      }

      // @ Boot HTTP Request
      $length = $Request->boot($Package, $buffer, $size);

      // @ Write to local cache
      if ($length > 0 && $length <= 512) {
         $inputs[$buffer] = clone $Request;

         if (count($inputs) > 512) {
            unset($inputs[key($inputs)]);
         }
      }

      return $length; // @ Return Request length (0 = invalid)
   }
}
