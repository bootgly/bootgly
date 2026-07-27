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


use function is_array;
use function spl_object_id;
use function strlen;
use function stripos;
use function strncmp;
use Generator;
use Throwable;

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Events\Emitter;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Challenge;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Check;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


class Encoder_ extends Encoders
{
   // * Metadata
   // # Replay handoff — per-request, reset by encode(); written by the
   //   admission core so the worker-lifetime closure captures nothing.
   private static null|string $wire = null;
   private static null|Response $Admitted = null;


   /**
    * Fetch cacheable HTTP/1.1 wire after admission middleware has run.
    */
   private static function replay (Request $Request): null|string
   {
      if (
         Cache::$entries === []
         || isSet(Cache::$URIs[$Request->URI]) === false
         || $Request->closeConnection
         || $Request->protocol !== 'HTTP/1.1'
         || $Request->URI === Server::$health
         || strncmp($Request->URI, Challenge::PREFIX, 28) === 0
      ) {
         // ? The URI pre-gate keeps every never-cached route (the common
         //   case) at one set-membership test — the header reads and the
         //   key composition below only run for URIs that have stored.
         return null;
      }

      // ! Request header fields are lowercase-normalized by the decoder.
      $fields = $Request->headers;
      if (isSet($fields['cookie']) || isSet($fields['authorization'])) {
         return null;
      }

      // ? A client asking for `no-cache` is asking for the handler, not a
      //   replay (audit 2026-07-27 M5). Only that exact directive counts, so a
      //   `max-age`/`no-store` request still replays as before.
      $control = $fields['cache-control'] ?? null;
      if ($control !== null) {
         $directives = is_array($control) ? implode(',', $control) : $control;

         if (stripos($directives, 'no-cache') !== false) {
            return null;
         }
      }

      return Cache::fetch(Cache::compose($Request, Language::$roots !== []));
   }

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

      // ?: Do not route / run middleware while request body is incomplete.
      //   The decoder has already installed a per-connection body decoder;
      //   executing user code here would duplicate side effects when the
      //   body later completes. Keep this before Response::reset() so the
      //   incomplete-read path does the least possible work.
      if ($Request->Body->waiting) {
         return '';
      }

      // @ Locale — negotiated BEFORE the route-cache fetch so cached wire
      //   bytes vary by language; the unconditional assign doubles as the
      //   per-request reset (nothing leaks forward); the guards keep the
      //   cost at one static read when no catalogs are registered
      if (Language::$roots !== []) {
         Language::$locale = isSet($Request->headers['accept-language'])
            ? Language::negotiate($Request->languages, $Request->exclusions)
            : Language::$source;
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
      self::$wire = null;
      self::$Admitted = null;

      // @
      try {
         // ?: Built-in health endpoint (K8s probes) — dispatched before the
         //   middleware pipeline, so RateLimit/Authentication or any user
         //   middleware can never break a liveness/readiness check
         if (
            Server::$health !== null
            && $Request->URI === Server::$health
            && ($Request->method === 'GET' || $Request->method === 'HEAD')
         ) {
            Check::respond($Request, $Response);
         }
         // ?: Built-in ACME HTTP-01 responder (Auto-TLS) — same rationale as
         //   the health probe: a certificate validation can never be broken
         //   by user middlewares or router config. The rare URI prefix is
         //   checked first so ordinary responses never allocate a path list.
         else if (
            strncmp($Request->URI, Challenge::PREFIX, 28) === 0
            && Challenges::collect() !== []
            && ($Request->method === 'GET' || $Request->method === 'HEAD')
         ) {
            Challenge::respond($Request, $Response);
         }
         else {
            // @ Defensive: Middlewares pipeline may not have been initialized yet
            //   (e.g. when trailing bytes from a previous test connection arrive
            //   after @test end but before SAPI::boot() has rebuilt the pipeline).
            if ( ! isset(SAPI::$Middlewares)) {
               SAPI::$Middlewares = new Middlewares;
            }
            if ( ! isset(SAPI::$Handler)) {
               // ! Break the static-Response alias: the Catcher builds a fresh,
               //   resource-less Response for THIS request only — writing it
               //   through the reference would strip the worker's bound
               //   Response of its loaded resources for every later request
               $Errored = Catcher::respond($Request, Server::$Response, code: 503);
               unset($Response);
               $Response = $Errored;
            }
            else {
               // ! One admission-core closure per worker, not per request:
               //   it captures nothing — per-request state flows through the
               //   `self::$wire` / `self::$Admitted` statics reset above.
               static $core = null;
               $core ??= static function (object $Request, object $Res): mixed {
                  // ?: Cache replay is inside the global admission pipeline.
                  //   Route/group middleware routes never create entries, so
                  //   every security middleware decides before this lookup.
                  /** @var Request $Request */
                  /** @var Response $Res */
                  $wire = self::replay($Request);
                  if ($wire !== null) {
                     self::$wire = $wire;
                     self::$Admitted = $Res;
                     return $Res;
                  }

                  $Router = Server::$Router;

                  // @ Warm-router fast path, still inside global middleware.
                  if ($Router->cached) {
                     $Result = $Router->resolve();
                     if ($Result instanceof Response) {
                        return $Result;
                     }
                  }

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
               };

               $Result = SAPI::$Middlewares->process($Request, $Response, $core);

               if ($Result instanceof Response && $Result !== $Response) {
                  $Response = $Result;
               }
            }
         }
      }
      catch (Throwable $Throwable) {
         self::$wire = null;
         self::$Admitted = null;
         // ! Break the static-Response alias (see the 503 path above)
         // ? The Catcher can itself throw (Throwables::notify, content
         //   negotiation, error-page rendering). The response tail below is
         //   no longer a `finally`, so there is nothing left to swallow that
         //   throwable — and nothing between here and Select::loop catches,
         //   so it would kill the worker. Degrade to a bare 500 instead: a
         //   failing Catcher costs one response, never the whole worker.
         try {
            $Errored = Catcher::respond($Request, Server::$Response, $Throwable);
         }
         catch (Throwable) {
            $Errored = new Response(code: 500, body: '');
         }
         unset($Response);
         $Response = $Errored;
      }

      // ---

      // ! The response tail is deliberately straight-line code and NOT a
      //   `finally` block. `finally` compiles to ZEND_FAST_CALL/ZEND_FAST_RET,
      //   which the tracing JIT cannot record: the root trace aborted on every
      //   attempt and, after `opcache.jit_blacklist_root_trace` (16) tries,
      //   this op_array — the framework's hottest PHP function — was
      //   blacklisted and ran fully interpreted for the worker's lifetime.
      //   Measured on PHP 8.4 (CRTO 1254): 16 trace starts, 0 stops for a
      //   function returning through `finally`; the straight-line shape needs
      //   no root trace of its own at all.
      //   The equivalence holds because the `try` above contains no
      //   function-level `return` (those live inside the `$core` closure, a
      //   separate op_array) and the `catch` is total — so control always
      //   arrives here exactly once, which is what `finally` guaranteed.

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

      // ?: Replay only when the admitted Response remains the active 200.
      //   A post-middleware/event denial or replacement must serialize its
      //   own response instead of reviving the cached success wire.
      // ! PHPStan cannot see that the admission-core closure (invoked by
      //   `Middlewares::process` above) writes `self::$wire`/`$Admitted`,
      //   so it narrows both to their pre-try null resets.
      if (
         self::$wire !== null // @phpstan-ignore notIdentical.alwaysFalse, booleanAnd.alwaysFalse, booleanAnd.alwaysFalse, booleanAnd.alwaysFalse
         && self::$Admitted === $Response // @phpstan-ignore identical.alwaysFalse
         && $Response->code === 200
         && $Request->closeConnection === false
      ) {
         $length = strlen(self::$wire);
         return self::$wire;
      }

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
