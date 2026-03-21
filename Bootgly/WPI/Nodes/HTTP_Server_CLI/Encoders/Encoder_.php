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
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


class Encoder_ extends Encoders
{
   /**
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
    */
   public static function encode (Packages $Packages, null|int &$length): string
   {
      // @ Get callbacks
      $Request  = Server::$Request;
      $Response = &Server::$Response;
      $Router   = Server::$Router;

      // ! Reset Response state for new request
      $Response->reset();

      // @ Try to Invoke SAPI Closure
      try {
         $Result = SAPI::$Middlewares->process($Request, $Response,
            function (object $Request, object $Res) use ($Router): mixed {
               $Result = (SAPI::$Handler)($Request, $Res, $Router);

               // @ Resolve Generator-based routing inside the pipeline
               if ($Result instanceof \Generator) {
                  foreach ($Router->routing($Result) as $Responses) {
                     if ($Responses instanceof Response) {
                        $Res = $Responses;
                     }
                  }

                  return $Res;
               }

               if ($Result instanceof Response) {
                  return $Result;
               }

               return $Res;
            }
         );

         if ($Result instanceof Response && $Result !== $Response) {
            $Response = $Result;
         }
      }
      catch (\Throwable $Throwable) {
         $Response = new Response(code: 500, body: ' ');

         Throwables::debug($Throwable);
      }
      finally {
         // @ Check if Request Body is waiting data
         if ($Request->Body->waiting) {
            return '';
         }

         // @ Encode HTTP Response
         return $Response->encode($Packages, $length);
      }
   }
}
