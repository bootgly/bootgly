<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT;


use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;
use const PREG_SET_ORDER;
use function array_slice;
use function file_get_contents;
use function hrtime;
use function http_get_last_response_headers;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function parse_url;
use function preg_match;
use function preg_match_all;
use function strcasecmp;
use function stream_context_create;
use function strlen;
use function strtolower;
use function time;
use function trim;
use Closure;
use InvalidArgumentException;
use JsonException;
use Throwable;

use Bootgly\ABI\Data\URI;
use Bootgly\API\Security\JWT\Remote\Response;


/**
 * Remote JWKS resolver with process-local/shared cache and refresh-on-miss.
 *
 * Each process bounds how often it asks the origin, whatever the IdP's cache
 * headers say: a key set the origin confirmed is held — served — for one window
 * (`cooldown`, or `$TTL` when positive and shorter), even under `no-store` or
 * `ttl: 0`; a failed fetch is replayed — fail closed — for one `cooldown`. Key
 * resolution runs before signature verification, so without this floor every
 * unauthenticated token could force one blocking fetch inside the worker. Only
 * `refresh()` and the refresh on an unknown `kid` (at most one per `cooldown`)
 * bypass it.
 */
class Remote implements KeyResolver
{
   private const int MAX_TTL = 31_536_000;

   // * Config
   public private(set) string $URI;
   public private(set) null|string $algorithm;
   /**
    * Ceiling on a fetched set's freshness (`expires`) and shared-cache lifetime.
    * A positive value shorter than `cooldown` also shortens how long a set the
    * origin confirmed is held; `0` still holds it for one `cooldown`.
    */
   public int $TTL {
      get => $this->ttl;
      set {
         if ($value < 0) {
            throw new InvalidArgumentException('JWKS cache TTL must not be negative.');
         }
         if ($value > self::MAX_TTL) {
            throw new InvalidArgumentException('JWKS cache TTL is too large.');
         }

         $this->ttl = $value;
      }
   }
   /**
    * The origin floor, in seconds, per process: a failed fetch is replayed for
    * one cooldown, and a set the origin confirmed is held for one — or for
    * `$TTL`, when that is positive and shorter. It also spaces the refresh on an
    * unknown `kid`. `0` disables both (every zero-TTL verification then fetches:
    * for tests only).
    */
   public int $cooldown {
      set {
         if ($value < 0) {
            throw new InvalidArgumentException('JWKS refresh cooldown must not be negative.');
         }
         if ($value > self::MAX_TTL) {
            throw new InvalidArgumentException('JWKS refresh cooldown is too large.');
         }

         $this->cooldown = $value;
      }
   }
   public int $size;
   public int|float $timeout = 10;
   public int $redirects = 3;
   public bool $insecure;

   // * Data
   /**
    * Custom remote fetcher. It must return a JWKS body string or Response.
    */
   private null|Closure $Fetcher;
   private null|Cache $Cache = null;
   private null|KeySet $Keys = null;
   private int $ttl;
   public private(set) null|Failures $failure = null;
   public private(set) string $message = '';
   public private(set) int $status = 0;

   // * Metadata
   public private(set) int $fetched = 0;
   public private(set) int $expires = 0;
   private int $missed = 0;
   // ! Origin floor deadlines, on the monotonic clock (ns), fixed when an attempt
   //   ends: a wall-clock step back never stretches them, and a later `cooldown`
   //   or `$TTL` assignment only shapes the next attempt
   // ? Until when the set the origin last confirmed is served
   private int $hold = 0;
   // ? Until when the origin is not asked again (forced loads excepted)
   private int $pause = 0;
   private null|Failures $Fault = null;


   /**
    * Create a remote JWKS resolver.
    */
   public function __construct (
      string $URI,
      null|callable $Fetcher = null,
      null|string $algorithm = 'RS256',
      int $ttl = 3600,
      int $cooldown = 60,
      int $size = 1048576,
      bool $insecure = false
   )
   {
      if ($URI === '') {
         throw new InvalidArgumentException('JWKS URI must not be empty.');
      }
      if ($algorithm !== null && $algorithm !== 'RS256') {
         throw new InvalidArgumentException('Unsupported JWKS algorithm.');
      }
      if ($ttl < 0) {
         throw new InvalidArgumentException('JWKS cache TTL must not be negative.');
      }
      if ($ttl > self::MAX_TTL) {
         throw new InvalidArgumentException('JWKS cache TTL is too large.');
      }
      if ($cooldown < 0) {
         throw new InvalidArgumentException('JWKS refresh cooldown must not be negative.');
      }
      if ($size < 1) {
         throw new InvalidArgumentException('JWKS response size must be positive.');
      }

      $parts = parse_url($URI);
      $scheme = is_array($parts) && is_string($parts['scheme'] ?? null)
         ? strtolower($parts['scheme'])
         : '';
      if ($scheme !== 'https' && ($insecure === false || $scheme !== 'http')) {
         throw new InvalidArgumentException('Remote JWKS requires HTTPS.');
      }

      // * Config
      $this->URI = $URI;
      $this->algorithm = $algorithm;
      $this->TTL = $ttl;
      $this->cooldown = $cooldown;
      $this->size = $size;
      $this->insecure = $insecure;

      // * Data
      $this->Fetcher = $Fetcher === null ? null : Closure::fromCallable($Fetcher);
   }

   /**
    * Fetch the JWKS, returning the cached key set while it is fresh — or while
    * the origin confirmed it within the last window — and replaying the last
    * origin failure until the window ends.
    */
   public function fetch (): KeySet|Failures
   {
      return $this->load(false);
   }

   /**
    * Force a JWKS refresh.
    */
   public function refresh (): KeySet|Failures
   {
      return $this->load(true);
   }

   /**
    * Use a shared JWKS cache across workers.
    */
   public function cache (Cache $Cache): self
   {
      $this->Cache = $Cache;

      return $this;
   }

   /**
    * Resolve a key, refreshing once when a `kid` is not in the cache.
    */
   public function resolve (null|string $id, string $algorithm): null|Key
   {
      // ? A set pinned to one algorithm never holds a key for another — no fetch
      if ($this->algorithm !== null && $algorithm !== $this->algorithm) {
         $this->mark(Failures::Key, 'JWT key could not be resolved.', $this->status);
         return null;
      }

      $pause = $this->pause;
      $Keys = $this->fetch();
      if ($Keys instanceof Failures) {
         return null;
      }

      $Key = $Keys->resolve($id, $algorithm);
      if ($Key !== null) {
         $this->clear($this->status);
         return $Key;
      }

      // ? This very call just asked the origin: a refresh would fetch the same set
      if ($id === null || $this->pause !== $pause) {
         $this->mark(Failures::Key, 'JWT key could not be resolved.', $this->status);
         return null;
      }

      $now = time();
      if ($this->throttle($now)) {
         $this->mark(Failures::Key, 'JWT key could not be resolved.', $this->status);
         return null;
      }

      $Keys = $this->load(true);
      if ($Keys instanceof Failures) {
         return null;
      }

      $Key = $Keys->resolve($id, $algorithm);
      if ($Key !== null) {
         $this->clear($this->status);
         return $Key;
      }

      $this->mark(Failures::Key, 'JWT key could not be resolved.', $this->status);

      return null;
   }

   /**
    * Return the last remote resolver failure, if any.
    */
   public function fail (): null|Failures
   {
      return $this->failure;
   }

   /**
    * Load and parse the remote JWKS.
    */
   private function load (bool $force): KeySet|Failures
   {
      $now = time();
      // ! The origin floor, on the monotonic clock: a confirmed set is held for
      //   one window — `cooldown`, or a shorter positive `$TTL` (the operator's
      //   bound wins); a failure pauses the origin for a whole `cooldown`
      $clock = hrtime(true);
      $window = ($this->ttl > 0 ? min($this->cooldown, $this->ttl) : $this->cooldown) * 1_000_000_000;

      // ? A set the ORIGIN confirmed is served for its whole window even when its
      //   TTL is 0 — nor does a failed forced refresh (an unknown `kid` any client
      //   can send) turn valid tokens into rejections inside that window
      $held = $clock < $this->hold;
      $Keys = $this->Keys;
      if ($force === false && $Keys !== null && ($this->expires > $now || $held)) {
         $this->clear($this->status);
         return $Keys;
      }

      if ($force === false && $this->Cache !== null && $this->ttl > 0) {
         $value = $this->Cache->read($this->index());
         if ($value !== null) {
            $record = $this->unpack($value, $now);
            if ($record !== null) {
               $ttl = min($this->ttl, $record['expires'] - $now);
               $Keys = $this->parse($record['body'], $this->status, $now, $ttl);
            }
            else {
               $Keys = Failures::JWKS;
            }

            if ($Keys instanceof Failures === false) {
               return $Keys;
            }

            $this->Cache->delete($this->index());
         }
      }

      // ? The origin was asked within the window and gave no set to hold: replay
      //   its failure (fail closed) instead of asking again — `Network` for a
      //   failed request, or for a call while that attempt is still in flight
      if ($force === false && $clock < $this->pause) {
         return $this->mark($this->Fault ?? Failures::Network, 'Remote JWKS origin is cooling down.', $this->status);
      }

      // @ Ask the origin — paused for as long as the attempt is in flight, then
      //   for one `cooldown` from its end (a failure keeps that pause)
      $this->Fault = null;
      $this->pause = PHP_INT_MAX;
      try {
         $Response = $this->request();
      }
      finally {
         // ? Runs even when a suspended Fiber fetcher is destroyed mid-attempt
         $ended = hrtime(true);
         $this->pause = $ended + $this->cooldown * 1_000_000_000;
      }
      if ($Response instanceof Failures) {
         return $this->mark($Response, 'Remote JWKS fetch failed.');
      }

      if ($Response->status < 200 || $Response->status > 299) {
         $this->Fault = Failures::Status;
         return $this->mark(Failures::Status, 'Remote JWKS returned a non-success status.', $Response->status);
      }

      $ttl = $this->limit($Response);
      $Keys = $this->parse($Response->body, $Response->status, $now, $ttl);
      if ($Keys instanceof Failures) {
         $this->Fault = $Keys;
         return $Keys;
      }

      // ! Only an origin answer confirms a set — never a shared-cache read — and
      //   it reopens the origin when its hold ends
      $this->hold = $ended + $window;
      $this->pause = $this->hold;

      $remaining = $this->expires - time();
      if ($this->Cache !== null && $remaining > 0) {
         $value = $this->pack($Response->body, $this->expires);
         if ($value !== null) {
            $this->Cache->write($this->index(), $value, $remaining);
         }
      }

      return $Keys;
   }

   /**
    * Parse and cache a JWKS body.
    */
   private function parse (string $body, int $status, int $now, int $ttl): KeySet|Failures
   {
      if (strlen($body) > $this->size) {
         return $this->mark(Failures::JWKS, 'Remote JWKS response is too large.', $status);
      }

      try {
         $jwks = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
      }
      catch (JsonException) {
         return $this->mark(Failures::JSON, 'Remote JWKS JSON is not valid.', $status);
      }

      if (is_array($jwks) === false) {
         return $this->mark(Failures::JWKS, 'Remote JWKS document is not an object.', $status);
      }

      try {
         /** @var array<string,mixed> $jwks */
         $Keys = KeysJWKS::parse($jwks, $this->algorithm);
      }
      catch (InvalidArgumentException $Exception) {
         return $this->mark(Failures::JWKS, $Exception->getMessage(), $status);
      }

      $this->Keys = $Keys;
      $this->fetched = $now;
      $this->expires = $ttl > 0 ? $now + $ttl : 0;
      $this->clear($status);

      return $Keys;
   }

   /**
    * Encode a shared JWKS cache record with its absolute expiry.
    */
   private function pack (string $body, int $expires): null|string
   {
      try {
         return json_encode([
            'expires' => $expires,
            'body' => $body,
         ], JSON_THROW_ON_ERROR);
      }
      catch (JsonException) {
         return null;
      }
   }

   /**
    * Decode a live shared JWKS cache record.
    *
    * @return null|array{expires:int,body:string}
    */
   private function unpack (string $value, int $now): null|array
   {
      try {
         $record = json_decode($value, true, 3, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
      }
      catch (JsonException) {
         return null;
      }

      if (
         is_array($record) === false
         || is_int($record['expires'] ?? null) === false
         || is_string($record['body'] ?? null) === false
         || $record['expires'] <= $now
      ) {
         return null;
      }

      /** @var array{expires:int,body:string} $record */
      return $record;
   }

   /**
    * Compute the effective cache TTL from remote headers.
    */
   private function limit (Response $Response): int
   {
      if ($this->ttl === 0) {
         return 0;
      }

      $controls = [];
      $ages = [];

      foreach ($Response->headers as $name => $header) {
         $field = '';
         $value = $header;

         if (is_string($name)) {
            $field = trim($name);
         }
         elseif (preg_match('/^([^:\s]+)\s*:\s*(.*)$/', $header, $matches) === 1) {
            $field = $matches[1];
            $value = $matches[2];
         }

         if (strcasecmp($field, 'Cache-Control') === 0) {
            $controls[] = $value;
         }
         elseif (strcasecmp($field, 'Age') === 0) {
            $ages[] = $value;
         }
      }

      $remote = null;
      foreach ($controls as $control) {
         if (preg_match('/(?:^|,)\s*(?:no-cache|no-store)\s*(?:=|,|$)/i', $control) === 1) {
            return 0;
         }

         $count = preg_match_all(
            '/(?:^|,)\s*max-age\s*=\s*"?(\d+)"?\s*(?=,|$)/i',
            $control,
            $matches,
            PREG_SET_ORDER
         );
         if ($count === false || $count === 0) {
            continue;
         }

         foreach ($matches as $match) {
            $candidate = (int) $match[1];
            $remote = $remote === null ? $candidate : min($remote, $candidate);
         }
      }

      if ($remote === null) {
         return $this->ttl;
      }
      if ($remote === 0) {
         return 0;
      }

      $age = 0;
      foreach ($ages as $value) {
         if (preg_match('/^\s*(\d+)\s*$/', $value, $matches) !== 1) {
            continue;
         }

         $age = max($age, (int) $matches[1]);
      }

      $remote = $age < $remote ? $remote - $age : 0;

      return min($remote, $this->ttl);
   }

   /**
    * Throttle refresh-on-miss attempts.
    */
   private function throttle (int $now): bool
   {
      if ($this->cooldown < 1) {
         return false;
      }

      // ? One refresh per cooldown per worker, always
      if ($this->missed > 0 && $now - $this->missed < $this->cooldown) {
         return true;
      }
      // ? ... and one per fleet while the worker's set is still fresh: its answer
      //   then reaches the fleet through the Vault — a zero-TTL set never does
      if (
         $this->Cache !== null
         && $this->expires > $now
         && $this->Cache->claim($this->index('miss'), (string) $now, $this->cooldown) === false
      ) {
         return true;
      }

      $this->missed = $now;

      return false;
   }

   /**
    * Build the shared cache key for this remote JWKS source.
    */
   private function index (string $scope = 'body:v2'): string
   {
      return "jwt:jwks:{$scope}:{$this->algorithm}:{$this->URI}";
   }

   /**
    * Fetch the remote document through a custom or native fetcher.
    */
   private function request (): Response|Failures
   {
      try {
         $Fetched = $this->Fetcher !== null
            ? ($this->Fetcher)($this->URI)
            : $this->pull();
      }
      catch (Throwable) {
         return Failures::Network;
      }

      if ($Fetched instanceof Response) {
         return $Fetched;
      }

      if (is_string($Fetched)) {
         return new Response(200, $Fetched);
      }

      return Failures::Network;
   }

   /**
    * Native HTTPS GET fallback used when no fetcher is injected.
    */
   private function pull (): Response|Failures
   {
      $Context = stream_context_create([
         'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
         ],
         'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
         ],
      ]);

      $limit = $this->size + 1;
      if ($limit < 1) {
         $limit = 1;
      }

      $URI = $this->URI;
      $redirected = 0;

      while (true) {
         // ! Validate every hop before opening its transport. Delegating
         //   redirects to PHP reports the target only after its request bytes
         //   have already crossed the wire.
         $parts = parse_url($URI);
         $scheme = is_array($parts) && is_string($parts['scheme'] ?? null)
            ? strtolower($parts['scheme'])
            : '';
         if ($scheme !== 'https' && ($this->insecure === false || $scheme !== 'http')) {
            return Failures::Network;
         }

         $body = @file_get_contents($URI, false, $Context, 0, $limit);
         if (is_string($body) === false) {
            return Failures::Network;
         }

         // @ The headers PHP recorded for this one manual request. Selecting
         //   the last status block still handles informational responses while
         //   keeping redirect-hop fields outside the final Response.
         $headers = http_get_last_response_headers() ?? [];
         $status = 0;
         $offset = null;
         foreach ($headers as $index => $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) !== 1) {
               continue;
            }

            $status = (int) $matches[1];
            $offset = $index;
         }
         if ($status === 0 || $offset === null) {
            return Failures::Network;
         }

         $headers = array_slice($headers, $offset);
         if (in_array($status, [301, 302, 303, 307, 308], true) === false) {
            return new Response($status, $body, $headers);
         }

         $location = $this->locate($headers);
         if ($location === null) {
            return new Response($status, $body, $headers);
         }
         if ($redirected >= $this->redirects) {
            return Failures::Network;
         }

         // ! RFC 3986 §5 against the hop that answered; a target that is not an
         //   absolute hierarchical URI with a host (`g:h`, `https:x`) ends the
         //   fetch — PHP's wrappers would read `https:x` as a local file
         $Target = URI::parse($URI)?->resolve($location);
         if ($Target === null) {
            return Failures::Network;
         }
         $URI = (string) $Target;

         $redirected++;
      }
   }

   /**
    * Locate a redirect target in one response header block.
    *
    * @param array<int|string,string> $headers
    */
   private function locate (array $headers): null|string
   {
      foreach ($headers as $header) {
         if (preg_match('/^Location\s*:\s*(.*)$/i', $header, $matches) !== 1) {
            continue;
         }

         $location = trim($matches[1]);

         return $location;
      }

      return null;
   }

   /**
    * Mark the resolver as failed.
    */
   private function mark (Failures $Failure, string $message = '', int $status = 0): Failures
   {
      $this->failure = $Failure;
      $this->message = $message;
      $this->status = $status;

      return $Failure;
   }

   /**
    * Clear the last failure.
    */
   private function clear (int $status): void
   {
      $this->failure = null;
      $this->message = '';
      $this->status = $status;
   }
}
