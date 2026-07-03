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


use function spl_object_id;
use function strlen;
use Generator;
use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Events\Emitter;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


class Encoder_ extends Encoders
{
   /**
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
    */
   public static function encode (Packages $Packages, null|int &$length): string
   {
      /** @var TCPPackages $Packages */
      // @ Get callbacks
      $Request  = Server::$Request;
      $Response = &Server::$Response;
      $Router   = Server::$Router;

      // ?: Do not route / run middleware while request body is incomplete.
      //   The decoder has already installed a per-connection body decoder;
      //   executing user code here would duplicate side effects when the
      //   body later completes. Keep this before Response::reset() so the
      //   incomplete-read path does the least possible work.
      if ($Request->Body->waiting) {
         return '';
      }

      // ?: Route response cache — serve stored wire bytes before routing,
      //   middleware, handler and serialization (empty-store check keeps the
      //   cost at one static array read for servers that never opt in).
      //   Guards mirror stash(): plain keep-alive GET, no credentials.
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
      // ! Direct Listeners read instead of check(): the call frame +
      //   Event&UnitEnum intersection-type check cost ~9% of worker CPU
      //   at 600k req/s. Enum-case object ids are stable per process.
      static $received = null, $handled = null;
      $received ??= spl_object_id(RequestEvents::Received);
      $handled ??= spl_object_id(RequestEvents::Handled);

      $Emitter = Emitter::$Instance;
      isSet($Emitter->Listeners[$received]) && $Emitter->emit(RequestEvents::Received, $Request);

      // @ Reset Response state and bind per-request context.
      $Response->reset($Packages, $Request);

      // @
      try {
         // @ Fast path: resolve from route cache (bypass Generator entirely)
         $Result = $Router->cached ? $Router->resolve() : null;
         if ($Result instanceof Response) {
            if ($Result !== $Response) {
               $Response = $Result;
            }
         }
         else {
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
         }
      }
      catch (Throwable $Throwable) {
         $Response = new Response(code: 500, body: ' ');

         Throwables::debug($Throwable);
      }
      finally {
         // @ Persist the session before the response leaves the server —
         //   __destruct timing is GC-bound (reference cycles can defer it
         //   past subsequent requests), so save explicitly per request.
         if ($Request->sessioned) {
            $Request->Session?->save();
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

         // @ Per-request file cleanup (replaces Request::__destruct)
         //   Gated to avoid a method frame when no uploads exist.
         if ($Request->hasFiles) {
            $Request->clean();
         }

         // @ Events — request handled, response ready (guarded: zero-alloc when no listeners)
         isSet($Emitter->Listeners[$handled]) && $Emitter->emit(RequestEvents::Handled, $Request, $Response);

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
