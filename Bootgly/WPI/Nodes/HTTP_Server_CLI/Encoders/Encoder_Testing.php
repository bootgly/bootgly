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
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


class Encoder_Testing extends Encoders
{
   /**
    * @param int<0,max>|null $length
    * @param-out int<0,max>|null $length
    */
   public static function encode (Packages $Packages, null|int &$length): string
   {
      /** @var TCPPackages $Packages */
      $Request  = Server::$Request;

      // @ Skip handler consumption while waiting for request body (chunked)
      if ($Request->Body->waiting) {
         return '';
      }

      // @ Reset Response state (same as production Encoder_)
      Server::$Response->reset();
      // @ Instance new Router (per-test: each test defines different routes)
      Server::$Router = new Router;

      // @ Index-based dispatch: Request::decode() parsed & stripped the
      //   `X-Bootgly-Test` header and parked the index on SAPI. Install
      //   the handler at that exact slot (no FIFO pop) for order-
      //   independent tests. `null` → no-header fallback in SAPI::boot().
      $testIndex = SAPI::$testIndexHeader;
      SAPI::$testIndexHeader = null;
      // @ Reset SAPI
      SAPI::boot(reset: true, base: Server::class, key: 'response', testIndex: $testIndex);

      // @ Get callbacks
      $Response = &Server::$Response;
      $Router   = Server::$Router;

      // ! Bind per-request context (used by Response::defer() when needed).
      $Response->bind($Packages, $Packages->Connection->Socket);

      // ! Response
      // @
      try {
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
      catch (Throwable $Throwable) {
         $Response = new Response(code: 500, body: ' ');

         Throwables::debug($Throwable);
      }
      finally {
         // ?: Check if Response is deferred (async Fiber)
         if ($Response->deferred) {
            return '';
         }

         // @ Remove dynamic Headers
         $Response->Header->preset('Date', null);

         // @ Connection management (RFC 9112 §9.3)
         if ($Request->closeConnection) {
            if ($Request->protocol === 'HTTP/1.1') {
               $Response->Header->set('Connection', 'close');
            }

            // @ Skip actual connection close in test mode to preserve
            // the test runner's single persistent TCP connection.
            // closeAfterWrite is tested via compliance test 4.10/4.16.
         }

         // : Encode HTTP Response
         return $Response->encode($Packages, $length);
      }
   }
}
