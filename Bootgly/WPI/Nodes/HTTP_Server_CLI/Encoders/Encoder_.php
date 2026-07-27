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


use function explode;
use function implode;
use function is_array;
use function ltrim;
use function spl_object_id;
use function stripos;
use function strlen;
use function strncmp;
use function strpos;
use function strtolower;
use function substr;
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
   // # Admission snapshot of the response a replay would serve. Global
   //   middleware may still mutate that same object AFTER calling $Next —
   //   adding a per-request security header or nonce — and the stored wire
   //   knows nothing about it. Comparing these at the end tells replay from
   //   post-processing so the mutation is never silently dropped.
   private static string $admittedBody = '';
   /** @var array<string,string> */
   private static array $admittedFields = [];
   /** @var array<string,string> */
   private static array $admittedPrepared = [];
   /** @var array<int,string> */
   private static array $admittedQueued = [];
   /** @var array<string,true> */
   private static array $admittedMasked = [];
   private static string $admittedType = '';
   // # Whether this request's replay already restored the cached representation
   //   into the response (see adopt()).
   private static bool $adopted = false;
   // # Snapshot of the response as the HANDLER left it, taken before global
   //   middleware can post-process it. Only taken for responses that opted into
   //   the route cache, so ordinary traffic pays one property read.
   /** @var array<int,mixed> */
   private static array $handled = [];
   // # Whether global middleware post-processed the response after $Next.
   private static bool $mutated = false;
   // # Response fields the encoder owns or regenerates per response. When a
   //   mutated replay restores the cached representation, these must come from
   //   THIS response, never from the stored head.
   public const array OWNED = [
      'connection' => true,
      'content-length' => true,
      'date' => true,
      'keep-alive' => true,
      'server' => true,
      'transfer-encoding' => true,
   ];


   /**
    * Capture every response surface that affects serialization.
    *
    * Used twice per cached response: once as the handler leaves it, once at
    * emission. An inequality means global middleware post-processed the
    * response after `$Next`.
    *
    * @return array<int,mixed>
    */
   public static function capture (Response $Response): array
   {
      $Header = $Response->Header;

      return [
         $Response->Body->raw,
         $Header->fields,
         $Header->prepared,
         $Header->queued,
         $Header->masked,
         $Header->type,
         // ! `preset` is worker-persistent config, normally written once at
         //   boot — but it serializes into every response, so a middleware that
         //   writes one after $Next changes THIS response too. Omitting it let a
         //   genuine hit return the stored preset to a later request.
         $Header->preset,
      ];
   }

   /**
    * Restore a stored representation into the response a replay short-circuits.
    *
    * A replay skips the handler, so the response object carries no content of
    * its own. Any code that runs afterwards — global middleware returning from
    * `$Next`, a `Handled` listener — therefore reads an EMPTY body and appends
    * to nothing, and its mutation would either be dropped by the raw-wire
    * replay or serialized as a content-less response. Putting the cached
    * status-line-less head fields and body back first makes that code compose
    * with real content, exactly as it would after a live handler.
    *
    * Fields this response already carries always win; framing fields stay the
    * encoder's own. Public because `Encoder_Testing` runs the same contract.
    */
   public static function adopt (Response $Response, string $wire): void
   {
      $separator = strpos($wire, "\r\n\r\n");
      if ($separator === false) {
         return;
      }

      $Header = $Response->Header;
      $head = explode("\r\n", substr($wire, 0, $separator));
      unset($head[0]); // @ status line — this response owns its own

      // @@
      foreach ($head as $line) {
         $colon = strpos($line, ':');
         if ($colon === false) {
            continue;
         }

         $name = substr($line, 0, $colon);
         // ? Framing and per-response fields belong to THIS response
         if (isSet(self::OWNED[strtolower($name)])) {
            continue;
         }
         // ? Whatever this response already wrote owns the field
         if ($Header->get($name) !== '') {
            continue;
         }

         $Header->set($name, ltrim(substr($line, $colon + 1), ' '));
      }

      $Response(body: substr($wire, $separator + 4));

      self::$adopted = true;
   }

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
      self::$admittedBody = '';
      self::$admittedFields = [];
      self::$admittedPrepared = [];
      self::$admittedQueued = [];
      self::$admittedMasked = [];
      self::$admittedType = '';
      self::$adopted = false;
      self::$handled = [];
      self::$mutated = false;

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

                     // ! A global pipeline can run code after $Next. Restore the
                     //   representation BEFORE that code sees the response, so an
                     //   append composes with the cached body instead of
                     //   replacing it. With no pipeline nothing can mutate here,
                     //   and the hit stays a pure memcpy of the stored bytes.
                     if (SAPI::$Middlewares->count > 0) {
                        /** @var Response $Res */
                        self::adopt($Res, $wire);
                     }

                     $Header = $Res->Header;
                     self::$admittedBody = $Res->Body->raw;
                     self::$admittedFields = $Header->fields;
                     self::$admittedPrepared = $Header->prepared;
                     self::$admittedQueued = $Header->queued;
                     self::$admittedMasked = $Header->masked;
                     self::$admittedType = $Header->type;
                     return $Res;
                  }

                  $Router = Server::$Router;

                  // @ Warm-router fast path, still inside global middleware.
                  if ($Router->cached) {
                     $Result = $Router->resolve();
                     if ($Result instanceof Response) {
                        if ($Result->cache !== 0) {
                           self::$handled = self::capture($Result);
                        }

                        return $Result;
                     }
                  }

                  $Result = (SAPI::$Handler)($Request, $Res, $Router);

                  // ?: Handler returned a Response directly — short-circuit
                  if ($Result instanceof Response) {
                     if ($Result->cache !== 0) {
                        self::$handled = self::capture($Result);
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

                  if ($Res->cache !== 0) {
                     self::$handled = self::capture($Res);
                  }

                  return $Res;
               };

               $Result = SAPI::$Middlewares->process($Request, $Response, $core);

               if ($Result instanceof Response && $Result !== $Response) {
                  $Response = $Result;
               }

               // ! Compare HERE, not at emission: everything below this point —
               //   the Date preset, Connection handling, the Handled event — is
               //   the encoder's own response tail and would read as a mutation.
               // ! PHPStan cannot see the admission closure writing `$handled`.
               if (self::$handled !== []) { // @phpstan-ignore notIdentical.alwaysFalse
                  self::$mutated = self::$handled !== self::capture($Response); // @phpstan-ignore notIdentical.alwaysTrue
               }
            }
         }
      }
      catch (Throwable $Throwable) {
         self::$wire = null;
         self::$Admitted = null;
         self::$admittedBody = '';
         self::$admittedFields = [];
         self::$admittedPrepared = [];
         self::$admittedQueued = [];
         self::$admittedMasked = [];
         self::$admittedType = '';
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
         self::$wire !== null // @phpstan-ignore notIdentical.alwaysFalse, booleanAnd.alwaysFalse, booleanAnd.alwaysFalse, booleanAnd.alwaysFalse, booleanAnd.alwaysFalse, booleanAnd.alwaysFalse
         && self::$Admitted === $Response // @phpstan-ignore identical.alwaysFalse
         && $Response->code === 200
         && $Request->closeConnection === false
      ) {
         $Header = $Response->Header;

         // ?: Nothing touched the admitted response after $Next — the stored
         //   bytes ARE this response, so return them without re-encoding.
         if (
            $Response->Body->raw === self::$admittedBody
            && $Header->fields === self::$admittedFields
            && $Header->prepared === self::$admittedPrepared
            && $Header->queued === self::$admittedQueued
            && $Header->masked === self::$admittedMasked
            && $Header->type === self::$admittedType
         ) {
            $length = strlen(self::$wire);
            return self::$wire;
         }

         // @ Mutated after $Next. The middleware wants ITS version on the wire —
         //   restore the cached representation underneath it first, unless the
         //   admission path already did (see adopt()).
         // ! Same blind spot as `self::$wire` above: PHPStan cannot see the
         //   admission closure writing this flag.
         if (self::$adopted === false) { // @phpstan-ignore identical.alwaysTrue
            self::adopt($Response, self::$wire);
         }
      }

      // @ Encode HTTP Response
      $buffer = $Response->encode($Packages, $length);

      // ? Route response cache opt-in — store the built wire bytes
      if ($Response->cache !== 0) {
         // ? A response that global middleware post-processed after $Next is
         //   per-request by construction, not a shared representation: storing
         //   it would replay one request's nonce or body marker to every later
         //   client. Skipping the store is also what makes adopt() correct on
         //   the replay side — every stored entry is pure handler output, so a
         //   later request's mutation composes with it instead of duplicating
         //   the stored one.
         if (self::$mutated === false) { // @phpstan-ignore identical.alwaysTrue
            $Response->stash($buffer);
         }
         else {
            $Response->cache = 0;
         }
      }

      // :
      return $buffer;
   }
}
