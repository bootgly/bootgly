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


use function explode;
use function implode;
use function is_array;
use function ltrim;
use function preg_match;
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
   /** @var array<string,string|true> */
   private static array $admittedPreset = [];
   private static int $admittedCode = 200;
   private static string $admittedHints = '';
   private static bool $admittedStream = false;
   private static bool $admittedChunked = false;
   private static bool $admittedEncoded = false;
   // # Whether this request's replay already restored the cached representation
   //   into the response (see adopt()).
   private static bool $adopted = false;
   // # Snapshot of the response as the HANDLER left it, taken before global
   //   middleware can post-process it. Only taken for responses that opted into
   //   the route cache, so ordinary traffic pays one property read.
   /** @var array<int,mixed> */
   private static array $handled = [];
   // # Whether lifecycle code changed the response before/after $Next or in
   //   the synchronous Handled event.
   private static bool $mutated = false;
   // # Whether the same Emitter instance used by this request has a Handled
   //   listener. A hit must be materialized before that listener observes it.
   private static bool $observed = false;
   // # Immutable admission policy for this request. Raw cached wire cannot
   //   preserve generic middleware/event ownership, so either lifecycle makes
   //   both replay and storage fail closed.
   private static bool $mediated = false;
   // # Whether the worker cache was invalidated for the current mediated
   //   configuration. Kept across requests so a stable stack pays one flush.
   private static bool $guarded = false;
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
    * Snapshots delimit Received, global middleware, handler and Handled-event
    * ownership.
    * An inequality at one of those boundaries means the representation carries
    * request-lifecycle output and cannot be stored as shared wire.
    *
    * @return array<int,mixed>
    */
   public static function capture (Response $Response): array
   {
      $Header = $Response->Header;

      return [
         $Response->code,
         $Response->Body->raw,
         $Header->fields,
         $Header->prepared,
         $Header->queued,
         $Header->masked,
         $Header->type,
         $Response->hints,
         $Response->stream,
         $Response->chunked,
         $Response->encoded,
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
    * its own. Global middleware returning from `$Next` would therefore read an
    * EMPTY body and append to nothing, and its mutation would either be dropped
    * by the raw-wire replay or serialized as a content-less response. Putting
    * the cached status-line-less head fields and body back first makes that code
    * compose with real content, exactly as it would after a live handler.
    *
    * Fields this response already carries always win; framing fields stay the
    * encoder's own. Public because `Encoder_Testing` runs the same contract.
    */
   public static function adopt (Response $Response, string $wire): void
   {
      // ! A cache entry may begin with one or more informational responses
      //   emitted by Response::hint(). Only the following final response owns
      //   the mutable Header/Body representation. Splitting at the first empty
      //   line promotes a 103 Link into the final head and embeds the complete
      //   cached 200 wire in a new outer response (audit M4).
      $offset = 0;
      while (true) {
         $separator = strpos($wire, "\r\n\r\n", $offset);
         if ($separator === false) {
            return;
         }

         $statusEnd = strpos($wire, "\r\n", $offset);
         if ($statusEnd === false || $statusEnd > $separator) {
            return;
         }

         $status = substr($wire, $offset, $statusEnd - $offset);
         if (
            preg_match(
               '/\AHTTP\/1\.1 ([0-9]{3})(?: [^\r\n]*)?\z/D',
               $status,
               $matches
            ) !== 1
         ) {
            return;
         }

         $code = (int) $matches[1];
         if ($code < 100 || $code >= 200) {
            break;
         }

         // ! 101 is a terminal protocol switch, never an interim block before
         //   an HTTP final response. Route-cache adoption cannot represent it.
         if ($code === 101) {
            return;
         }

         $offset = $separator + 4;
      }

      // ? stash() admits only final 200 responses. A different code means the
      //   cache wire is malformed or came from an incompatible producer.
      if ($code !== 200) {
         return;
      }

      $Header = $Response->Header;
      $head = explode("\r\n", substr($wire, $offset, $separator - $offset));
      unset($head[0]); // @ status line — this response owns its own
      $fields = [];
      $contentLength = null;

      // ! Validate the complete final representation before mutating Response.
      //   Route-cache wire is a buffered GET 200 and therefore owns exactly one
      //   canonical Content-Length. A truncated/concatenated or incompatible
      //   entry fails closed without partially importing its fields.
      foreach ($head as $line) {
         $colon = strpos($line, ':');
         if ($colon === false) {
            return;
         }

         $name = substr($line, 0, $colon);
         $value = ltrim(substr($line, $colon + 1), " \t");
         if (
            preg_match("/\A[!#\$%&'*+.^_`|~0-9A-Za-z-]+\z/D", $name) !== 1
            || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1
         ) {
            return;
         }

         $lower = strtolower($name);
         if ($lower === 'transfer-encoding' || $lower === 'set-cookie') {
            return;
         }
         if ($lower === 'content-length') {
            if (
               $contentLength !== null
               || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            ) {
               return;
            }

            $contentLength = $value;
         }

         $fields[] = [$name, $value];
      }

      $body = substr($wire, $separator + 4);
      if (
         $contentLength === null
         || $contentLength !== (string) strlen($body)
      ) {
         return;
      }

      // @@
      foreach ($fields as [$name, $value]) {
         // ? Framing and per-response fields belong to THIS response
         if (isSet(self::OWNED[strtolower($name)])) {
            continue;
         }
         // ? Whatever this response already wrote owns the field
         if ($Header->get($name) !== '') {
            continue;
         }

         $Header->set($name, $value);
      }

      $Response(body: $body);

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
      $receiving = isSet($Emitter->Listeners[$received]);
      if ($receiving) {
         // ! Received runs before reset(), and listeners can reach the
         //   worker-persistent Response singleton. Guard that pre-reset object
         //   and invalidate older wire before user code can clone/stash it or
         //   change a persistent Header preset from current-request input.
         $Response->guard();
         if (self::$guarded === false) {
            Cache::flush();
            self::$guarded = true;
         }

         $Emitter->emit(RequestEvents::Received, $Request);
      }

      // @ Reset Response state and bind per-request context.
      $Response->reset($Packages, $Request);
      // ? Every path that writes replay/capture state leaves a reset root set;
      //   the catch below restores the same invariant on exceptions.
      if (
         self::$wire !== null
         || self::$handled !== []
         || self::$observed
         || self::$mediated
      ) {
         self::$wire = null;
         self::$Admitted = null;
         self::$admittedBody = '';
         self::$admittedFields = [];
         self::$admittedPrepared = [];
         self::$admittedQueued = [];
         self::$admittedMasked = [];
         self::$admittedType = '';
         self::$admittedPreset = [];
         self::$admittedCode = 200;
         self::$admittedHints = '';
         self::$admittedStream = false;
         self::$admittedChunked = false;
         self::$admittedEncoded = false;
         self::$adopted = false;
         self::$handled = [];
         self::$mutated = false;
         self::$observed = false;
         self::$mediated = false;
      }
      self::$observed = isSet($Emitter->Listeners[$handled]);

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
               // ! Raw final-response wire has no generic operation/source
               //   model for Received, middleware or Handled output. Snapshot this
               //   request's lifecycle before entering the onion and decline
               //   both replay and storage. The Response flag survives
               //   defer()'s private clone and protects its later stash().
               $Middlewares = SAPI::$Middlewares;
               self::$mediated = $receiving
                  // ! A subtype can override process() while its inherited
                  //   private stack (and count) stays empty. Treat every subtype
                  //   as lifecycle-bearing instead of trusting that divergence.
                  || $Middlewares::class !== Middlewares::class
                  || $Middlewares->count > 0
                  || self::$observed;
               if (self::$mediated) {
                  $Response->guard();

                  // ! Entries primed before a lifecycle/preset transition
                  //   cannot safely resurrect after that lifecycle is removed.
                  if (self::$guarded === false) {
                     Cache::flush();
                     self::$guarded = true;
                  }
               }
               else {
                  self::$guarded = false;
               }

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
                  // ! A middleware may pass a replacement Response into
                  //   $Next. Guard that exact object before the handler can
                  //   clone/defer it, not only the pipeline's original object.
                  if (self::$mediated) {
                     $Res->guard();
                  }

                  $wire = self::$mediated
                     ? null
                     : self::replay($Request);
                  if ($wire !== null) {
                     self::$wire = $wire;
                     self::$Admitted = $Res;

                     $Header = $Res->Header;
                     self::$admittedBody = $Res->Body->raw;
                     self::$admittedFields = $Header->fields;
                     self::$admittedPrepared = $Header->prepared;
                     self::$admittedQueued = $Header->queued;
                     self::$admittedMasked = $Header->masked;
                     self::$admittedType = $Header->type;
                     self::$admittedPreset = $Header->preset;
                     self::$admittedCode = $Res->code;
                     self::$admittedHints = $Res->hints;
                     self::$admittedStream = $Res->stream;
                     self::$admittedChunked = $Res->chunked;
                     self::$admittedEncoded = $Res->encoded;
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

               $Result = $Middlewares->process($Request, $Response, $core);

               if ($Result instanceof Response && $Result !== $Response) {
                  $Response = $Result;
               }
               if (self::$mediated) {
                  $Response->guard();
               }

               // ! Compare HERE, not at emission: connection framing below is
               //   encoder-owned. This remains defense in depth for the
               //   unmediated path; a mediated response never stores at all.
               // ! PHPStan cannot see the admission closure writing `$handled`.
               if (self::$handled !== []) { // @phpstan-ignore notIdentical.alwaysFalse
                  if (self::$handled !== self::capture($Response)) { // @phpstan-ignore notIdentical.alwaysTrue
                     self::$mutated = true;
                  }

               }
            }
         }
      }
      catch (Throwable $Throwable) {
         // ! Full snapshot reset — the guarded reset at the next encode() only
         //   fires while one of the two roots is set, so the exception path
         //   must not leave a flag stranded behind cleared roots.
         self::$wire = null;
         self::$Admitted = null;
         self::$admittedBody = '';
         self::$admittedFields = [];
         self::$admittedPrepared = [];
         self::$admittedQueued = [];
         self::$admittedMasked = [];
         self::$admittedType = '';
         self::$admittedPreset = [];
         self::$admittedCode = 200;
         self::$admittedHints = '';
         self::$admittedStream = false;
         self::$admittedChunked = false;
         self::$admittedEncoded = false;
         self::$adopted = false;
         self::$handled = [];
         self::$mutated = false;
         self::$observed = false;
         self::$mediated = false;
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
         // @ The Fiber works on the deep copy taken by `Request::capture()`
         //   (`Request::__clone` clones the Body), so the live payload is
         //   already redundant here — and it would otherwise sit on the
         //   connection until the next request, unreserved. Same rationale
         //   as the post-`Handled` scrub below.
         $Request->Body->scrub();
         $Request->fields = [];

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
      // ! Re-read the same Emitter: handlers/middleware may register a
      //   listener after admission. A late listener cannot influence the
      //   earlier replay decision, so materialize a hit before it observes it.
      $observed = isSet($Emitter->Listeners[$handled]);
      if ($observed) {
         if (
            self::$observed === false // @phpstan-ignore booleanAnd.alwaysFalse, booleanAnd.alwaysFalse, booleanAnd.alwaysFalse
            && self::$wire !== null // @phpstan-ignore notIdentical.alwaysFalse
            && self::$Admitted === $Response // @phpstan-ignore identical.alwaysFalse
            && self::$adopted === false
         ) {
            self::adopt($Response, self::$wire);
         }

         self::$observed = true;
         self::$mediated = true;
         if (self::$guarded === false) {
            Cache::flush();
            self::$guarded = true;
         }
         // ! Guard before user event code runs: a listener can clone/defer the
         //   Response and call stash() during the synchronous emission.
         $Response->guard();
         $before = self::capture($Response);
         $Emitter->emit(RequestEvents::Handled, $Request, $Response);

         // ! Handled runs after the middleware snapshot and has no cache-safe
         //   declaration contract. Its current-request output reaches this
         //   response, but the lifecycle is never eligible for shared wire.
         if ($before !== self::capture($Response)) {
            self::$mutated = true;
         }
      }

      // @ End of the synchronous request cycle: every consumer allowed to read
      //   the body has run (handler, middlewares, `Handled` listeners), and
      //   nothing below reads it — replay compares Response state, `encode()`
      //   only reads `method`/`protocol`/`stream`, and `stash()` stores wire
      //   bytes. Drop the payload now instead of leaving it for the NEXT
      //   request's `reset()`/`assume()` to overwrite: the decoders release
      //   their reservation the moment a body completes, so between those two
      //   points a completed body was resident with nothing accounting for it
      //   and an idle keep-alive peer could park memory the ceiling in
      //   `Decoders\Bodies` never saw. This also unpins the body from the
      //   worker-global `Server::$Request`, which nothing else ever clears.
      // !! KEEP THIS STRAIGHT-LINE AND UNCONDITIONAL — same tracing-JIT
      //   constraint as `Request::__clone` / `Request::reset`.
      $Request->Body->scrub();
      $Request->fields = [];

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
            && $Header->preset === self::$admittedPreset
            && $Response->code === self::$admittedCode
            && $Response->hints === self::$admittedHints
            && $Response->stream === self::$admittedStream
            && $Response->chunked === self::$admittedChunked
            && $Response->encoded === self::$admittedEncoded
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
         // ! Any mediated lifecycle is per-request by policy. Raw final wire
         //   cannot retain source/precedence ownership for Received listeners,
         //   generic middleware or Handled output, so only an unmediated
         //   handler representation may enter the shared route cache.
         if (
            self::$mediated === false // @phpstan-ignore identical.alwaysTrue, booleanAnd.alwaysFalse
            && self::$mutated === false // @phpstan-ignore identical.alwaysTrue
         ) {
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
