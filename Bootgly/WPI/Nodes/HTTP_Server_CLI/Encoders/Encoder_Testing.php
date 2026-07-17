<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;


use function strlen;
use function strncmp;
use Generator;
use Throwable;

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Events\Emitter;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Challenge;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Check;
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

      // @ Locale — negotiated BEFORE the route-cache fetch so cached wire
      //   bytes vary by language; the unconditional assign doubles as the
      //   per-request reset (mirrors Encoder_)
      if (Language::$roots !== []) {
         Language::$locale = isSet($Request->headers['accept-language'])
            ? Language::negotiate($Request->languages, $Request->exclusions)
            : Language::$source;
      }

      // ?: Route response cache — serve stored wire bytes before routing,
      //   middleware, handler and serialization (mirrors Encoder_ so E2E
      //   specs exercise the same hit path as production). Only HTTP/1.1
      //   reads entries and the built-in health path never reads the cache;
      //   the reserved ACME HTTP-01 namespace never reads it either
      //   (mirrors Encoder_).
      if (
         Cache::$entries !== [] && $Request->closeConnection === false
         && $Request->protocol === 'HTTP/1.1'
         && $Request->URI !== Server::$health
         && strncmp($Request->URI, Challenge::PREFIX, 28) !== 0
      ) {
         $wire = Cache::fetch(Cache::compose($Request, Language::$roots !== []));

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
         // ?: Built-in health endpoint (K8s probes) — dispatched before the
         //   middleware pipeline (mirrors Encoder_)
         if (
            Server::$health !== null
            && $Request->URI === Server::$health
            && ($Request->method === 'GET' || $Request->method === 'HEAD')
         ) {
            Check::respond($Request, $Response);
         }
         // ?: Built-in ACME HTTP-01 responder (Auto-TLS) — mirrors Encoder_
         else if (
            strncmp($Request->URI, Challenge::PREFIX, 28) === 0
            && Challenges::collect() !== []
            && ($Request->method === 'GET' || $Request->method === 'HEAD')
         ) {
            Challenge::respond($Request, $Response);
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
      catch (Throwable $Throwable) {
         // ! Break the static-Response alias: the Catcher builds a fresh,
         //   resource-less Response for THIS request only — writing it
         //   through the reference would strip the persistent test worker's
         //   bound Response of its loaded resources (mirrors Encoder_)
         $Errored = Catcher::respond($Request, Server::$Response, $Throwable);
         unset($Response);
         $Response = $Errored;
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
