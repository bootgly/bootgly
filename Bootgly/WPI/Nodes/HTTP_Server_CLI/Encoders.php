<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;

use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;


abstract class Encoders implements Encoder
{
   /**
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
    */
   abstract public static function encode (Packages $Package, null|int &$length): string;

   /**
    * Find the exchange an escaping response belongs to.
    *
    * Shared by every encoder deliberately: it decides which lifecycle a
    * response that left the onion is accounted against, so two copies mean a
    * correction can be applied to one wire path and silently missed on the
    * other — which is exactly how a response-splitting fix once shipped half
    * done and a mutation test reported a false survivor.
    *
    * `$Injected` is the response the encoder handed to the onion, captured
    * BEFORE a handler-returned replacement could be assigned. It cannot be read
    * back from `Server::$Response`: the local is an alias of that static, so
    * assigning the replacement also overwrote it. The escaping response is the
    * injected one, and only its weak snapshot still names the exchange —
    * `Exchange::finish()` drops the Request aliases but deliberately keeps the
    * snapshot alive for exactly this kind of late lookup.
    */
   protected static function resolve (
      Response $Response,
      Response $Injected,
      Request $Request,
   ): null|Exchange
   {
      $Exchange = Exchange::fetch($Response);
      if ($Exchange !== null) {
         return $Exchange;
      }

      if ($Injected !== $Response) {
         $Exchange = Exchange::fetch($Injected);
         if ($Exchange !== null) {
            return $Exchange;
         }
      }

      return Exchange::fetch($Request);
   }
}
