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


use const DNS_AAAA;
use const FILTER_FLAG_GLOBAL_RANGE;
use const FILTER_VALIDATE_IP;
use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;
use const PREG_SET_ORDER;
use const SOCK_STREAM;
use function array_slice;
use function array_unique;
use function array_values;
use function dns_get_record;
use function file_get_contents;
use function filter_var;
use function function_exists;
use function gethostbynamel;
use function hrtime;
use function http_clear_last_response_headers;
use function http_get_last_response_headers;
use function in_array;
use function inet_ntop;
use function inet_pton;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function ord;
use function parse_url;
use function preg_match;
use function preg_match_all;
use function rtrim;
use function socket_addrinfo_explain;
use function socket_addrinfo_lookup;
use function str_contains;
use function str_ends_with;
use function strcasecmp;
use function stream_context_create;
use function stream_context_set_option;
use function strlen;
use function strtolower;
use function substr;
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
 *
 * The native fetch resolves each hop once and dials those addresses in
 * resolver order — never the name again, so a second DNS answer cannot
 * redirect the request — and, once a hop was answered from a public address,
 * refuses a later hop that resolves to a non-public one (loopback, private,
 * link-local, reserved, NAT64-embedded): a compromised or misconfigured IdP
 * cannot point the worker at its own network. The configured URI is never
 * refused (an intranet IdP keeps working), and a custom `Fetcher` owns its
 * own transport.
 */
class Remote implements KeyResolver
{
   private const int MAX_TTL = 31_536_000;
   /**
    * The least time, in seconds, a hop's next address is tried with: when
    * less of the hop's timeout is left, the hop ends.
    */
   private const float TRY_FLOOR = 0.05;

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
   // ? Why the native fetch refused a hop ('' when it did not)
   private string $refusal = '';


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
         return $this->mark($Response, $this->refusal !== '' ? $this->refusal : 'Remote JWKS fetch failed.');
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
      $this->refusal = '';

      $limit = $this->size + 1;
      if ($limit < 1) {
         $limit = 1;
      }

      $URI = $this->URI;
      $redirected = 0;
      // ! Whether a hop was already answered from a public address: from then
      //   on every address a hop resolves to must be public
      $public = false;

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
         $Hop = URI::parse($URI);
         if ($Hop === null) {
            return Failures::Network;
         }

         // ! Resolve the hop once — the transport dials this literal, so a
         //   second DNS answer (rebinding) never decides where bytes go. A
         //   terminal dot stays in the lookup name (no resolver search suffix).
         $addresses = $this->discover($Hop->host[0] === '[' ? $Hop->hostname : $Hop->host);
         if ($addresses === []) {
            return Failures::Network;
         }
         // ? Once a hop was answered from a public address, a later hop never
         //   reaches a non-public one — none of its addresses may be
         if ($public) {
            foreach ($addresses as $address) {
               if ($this->permit($address) === false) {
                  $this->refusal = 'Remote JWKS redirect from a public address to a non-public address was refused.';

                  return Failures::Network;
               }
            }
         }

         // ! Dial the vetted literals in resolver order until one answers; the
         //   hop's own name stays the HTTP authority (`Host`) and the TLS
         //   identity (`peer_name`: SNI and certificate verification)
         $port = $Hop->port ?? ($scheme === 'https' ? 443 : 80);
         $userinfo = $Hop->userinfo === null ? '' : "{$Hop->userinfo}@";
         $path = $Hop->path === '' ? '/' : $Hop->path;
         $query = $Hop->query === null ? '' : "?{$Hop->query}";
         $default = $scheme === 'https' ? 443 : 80;
         $host = $Hop->port === null || $Hop->port === $default
            ? $Hop->host
            : "{$Hop->host}:{$Hop->port}";
         $Context = stream_context_create([
            'http' => [
               'method' => 'GET',
               'header' => "Host: {$host}\r\nAccept: application/json\r\n",
               'timeout' => $this->timeout,
               'ignore_errors' => true,
               'follow_location' => 0,
               'max_redirects' => 0,
            ],
            'ssl' => [
               'verify_peer' => true,
               'verify_peer_name' => true,
               'peer_name' => $Hop->hostname,
            ],
         ]);
         // ! The hop's own timeout bounds every try together: each one gets
         //   what is left of it
         $deadline = hrtime(true) + (int) ($this->timeout * 1_000_000_000);
         $body = false;
         $answered = null;
         // @@
         foreach ($addresses as $index => $address) {
            // ? A later address only with what is left of the hop's timeout
            if ($index > 0) {
               $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
               if ($remaining < self::TRY_FLOOR) {
                  break;
               }
               stream_context_set_option($Context, 'http', 'timeout', $remaining);
            }
            $literal = str_contains($address, ':') ? "[{$address}]" : $address;
            http_clear_last_response_headers();
            $body = @file_get_contents(
               "{$scheme}://{$userinfo}{$literal}:{$port}{$path}{$query}",
               false,
               $Context,
               0,
               $limit
            );
            // ? An address that took the request ends the hop — with its
            //   answer, or with the failure it caused midway; a refused,
            //   unreachable or TLS-failed one hands over to the next
            if (is_string($body) || http_get_last_response_headers() !== null) {
               $answered = $address;
               break;
            }
         }
         if (is_string($body) === false || $answered === null) {
            return Failures::Network;
         }
         // ! A hop answered from a public address arms the policy
         if ($public === false && $this->permit($answered)) {
            $public = true;
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
         $Target = $Hop->resolve($location);
         if ($Target === null) {
            return Failures::Network;
         }
         $URI = (string) $Target;

         $redirected++;
      }
   }

   /**
    * Resolve one host to its canonical IP addresses — once per hop: the native
    * fetch dials one of these literals and never resolves the name again.
    *
    * An IP literal is returned canonical without a lookup (an IPv4-mapped
    * IPv6 address as its IPv4 address). Overridable by a subclass — a test
    * models DNS answers through it.
    *
    * @param string $host A registered name (a terminal dot kept) or an IP literal without brackets.
    *
    * @return array<int,string> Canonical addresses in resolver order (getaddrinfo's with ext-sockets;
    *    without it, the IPv4 answers first, then the absolute name's DNS AAAA records — a DNS round trip
    *    outside the hop's `timeout`, none for `localhost`); empty when the host does not resolve.
    */
   protected function discover (string $host): array
   {
      // ? An IP literal needs no lookup
      $literal = $this->normalize($host);
      if ($literal !== null) {
         return [$literal];
      }

      // @ The system resolver — the one PHP's own wrapper would have used
      $addresses = [];
      if (function_exists('socket_addrinfo_lookup')) {
         try {
            $AddressInfos = @socket_addrinfo_lookup($host, null, [
               'ai_socktype' => SOCK_STREAM,
            ]);
         }
         catch (Throwable) {
            $AddressInfos = false;
         }
         foreach (is_array($AddressInfos) ? $AddressInfos : [] as $AddressInfo) {
            $explained = socket_addrinfo_explain($AddressInfo);
            $IP = $explained['ai_addr']['sin_addr']
               ?? ($explained['ai_addr']['sin6_addr'] ?? null);
            if (is_string($IP)) {
               $addresses[] = $IP;
            }
         }
      }
      // ! Without ext-sockets: the system resolver's IPv4 answers (hosts
      //   file included) first, then the name's DNS AAAA records — asked for
      //   the absolute name (no search list), never for `localhost`
      else {
         $IPv4 = @gethostbynamel($host);
         foreach (is_array($IPv4) ? $IPv4 : [] as $IP) {
            $addresses[] = $IP;
         }

         $name = strtolower(rtrim($host, '.'));
         if ($name !== 'localhost' && str_ends_with($name, '.localhost') === false) {
            $records = @dns_get_record("{$name}.", DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
               $IP = $record['ipv6'] ?? null;
               if (is_string($IP)) {
                  $addresses[] = $IP;
               }
            }
         }
      }

      // ! An answer that is not an address voids the whole lookup
      $normalized = [];
      foreach ($addresses as $IP) {
         $address = $this->normalize($IP);
         if ($address === null) {
            return [];
         }
         $normalized[] = $address;
      }

      // :
      return array_values(array_unique($normalized));
   }

   /**
    * Whether one canonical IP address is public — a globally routable unicast
    * destination.
    *
    * Non-public: loopback, private, link-local, shared (CGNAT), reserved and
    * documentation ranges (PHP's `FILTER_FLAG_GLOBAL_RANGE`), plus what that
    * classifier still admits — `0/8`, multicast and `240/4`, the 6to4 relay
    * anycast `192.88.99/24`, the unspecified `::`, the IPv4-compatible
    * `::/96`, SIIT `::ffff:0:0/96`, multicast `ff00::/8`, the NAT64 local-use
    * prefix `64:ff9b:1::/48`, site-local `fec0::/10`, documentation
    * `3fff::/20` and SRv6 `5f00::/16`. An IPv4-mapped address, and one in the
    * NAT64 well-known prefix `64:ff9b::/96`, is judged as the IPv4 address it
    * carries; a network-specific NAT64 prefix is not recognized. Overridable
    * by a subclass — a test models a public origin on loopback through it.
    *
    * @param string $IP A canonical IPv4 or IPv6 address.
    */
   protected function permit (string $IP): bool
   {
      $address = $this->normalize($IP);
      $packed = $address === null ? false : @inet_pton($address);
      if ($address === null || $packed === false) {
         return false;
      }

      $first = ord($packed[0]);
      if (strlen($packed) === 4) {
         // ? `0/8`, multicast `224/4`, reserved `240/4`; the 6to4 relay anycast
         if ($first === 0 || $first >= 224 || substr($packed, 0, 3) === "\xc0\x58\x63") {
            return false;
         }
      }
      else {
         // ?: The NAT64 well-known prefix only translates global IPv4: judge
         //   the IPv4 address it embeds
         if (substr($packed, 0, 12) === "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00") {
            $embedded = inet_ntop(substr($packed, 12));

            return is_string($embedded) && $this->permit($embedded);
         }

         $second = ord($packed[1]);
         // ? `::/96` (unspecified, IPv4-compatible), SIIT `::ffff:0:0/96`,
         //   multicast, NAT64 local-use, site-local `fec0::/10`, documentation
         //   `3fff::/20`, SRv6 `5f00::/16`
         if (
            substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            || substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\x00\x00"
            || $first === 255
            || substr($packed, 0, 6) === "\x00\x64\xff\x9b\x00\x01"
            || ($first === 0xfe && ($second & 0xc0) === 0xc0)
            || ($first === 0x3f && $second === 0xff && (ord($packed[2]) & 0xf0) === 0)
            || ($first === 0x5f && $second === 0x00)
         ) {
            return false;
         }
      }

      // @phpstan-ignore notIdentical.alwaysTrue (GLOBAL_RANGE is narrower than valid-IP)
      return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
   }

   /**
    * Canonicalize an IPv4/IPv6 literal (an IPv4-mapped IPv6 address becomes
    * its IPv4 address); null when the string is not one.
    */
   private function normalize (string $IP): null|string
   {
      $packed = @inet_pton($IP);
      if ($packed === false) {
         return null;
      }
      if (strlen($packed) === 16 && substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
         $packed = substr($packed, 12);
      }

      $normalized = inet_ntop($packed);

      return is_string($normalized) ? $normalized : null;
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
