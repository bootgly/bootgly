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
use const PREG_SET_ORDER;
use function array_slice;
use function file_get_contents;
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
use function str_starts_with;
use function strcasecmp;
use function stream_context_create;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function time;
use function trim;
use Closure;
use InvalidArgumentException;
use JsonException;
use Throwable;

use Bootgly\API\Security\JWT\Remote\Response;


/**
 * Remote JWKS resolver with process-local/shared cache and refresh-on-miss.
 */
class Remote implements KeyResolver
{
   private const int MAX_TTL = 31_536_000;

   // * Config
   public private(set) string $URI;
   public private(set) null|string $algorithm;
   /** Maximum local lifetime allowed for a fetched JWKS. */
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
   public int $cooldown;
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
    * Fetch the JWKS, returning the cached key set while it is fresh.
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
      $Keys = $this->fetch();
      if ($Keys instanceof Failures) {
         return null;
      }

      $Key = $Keys->resolve($id, $algorithm);
      if ($Key !== null) {
         $this->clear($this->status);
         return $Key;
      }

      if ($id === null) {
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
      $Keys = $this->Keys;
      if ($force === false && $Keys !== null && $this->expires > $now) {
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

      $Response = $this->request();
      if ($Response instanceof Failures) {
         return $this->mark($Response, 'Remote JWKS fetch failed.');
      }

      if ($Response->status < 200 || $Response->status > 299) {
         return $this->mark(Failures::Status, 'Remote JWKS returned a non-success status.', $Response->status);
      }

      $ttl = $this->limit($Response);
      $Keys = $this->parse($Response->body, $Response->status, $now, $ttl);
      if ($Keys instanceof Failures) {
         return $Keys;
      }

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

      if ($this->Cache !== null) {
         return $this->Cache->claim($this->index('miss'), (string) $now, $this->cooldown) === false;
      }

      if ($this->missed > 0 && $now - $this->missed < $this->cooldown) {
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

         $URI = $this->follow($URI, $location);
         if ($URI === null) {
            return Failures::Network;
         }

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
    * Resolve an HTTP Location URI-reference against its current request URI.
    */
   private function follow (string $base, string $location): null|string
   {
      $location = trim($location);
      if (preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
         return null;
      }

      // # Fragments never travel in an HTTP request target.
      $fragment = strpos($location, '#');
      if ($fragment !== false) {
         $location = substr($location, 0, $fragment);
      }
      if ($location === '') {
         $fragment = strpos($base, '#');

         return $fragment === false ? $base : substr($base, 0, $fragment);
      }

      $parts = parse_url($base);
      if (
         is_array($parts) === false
         || is_string($parts['scheme'] ?? null) === false
         || preg_match('/^([a-z][a-z0-9+.-]*):\/\/([^\/?#]*)/i', $base, $matches) !== 1
      ) {
         return null;
      }

      $scheme = strtolower($matches[1]);
      $origin = "{$scheme}://{$matches[2]}";
      $basePath = is_string($parts['path'] ?? null) && $parts['path'] !== ''
         ? $parts['path']
         : '/';

      // # Hierarchical absolute and network-path references replace the
      //   authority, but their paths still require RFC dot-segment removal.
      if (
         preg_match(
            '/^([a-z][a-z0-9+.-]*):\/\/([^\/?#]*)([^?#]*)(\?[^#]*)?$/i',
            $location,
            $reference
         ) === 1
      ) {
         $origin = "{$reference[1]}://{$reference[2]}";
         $path = $reference[3];
         $query = $reference[4] ?? '';
      }
      elseif (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location) === 1) {
         // # Opaque absolute URI (`g:h`) has no hierarchical path to merge.
         return $location;
      }
      elseif (
         preg_match('/^\/\/([^\/?#]*)([^?#]*)(\?[^#]*)?$/', $location, $reference) === 1
      ) {
         $origin = "{$scheme}://{$reference[1]}";
         $path = $reference[2];
         $query = $reference[3] ?? '';
      }
      else {
         // # Query-only reference keeps the current path exactly.
         if (str_starts_with($location, '?')) {
            return "{$origin}{$basePath}{$location}";
         }

         $question = strpos($location, '?');
         $path = $question === false ? $location : substr($location, 0, $question);
         $query = $question === false ? '' : substr($location, $question);

         if (str_starts_with($path, '/') === false) {
            $slash = strrpos($basePath, '/');
            $directory = $slash === false ? '/' : substr($basePath, 0, $slash + 1);
            $path = $directory . $path;
         }
      }

      // # RFC 3986 §5.2.4 remove_dot_segments. Moving path segments as raw
      //   substrings preserves meaningful consecutive empty segments (`//`).
      $input = $path;
      $path = '';
      while ($input !== '') {
         if (str_starts_with($input, '../')) {
            $input = substr($input, 3);
         }
         elseif (str_starts_with($input, './')) {
            $input = substr($input, 2);
         }
         elseif (str_starts_with($input, '/./')) {
            $input = '/' . substr($input, 3);
         }
         elseif ($input === '/.') {
            $input = '/';
         }
         elseif (str_starts_with($input, '/../')) {
            $input = '/' . substr($input, 4);
            $slash = strrpos($path, '/');
            $path = $slash === false ? '' : substr($path, 0, $slash);
         }
         elseif ($input === '/..') {
            $input = '/';
            $slash = strrpos($path, '/');
            $path = $slash === false ? '' : substr($path, 0, $slash);
         }
         elseif ($input === '.' || $input === '..') {
            $input = '';
         }
         else {
            $offset = str_starts_with($input, '/') ? 1 : 0;
            $slash = strpos($input, '/', $offset);
            if ($slash === false) {
               $path .= $input;
               $input = '';
            }
            else {
               $path .= substr($input, 0, $slash);
               $input = substr($input, $slash);
            }
         }
      }

      return "{$origin}{$path}{$query}";
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
