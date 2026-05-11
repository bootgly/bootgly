<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT;


use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function parse_url;
use function preg_match;
use function stream_context_create;
use function strlen;
use function strtolower;
use function time;
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
   // * Config
   public private(set) string $URI;
   public private(set) null|string $algorithm;
   public int $ttl;
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
         throw new InvalidArgumentException('JWKS cache ttl must not be negative.');
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
      $this->ttl = $ttl;
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
         $body = $this->Cache->read($this->index());
         if ($body !== null) {
            $Keys = $this->parse($body, $this->status, $now, $this->ttl);
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

      if ($this->Cache !== null && $ttl > 0) {
         $this->Cache->write($this->index(), $Response->body, $ttl);
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
    * Compute the effective cache TTL from remote headers.
    */
   private function limit (Response $Response): int
   {
      foreach ($Response->headers as $header) {
         if (preg_match('/(?:^|[\s,])max-age\s*=\s*(\d+)/i', $header, $matches) === 1) {
            return (int) $matches[1];
         }
      }

      return $this->ttl;
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
   private function index (string $scope = 'body'): string
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
            'follow_location' => 1,
            'max_redirects' => $this->redirects,
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

      $body = @file_get_contents($this->URI, false, $Context, 0, $limit);
      if (is_string($body) === false) {
         return Failures::Network;
      }

      // @ PHP populates `$http_response_header` in this local scope after
      //   `file_get_contents()` HTTP stream requests.
      $headers = $http_response_header;
      $status = 0;
      if (isset($headers[0]) && preg_match('/^HTTP\/\S+\s+(\d{3})/', $headers[0], $matches) === 1) {
         $status = (int) $matches[1];
      }
      if ($status === 0) {
         return Failures::Network;
      }

      return new Response($status, $body, $headers);
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
