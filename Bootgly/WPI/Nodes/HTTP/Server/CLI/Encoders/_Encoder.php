<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP\Server\CLI\Encoders;


use Bootgly\API\Server as SAPI;

use Bootgly\WPI\Interfaces\TCP\Server\Packages;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP\Server\CLI as Server;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;


class _Encoder extends Encoders
{
   public static function encode (Packages $Package, &$size)
   {
      // @ Instance callbacks
      $Request  = Server::$Request;
      $Response = Server::$Response;
      $Router   = Server::$Router;

      // @ Perform test mode
      // TODO move to another encoder?
      if (SAPI::$mode === SAPI::MODE_TEST) {
         Server::$Response = $Response = new Response;
         Server::$Router = $Router = new Router(Server::class);

         $Response->Header->preset('Date', null);

         SAPI::boot(reset: true, base: Server::class);
      }

      // ! Response
      // @ Try to Invoke API Closure
      try {
         (SAPI::$Handler)($Request, $Response, $Router);
      } catch (\Throwable $Throwable) {
         $Response->Meta->status = 500; // @ Set 500 HTTP Server Error Response

         dump($Throwable->getMessage());

         if ($Response->Content->raw === '') {
            $Response->Content->raw = ' ';
         }
      }
      // TODO move to another encoder
      // @ Check if Request Content is waiting data
      if ($Request->Content->waiting) {
         return '';
      }
      // @ Output/Stream HTTP Response
      return $Response->output($Package, $size); // @ Return Response raw
   }
}
