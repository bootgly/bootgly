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


use function strlen;
use Generator;
use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Events\Emitter;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
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

      // ?: Route response cache — serve stored wire bytes before routing,
      //   middleware, handler and serialization (mirrors Encoder_ so E2E
      //   specs exercise the same hit path as production).
      if (Cache::$entries !== [] && $Request->closeConnection === false) {
         $wire = Cache::fetch("{$Request->method}\0{$Request->URI}");

         if ($wire !== null) {
            // ! Request header fields are lowercase-normalized by the decoder
            $fields = $Request->headers;

            if (isSet($fields['cookie']) === false && isSet($fields['authorization']) === false) {
               $length = strlen($wire);

               // :
               return $wire;
            }
         }
      }

      // @ Events — request fully decoded (guarded: zero-alloc when no listeners)
      $Emitter = Emitter::$Instance;
      $Emitter->check(RequestEvents::Received) && $Emitter->emit(RequestEvents::Received, $Request);

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

      // ! Reset Response state and bind per-request context.
      $Response->reset($Packages, $Request);

      // ! Response
      // @
      try {
         $Result = SAPI::$Middlewares->process($Request, $Response,
            function (object $Request, object $Res) use ($Router): mixed {
               $Result = (SAPI::$Handler)($Request, $Res, $Router);

               // ?: Handler returned a Response directly — short-circuit
               if ($Result instanceof Response) {
                  return $Result;
               }

               // @ Resolve through the cache (handler may have yielded a Generator
               //   of routes, or registered routes via direct $Router->route() calls)
               $Routes = $Result instanceof Generator ? $Result : null;
               foreach ($Router->routing($Routes) as $Responses) {
                  if ($Responses instanceof Response) {
                     $Res = $Responses;
                  }
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
         // @ Persist the session before the response leaves the server —
         //   mirrors Encoder_ (deterministic save; __destruct is GC-bound).
         if ($Request->sessioned) {
            $Request->Session?->save();
         }

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

         // @ Per-request file cleanup (replaces Request::__destruct)
         if ($Request->hasFiles) {
            $Request->clean();
         }

         // @ Events — request handled, response ready (guarded: zero-alloc when no listeners)
         $Emitter->check(RequestEvents::Handled) && $Emitter->emit(RequestEvents::Handled, $Request, $Response);

         // @ Encode HTTP Response
         $buffer = $Response->encode($Packages, $length);

         // ? Route response cache opt-in — store the built wire bytes
         if ($Response->cache !== 0) {
            $Response->stash($buffer);
         }

         // :
         return $buffer;
      }
   }
}
