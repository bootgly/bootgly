<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;

use function implode;
use function is_array;
use function stripos;
use function strlen;
use function strncmp;
use function strtok;
use Generator;
use Throwable;

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Events\Emitter;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Events\Cancellation;
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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry\Admissions;

class Encoder_Testing extends Encoders
{
   /**
    * Fetch cacheable HTTP/1.1 wire after admission middleware has run.
    */
   private static function replay (Request $Request): null|string
   {
      if (
         Cache::$entries === []
         || $Request->closeConnection
         || $Request->protocol !== 'HTTP/1.1'
         || $Request->URI === Server::$health
         || strncmp($Request->URI, Challenge::PREFIX, 28) === 0
      ) {
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
    * @param int<0,max>|null $length
    * @param-out int<0,max>|null $length
    */
   public static function encode (Packages $Packages, null|int &$length): string
   {
      /** @var TCPPackages $Packages */
      $Request = Server::$Request;
      $Response = &Server::$Response;
      // ! Mirror production: hold the injected response by value, since the
      //   alias above is overwritten by a handler-returned replacement.
      $Injected = $Response;
      static $guarded = false;

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

      // @ Public event — request fully decoded (no Emission when unobserved)
      $Emitter = Emitter::$Instance;
      $receiving = $Emitter->check(RequestEvents::Received);
      if ($Response->deferred || $Response->cacheable === false) {
         Exchange::release($Request);
      }
      $Exchange = null;
      if ($receiving) {
         $Exchange = Admissions::open($Emitter, $Request);
         if ($Exchange === null) {
            $Exchange = new Exchange;
            Exchange::admit($Request, $Exchange);
         }
         Exchange::bind($Response, $Packages, $Request, $Exchange);
         try {
            $Response->guard();
         }
         finally {
            Exchange::capture($Response);
         }

         // ! Mirror production: protect the pre-reset singleton and invalidate
         //   older wire before Received can persist current-request output.
         if ($guarded === false) {
            Cache::flush();
            $guarded = true;
         }

         try {
            $Emitter->emit(RequestEvents::Received, $Request);
         }
         catch (Throwable $Throwable) {
            $Exchange->finish(null);
            throw $Throwable;
         }
      }

      // @ Instance new Router (per-test: each test defines different routes)
      Server::$Router = new Router;

      // @ Index-based dispatch: Request::decode() parsed & stripped the
      //   `X-Bootgly-Test` header and parked the index on SAPI. Install
      //   the handler at that exact slot (no FIFO pop) for order-
      //   independent tests. `null` → no-header fallback in SAPI::boot().
      $testIndex = SAPI::$testIndexHeader;
      SAPI::$testIndexHeader = null;
      // @ Reset SAPI
      try {
         SAPI::boot(reset: true, base: Server::class, key: 'response', testIndex: $testIndex);
      }
      catch (Throwable $Throwable) {
         $Exchange?->finish(null);
         throw $Throwable;
      }

      // @ Get callbacks
      $Router   = Server::$Router;

      // ! Reset Response state and bind per-request context.
      try {
         $Response->reset($Packages, $Request);
      }
      catch (Throwable $Throwable) {
         $Exchange?->finish(null);
         throw $Throwable;
      }
      $cacheWire = null;
      $CachedResponse = null;
      // ! Admission snapshot — production tells replay from post-$Next
      //   mutation the same way; without it this encoder would serve a stale
      //   representation to every case the suite runs through it.
      $admitted = [];
      $adopted = false;
      $handled = [];
      $mutated = false;
      $observed = $Emitter->check(RequestEvents::Handled);
      $Middlewares = SAPI::$Middlewares;
      $mediated = $receiving
         || $Middlewares::class !== Middlewares::class
         || $Middlewares->count > 0
         || $observed;

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
            if ($mediated) {
               $Response->guard();
               if ($guarded === false) {
                  Cache::flush();
                  $guarded = true;
               }
            }
            else {
               $guarded = false;
            }

            $Result = $Middlewares->process($Request, $Response,
               function (object $Request, object $Res) use (
                  $Router,
                  $mediated,
                  &$cacheWire,
                  &$CachedResponse,
                  &$admitted,
                  &$handled,
               ): mixed {
                  // ?: Mirror production: every global middleware runs before
                  //   replay; route/group middleware routes never seed entries.
                  /** @var Request $Request */
                  /** @var Response $Res */
                  if ($mediated) {
                     $Res->guard();
                  }

                  $cacheWire = $mediated
                     ? null
                     : self::replay($Request);
                  if ($cacheWire !== null) {
                     $CachedResponse = $Res;
                     $admitted = Encoder_::capture($Res);
                     return $Res;
                  }

                  $Result = (SAPI::$Handler)($Request, $Res, $Router);

                  // ?: Handler returned a Response directly — short-circuit
                  if ($Result instanceof Response) {
                     if ($Result->cache !== 0) {
                        $handled = Encoder_::capture($Result);
                     }

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

                  /** @var Response $Res */
                  if ($Res->cache !== 0) {
                     $handled = Encoder_::capture($Res);
                  }

                  return $Res;
               }
            );

            if ($Result instanceof Response && $Result !== $Response) {
               $Response = $Result;
            }
            if ($mediated) {
               $Response->guard();
            }

            // ! Same boundary as production: compare before the response tail.
            if ($handled !== []) {
               if ($handled !== Encoder_::capture($Response)) {
                  $mutated = true;
               }

            }
         }
      }
      catch (Throwable $Throwable) {
         $cacheWire = null;
         $CachedResponse = null;
         $admitted = [];
         // ! Break the static-Response alias: the Catcher builds a fresh,
         //   resource-less Response for THIS request only — writing it
         //   through the reference would strip the persistent test worker's
         //   bound Response of its loaded resources (mirrors Encoder_)
         // ? The Catcher can itself throw — degrade to a bare 500 instead of
         //   letting it escape (mirrors Encoder_; the tail below is no longer
         //   a `finally`, so nothing would swallow it).
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

      // ! Straight-line response tail, NOT a `finally` — mirrors Encoder_,
      //   where `finally` made the op_array untraceable by the tracing JIT
      //   (ZEND_FAST_CALL/ZEND_FAST_RET) and got it blacklisted. Kept
      //   structurally identical here so both encoders stay comparable.
      //   Equivalent because the `try` holds no function-level `return` (they
      //   live inside the admission closure) and the `catch` is total.

      if (
         $Exchange === null
         && (
            $Response->deferred
            || $Response->cacheable === false
            || $Injected->cacheable === false
         )
      ) {
         $Exchange = self::resolve($Response, $Injected, $Request);
      }

      // @ Persist the session before the response leaves the server —
      //   mirrors Encoder_ (deterministic save; __destruct is GC-bound).
      if ($Request->sessioned) {
         try {
            $Request->Session?->save();
         }
         catch (Throwable $Throwable) {
            if ($Exchange?->check()) {
               Throwables::notify($Throwable, [
                  'interface' => 'WPI',
                  'phase' => 'Session',
                  'method' => $Request->method,
                  'URI' => strtok($Request->URI, '?'),
                  'peer' => $Request->peer,
               ]);
               if ($Request->hasFiles) {
                  $Request->clean();
               }
               $Request->scrub();

               return '';
            }
            $Exchange?->finish(null);
            throw $Throwable;
         }
      }

      // ?: Routing may have emitted through a cloned Response or SSE resource.
      if ($Exchange?->check()) {
         if ($Request->hasFiles) {
            $Request->clean();
         }
         $Request->scrub();

         return '';
      }

      // ?: Mirror production selection across the active Response and sibling
      //   clone generation carried by the stable Exchange token.
      $Cancellation = $Exchange === null ? null : Cancellation::fetch($Exchange);
      if ($Response->deferred && $Cancellation === null) {
         $Exchange?->finish(null);
      }
      if (
         $Response->deferred
         || ($Exchange?->check() ?? false)
         || $Cancellation !== null
      ) {
         if ($Request->hasFiles) {
            $Request->clean();
         }
         $Request->scrub();

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
      // ! A handler/middleware may have registered Handled after admission.
      $handling = $Emitter->check(RequestEvents::Handled);
      if ($handling) {
         if (
            $observed === false
            && $cacheWire !== null
            && $CachedResponse === $Response
         ) {
            Encoder_::adopt($Response, $cacheWire);
            $adopted = true;
         }

         $mediated = true;
         if ($guarded === false) {
            Cache::flush();
            $guarded = true;
         }
         // ! Guard before user event code can clone/defer this Response.
         $Response->guard();
         $before = Encoder_::capture($Response);
         try {
            $Emitter->emit(RequestEvents::Handled, $Request, $Response);
         }
         catch (Throwable $Throwable) {
            if (
               $Exchange === null
               && (
                  $Response->deferred
                  || $Response->cacheable === false
                  || $Injected->cacheable === false
               )
            ) {
               $Exchange = self::resolve($Response, $Injected, $Request);
            }
            $Cancellation = $Exchange === null ? null : Cancellation::fetch($Exchange);
            if (
               $Response->deferred
               || ($Exchange?->check() ?? false)
               || $Cancellation !== null
            ) {
               Throwables::notify($Throwable, [
                  'interface' => 'WPI',
                  'phase' => 'Handled',
                  'method' => $Request->method,
                  'URI' => strtok($Request->URI, '?'),
                  'peer' => $Request->peer,
               ]);
            }
            else {
               $Exchange?->finish(null);
               throw $Throwable;
            }
         }

         if ($before !== Encoder_::capture($Response)) {
            $mutated = true;
         }
      }

      // ?: Handled can hand output to async work or emit it immediately.
      if (
         $Exchange === null
         && (
            $Response->deferred
            || $Response->cacheable === false
            || $Injected->cacheable === false
         )
      ) {
         $Exchange = self::resolve($Response, $Injected, $Request);
      }
      $Cancellation = $Exchange === null ? null : Cancellation::fetch($Exchange);
      if ($Response->deferred && $Cancellation === null) {
         $Exchange?->finish(null);
      }
      if (
         $Response->deferred
         || ($Exchange?->check() ?? false)
         || $Cancellation !== null
      ) {
         if ($Request->hasFiles) {
            $Request->clean();
         }
         $Request->scrub();

         return '';
      }

      // ?: A post-middleware/event denial or replacement wins over replay.
      if (
         $cacheWire !== null
         && $CachedResponse === $Response
         && $Response->code === 200
         && $Request->closeConnection === false
      ) {
         $Header = $Response->Header;

         // ?: Nothing touched the admitted response after $Next.
         if ($admitted === Encoder_::capture($Response)) {
            $length = strlen($cacheWire);
            $Exchange?->finish($Response);
            return $cacheWire;
         }

         // @ Mutated after $Next — restore underneath the mutation unless the
         //   admission path already did.
         try {
            if ($adopted === false) {
               Encoder_::adopt($Response, $cacheWire);
            }
         }
         catch (Throwable $Throwable) {
            $Exchange?->finish(null);
            throw $Throwable;
         }
      }

      try {
         // @ Encode HTTP Response
         $buffer = $Response->encode($Packages, $length);

         // ? Route response cache opt-in — only unmediated handler wire is shared.
         if ($Response->cache !== 0) {
            if ($mediated === false && $mutated === false) {
               $Response->stash($buffer);
            }
            else {
               $Response->cache = 0;
            }
         }
      }
      catch (Throwable $Throwable) {
         $Exchange?->finish(null);
         throw $Throwable;
      }

      $Exchange?->finish($Response);

      // :
      return $buffer;
   }
}
