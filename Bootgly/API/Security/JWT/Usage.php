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


use function ctype_digit;
use function hash;
use function is_float;
use function is_int;
use function is_string;
use function time;
use InvalidArgumentException;


/**
 * Persistent JWT `jti` usage and revocation guard.
 */
class Usage
{
   // * Config
   public private(set) bool $single;

   // * Data
   private Cache $Cache;
   public private(set) null|int $timestamp = null;

   // * Metadata
   private int $time {
      get => $this->timestamp ?? time();
   }


   /**
    * Configure a JWT usage guard.
    */
   public function __construct (Cache $Cache, bool $single = false)
   {
      // * Config
      $this->single = $single;

      // * Data
      $this->Cache = $Cache;
   }

   /**
    * Freeze usage time for deterministic checks.
    */
   public function freeze (int $timestamp): self
   {
      if ($timestamp < 0) {
         throw new InvalidArgumentException('JWT usage timestamp must not be negative.');
      }

      $this->timestamp = $timestamp;

      return $this;
   }

   /**
    * Resume wall-clock usage time.
    */
   public function resume (): self
   {
      $this->timestamp = null;

      return $this;
   }

   /**
    * Revoke a JWT identifier for a positive TTL.
    */
   public function block (string $identifier, int $ttl): bool
   {
      $this->guard($identifier, $ttl);

      return $this->Cache->write($this->index('blocked', $identifier), '1', $ttl);
   }

   /**
    * Remove a JWT identifier revocation marker.
    */
   public function drop (string $identifier): bool
   {
      if ($identifier === '') {
         throw new InvalidArgumentException('JWT identifier must not be empty.');
      }

      return $this->Cache->delete($this->index('blocked', $identifier));
   }

   /**
    * Claim a JWT identifier once for a positive TTL.
    */
   public function claim (string $identifier, int $ttl): bool
   {
      $this->guard($identifier, $ttl);

      return $this->Cache->claim($this->index('seen', $identifier), '1', $ttl);
   }

   /**
    * Verify revocation and optional single-use semantics for trusted claims.
    *
    * @param array<string,mixed> $claims
    */
   public function verify (array $claims, int $leeway = 0, null|int $timestamp = null): null|Failures
   {
      $identifier = $claims['jti'] ?? null;
      if (is_string($identifier) === false || $identifier === '') {
         return Failures::Identifier;
      }

      if ($this->Cache->read($this->index('blocked', $identifier)) !== null) {
         return Failures::Revoked;
      }

      if ($this->single === false) {
         return null;
      }

      $ttl = $this->limit($claims, $leeway, $timestamp);
      if ($ttl === null) {
         return Failures::Expired;
      }

      return $this->claim($identifier, $ttl) ? null : Failures::Replay;
   }

   /**
    * Validate identifier storage inputs.
    */
   private function guard (string $identifier, int $ttl): void
   {
      if ($identifier === '') {
         throw new InvalidArgumentException('JWT identifier must not be empty.');
      }
      if ($ttl < 1) {
         throw new InvalidArgumentException('JWT identifier ttl must be positive.');
      }
   }

   /**
    * Compute storage TTL from a verified `exp` claim.
    *
    * @param array<string,mixed> $claims
    */
   private function limit (array $claims, int $leeway, null|int $timestamp): null|int
   {
      $expires = $this->coerce($claims['exp'] ?? null);
      if ($expires === null) {
         return null;
      }

      $ttl = $expires - ($timestamp ?? $this->time) + $leeway;

      return $ttl > 0 ? $ttl : null;
   }

   /**
    * Convert a NumericDate-like value to an integer timestamp.
    */
   private function coerce (mixed $value): null|int
   {
      if (is_int($value)) {
         return $value;
      }

      if (is_float($value)) {
         return (int) $value;
      }

      if (is_string($value) && ctype_digit($value)) {
         return (int) $value;
      }

      return null;
   }

   /**
    * Build an identifier cache key.
    */
   private function index (string $scope, string $identifier): string
   {
      return "jwt:jti:{$scope}:" . hash('sha256', $identifier);
   }
}
