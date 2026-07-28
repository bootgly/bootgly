<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use function array_key_exists;
use function array_key_first;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function strlen;
use function strpos;
use function substr_replace;
use function time;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


/**
 * Per-worker route response cache (L1).
 *
 * Stores fully built HTTP/1.1 wire bytes keyed by request method, exact
 * authority, target (path + query), forwarded selectors, and the supported
 * request variance for routes that opt in via
 * `Router->route(..., cache: <ttl>)`.
 * A hit is served from `Encoder_::encode()` before routing, middleware,
 * handler and serialization run — cached dynamic routes respond at
 * static-route speed.
 *
 * The store is a dumb TTL'd key→wire map: every request-side guard
 * (method, credentials, connection state) and response-side guard (status,
 * streaming/encoding state, cookies) lives at the call sites, which own the
 * Request/Response context.
 *
 * Scope is deliberately per-worker (static array): in-process reads are
 * nanosecond-scale, while any cross-worker store (SysV shm, Redis) costs
 * microseconds per request at the front of the hot path. Each worker warms
 * its own slots within one TTL window.
 */
class Cache
{
   // * Config
   /**
    * Max cached entries per worker (FIFO eviction).
    */
   public const int ENTRIES_LIMIT = 512;
   /**
    * Max cache key length — framed method, authority, target, raw forwarded
    * fields and the optional Accept-Language request selector.
    */
   public const int KEY_LIMIT = 2048;
   /**
    * Max stored wire size (status line + headers + body) per entry.
    */
   public const int WIRE_LIMIT = 1048576;
   /**
    * Byte length of the RFC 9110 IMF-fixdate produced by Header::stamp().
    */
   private const int DATE_LENGTH = 29;

   // * Data
   /**
    * Cached entries: key => [wire bytes, expiration, Date value offset, stamped second].
    *
    * Public: the encoder's hot path reads `Cache::$entries !== []` directly
    * (a method frame there would tax every request of every server). Treat as
    * read-only — mutate only through store()/flush(). (PHP 8.4 does not allow
    * asymmetric visibility on static properties.)
    *
    * @var array<string,array{0:string,1:int,2:int,3:int}>
    */
   public static array $entries = [];
   /**
    * Admission pre-gate: URIs that have EVER stored an entry on this worker.
    *
    * The encoders test membership on the raw request URI before paying the
    * key composition + lookup, so never-cached routes (the common case) skip
    * the whole replay path. Deliberately a superset of the live entries —
    * evictions and expirations do not remove URIs (a stale member only costs
    * that URI the old compose-then-miss path); bounded by a periodic clear.
    *
    * @var array<string,true>
    */
   public static array $URIs = [];
   /**
    * Monotonic invalidation generation for delayed route-cache writers.
    *
    * Response captures this value when it is bound to a request. A deferred
    * clone from before flush() must not repopulate entries after the lifecycle
    * transition that invalidated it. Public for the same hot-path reason as
    * entries/URIs; treat as read-only outside this class.
    */
   public static int $generation = 0;

   // * Metadata
   /**
    * Monotonic counter behind every unshareable identity/claim marker: a state
    * that cannot be proven equal to another gets a value that never repeats, so
    * the request is served cold instead of risking a cross-principal hit.
    */
   private static int $unshared = 0;


   /**
    * Compose the stable route-cache key for one request.
    *
    * Every component is length-framed rather than delimiter-separated: HTTP
    * request targets and field values can contain delimiter-like bytes, so
    * concatenation would permit distinct requests to collide. Exact authority
    * is part of the primary key because application routing and generated
    * links can depend on it. Raw TrustedProxy inputs are also framed: that
    * middleware can change application address/scheme after cache lookup, so
    * a forwarded request must never read an entry primed without those fields.
    *
    * When language variance is enabled, preserve the exact parsed request
    * field (including absent, empty and repeated-field distinctions). Using
    * only the negotiated locale would be unsafe for handlers that also read
    * the raw Accept-Language field while declaring `Vary: Accept-Language`.
    */
   public static function compose (Request $Request, bool $varyLanguage = false): string
   {
      $headers = $Request->headers;
      /** @var null|string|array<int,string> $forwardedProto */
      $forwardedProto = array_key_exists('x-forwarded-proto', $headers)
         ? $headers['x-forwarded-proto']
         : null;
      /** @var null|string|array<int,string> $forwardedFor */
      $forwardedFor = array_key_exists('x-forwarded-for', $headers)
         ? $headers['x-forwarded-for']
         : null;
      /** @var null|string|array<int,string> $realIP */
      $realIP = array_key_exists('x-real-ip', $headers)
         ? $headers['x-real-ip']
         : null;

      $key = '5'
         . self::frame($Request->method)
         . self::frame($Request->host)
         . self::frame($Request->URI)
         . self::frame($forwardedProto)
         . self::frame($forwardedFor)
         . self::frame($realIP)
         . self::frame(self::identify($Request));

      if ($varyLanguage === false) {
         return "{$key}V0";
      }

      /** @var null|string|array<int,string> $language */
      $language = array_key_exists('accept-language', $headers)
         ? $headers['accept-language']
         : null;

      return "{$key}V1" . self::frame($language);
   }

   /**
    * Identify the admitted principal this entry belongs to (audit 2026-07-27 H1).
    *
    * Cache replay runs AFTER global admission, so a global middleware pipeline
    * can admit two DIFFERENT valid principals that reach the same
    * method/authority/URI. Without the principal in the key, the second one
    * replays the first one's raw wire and never runs the handler. Only
    * `Cookie` and `Authorization` had dedicated replay guards; a custom
    * credential such as `X-API-Key` reached this state.
    *
    * An anonymous request returns `null` — the overwhelmingly common case,
    * costing three property reads and no allocation, so public routes keep
    * sharing one entry exactly as before.
    *
    * A non-scalar identity cannot be proven equal to another instance, so it
    * yields a value that never repeats: the request is served cold rather than
    * risking a cross-principal hit.
    */
   private static function identify (Request $Request): null|string
   {
      $identity = $Request->identity;
      $claims = $Request->claims;
      $attributes = $Request->attributes;

      // ?: Anonymous — nothing admitted, nothing to partition on.
      if ($identity === null && $claims === [] && $attributes === []) {
         return null;
      }

      // ! Three FIXED slots, each length-framed like every other key component,
      //   and the identity slot carries its PHP type.
      //
      //   Concatenating raw markers with a separator was ambiguous in two ways
      //   that both let distinct principals share one entry: an identity whose
      //   own text contained the separator and a marker prefix reproduced the
      //   string a different principal built from its claims, and a scalar was
      //   stringified so integer `1` and string `"1"` were indistinguishable.
      //   A fixed slot count also stops an absent bag from shifting positions.
      // ! The identity goes through the SAME canonical encoder as the bags.
      //   Interpolating a scalar stringifies it: the adjacent finite doubles
      //   `1.0` and `1.0000000000000002` both rendered as `float:1`, so the
      //   second principal reused the first one's entry.
      $mark = self::frame(self::encode($identity));

      foreach ([$claims, $attributes] as $bag) {
         if ($bag === []) {
            $mark .= self::frame(null);
            continue;
         }

         $mark .= self::frame(self::encode($bag));
      }

      return $mark;
   }

   /**
    * Serialize one claim/attribute bag into a canonical, TYPE-TAGGED string.
    *
    * `json_encode()` erases distinctions the application itself can act on: a
    * float `1.0` and an integer `1` both render as `1`, so two principals whose
    * only difference was a claim's TYPE composed the same key and one replayed
    * the other's wire. Every scalar therefore carries its type tag, every string
    * its length, and every composite its arity — and anything that cannot be
    * compared structurally (object, resource, closure) makes the state
    * unshareable instead of collapsing onto a peer.
    */
   private static function encode (mixed $value): string
   {
      // ? Composite — recurse over the pairs in their own order, which is part
      //   of the state: two bags differing only in order are not the same bag.
      if (is_array($value)) {
         $encoded = 'A' . count($value) . ':';
         foreach ($value as $key => $item) {
            $encoded .= self::encode($key) . self::encode($item);
         }

         return "{$encoded};";
      }

      // :
      return match (true) {
         $value === null => 'N;',
         $value === true => 'B1;',
         $value === false => 'B0;',
         is_int($value) => "I{$value};",
         // ! 17 significant digits round-trip every double exactly, and the tag
         //   keeps `1.0` distinct from the integer `1`.
         is_float($value) => 'D' . sprintf('%.17G', $value) . ';',
         is_string($value) => 'S' . strlen($value) . ':' . $value . ';',
         // ? Unprovable equality — force a miss instead of guessing.
         default => 'U' . ++self::$unshared . ';'
      };
   }

   /**
    * Length-frame one scalar or repeated request-key component.
    *
    * @param null|string|array<int,string> $value
    */
   private static function frame (null|string|array $value): string
   {
      if ($value === null) {
         return 'N';
      }

      if (is_array($value)) {
         $frame = 'A' . count($value) . ':';
         foreach ($value as $entry) {
            $frame .= strlen($entry) . ":{$entry}";
         }

         return $frame;
      }

      return 'S' . strlen($value) . ":{$value}";
   }


   /**
    * Fetch stored wire bytes by key, refreshing the Date header per second.
    *
    * Returns null on miss or on an expired entry (which is dropped).
    */
   public static function fetch (string $key): null|string
   {
      // ?
      $entry = self::$entries[$key] ?? null;

      if ($entry === null) {
         return null;
      }

      $now = time();

      // ? Expired — drop and fall through to the normal pipeline
      if ($entry[1] <= $now) {
         unset(self::$entries[$key]);

         return null;
      }

      // @ Refresh the Date header once per second (fixed 29-byte IMF-fixdate);
      //   hits within the same second return the stored bytes with zero copies
      if ($entry[3] !== $now && $entry[2] >= 0) {
         $entry[0] = substr_replace($entry[0], Header::stamp(), $entry[2], self::DATE_LENGTH);
         $entry[3] = $now;

         self::$entries[$key] = $entry;
      }

      // :
      return $entry[0];
   }

   /**
    * Store built wire bytes for one request key.
    *
    * Callers are responsible for request/response-side guards; this method
    * only enforces structural limits.
    */
   public static function store (string $key, string $wire, int $ttl, string $URI): void
   {
      // ?
      if ($ttl <= 0 || strlen($key) > self::KEY_LIMIT || strlen($wire) > self::WIRE_LIMIT) {
         return;
      }

      // ? FIFO eviction at capacity
      if (count(self::$entries) >= self::ENTRIES_LIMIT && isSet(self::$entries[$key]) === false) {
         unset(self::$entries[array_key_first(self::$entries)]);
      }

      // ! Register the URI in the admission pre-gate set (bounded: cleared
      //   when it doubles the entry capacity; re-stores repopulate it).
      if (count(self::$URIs) > self::ENTRIES_LIMIT * 2) {
         self::$URIs = [];
      }
      self::$URIs[$URI] = true;

      // ! Locate the Date header value once — fetch() patches it in place
      $offset = strpos($wire, "\r\nDate: ");
      $offset = $offset === false ? -1 : $offset + 8;

      self::$entries[$key] = [$wire, time() + $ttl, $offset, time()];
   }

   /**
    * Flush every cached entry (worker-local).
    */
   public static function flush (): void
   {
      self::$generation++;
      self::$entries = [];
      self::$URIs = [];
   }
}
