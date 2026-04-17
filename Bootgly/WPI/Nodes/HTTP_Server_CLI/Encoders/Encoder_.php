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


use Generator;
use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
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

      // @ Reset Response state for new request
      $Response->reset();

      // @ Bind per-request context (used by Response::defer() when needed).
      $Response->bind($Packages, $Packages->Connection->Socket);

      // @
      try {
         $resolved = false;

         // @ Fast path: resolve from route cache (bypass Generator entirely)
         if ($Router->cached) {
            $Result = $Router->resolve();

            if ($Result instanceof Response) {
               $resolved = true;
               if ($Result !== $Response) {
                  $Response = $Result;
               }
            }
         }

         if ($resolved === false) {
            // @ Defensive: Middlewares pipeline may not have been initialized yet
            //   (e.g. when trailing bytes from a previous test connection arrive
            //   after @test end but before SAPI::boot() has rebuilt the pipeline).
            if ( ! isset(SAPI::$Middlewares)) {
               SAPI::$Middlewares = new Middlewares;
            }
            if ( ! isset(SAPI::$Handler)) {
               $Response = new Response(code: 503, body: '');
            }
            else {
               $Result = SAPI::$Middlewares->process($Request, $Response,
                  function (object $Request, object $Res) use ($Router): mixed {
                     $Result = (SAPI::$Handler)($Request, $Res, $Router);

                     // @ Resolve Generator-based routing inside the pipeline
                     if ($Result instanceof Generator) {
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
         }
      }
      catch (Throwable $Throwable) {
         $Response = new Response(code: 500, body: ' ');

         Throwables::debug($Throwable);
      }
      finally {
         // ?: Check if Request Body is waiting data
         if ($Request->Body->waiting) {
            return '';
         }
         // ?: Check if Response is deferred (async Fiber)
         if ($Response->deferred) {
            return '';
         }

         // @ Connection management (RFC 9112 §9.3)
         if ($Request->closeConnection) {
            if ($Request->protocol === 'HTTP/1.1') {
               $Response->Header->set('Connection', 'close');
            }

            $Packages->closeAfterWrite = true;
         }

         // : Encode HTTP Response
         return $Response->encode($Packages, $length);
      }
   }
}
