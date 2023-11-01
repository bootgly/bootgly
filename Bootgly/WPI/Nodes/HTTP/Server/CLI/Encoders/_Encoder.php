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


use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\API\Server as SAPI;

use Bootgly\WPI\Interfaces\TCP\Server\Packages;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP\Server\CLI as Server;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;


class _Encoder extends Encoders
{
   public static function encode (Packages $Packages, &$size)
   {
      // @ Perform test mode
      // TODO move to another encoder?
      switch (SAPI::$mode) {
         case SAPI::MODE_TEST:
            Server::$Response = new Response;
            Server::$Router = new Router(Server::class);

            Server::$Response->Header->preset('Date', null);

            SAPI::boot(reset: true, base: Server::class);
            break;
      }

      // @ Instance callbacks
      $Request  = Server::$Request;
      $Response = Server::$Response;
      $Router   = Server::$Router;

      // ! Response
      // @ Try to Invoke SAPI Closure
      try {
         $Responses = (SAPI::$Handler)($Request, $Response, $Router);

         if ($Responses instanceof \Generator) {
            foreach ($Responses as $Response) {
               if ($Response instanceof Response) {
                  break;
               }
            }
         }
      }
      catch (\Throwable $Throwable) {
         $Response = new Response(code: 500, body: ' ');

         Throwables::debug($Throwable);
      }
      finally {
         // TODO move to another encoder
         // @ Check if Request Content is waiting data
         if ($Request->Content->waiting) {
            return '';
         }

         // @ Output/Stream HTTP Response
         return $Response->output($Packages, $size);
      }
   }
}
