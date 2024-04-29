<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;


use Bootgly\ABI\Debugging\Data\Throwables;

use Bootgly\API\Server as SAPI;

use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


class Encoder_Testing extends Encoders
{
   public static function encode (Packages $Packages, ? int &$length) : string
   {
      // @ Instance callbacks
      Server::$Response = new Response;
      Server::$Router = new Router(Server::class);

      // @ Remove dynamic Headers
      Server::$Response->Raw->Header->preset('Date', null);

      // @ Reset SAPI
      SAPI::boot(reset: true, base: Server::class, key: 'response');

      // @ Get callbacks
      $Request  = Server::$Request;
      $Response = &Server::$Response;
      $Router   = Server::$Router;

      // ! Response
      // @ Try to Invoke SAPI Closure
      try {
         $Routes = (SAPI::$Handler)($Request, $Response, $Router);

         if ($Routes instanceof \Generator) {
            foreach ($Router->routing($Routes) as $Responses) {
               if ($Responses instanceof Response) {
                  $Response = $Responses;
               }
            }
         }
      }
      catch (\Throwable $Throwable) {
         $Response = new Response(code: 500, body: ' ');

         Throwables::debug($Throwable);
      }
      finally {
         // @ Check if Request Body is waiting data
         if ($Request->Raw->Body->waiting) {
            return '';
         }

         // @ Output/Stream HTTP Response
         return $Response->output($Packages, $length);
      }
   }
}
