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
use function base64_encode;
use function bin2hex;
use function hash;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rtrim;
use function strtr;
use function time;
use InvalidArgumentException;
use JsonException;
use RuntimeException;


/**
 * Opaque refresh-token lifecycle with rotation and replay detection.
 */
class Tokens
{
   // * Data
   private Cache $Cache;
   public private(set) null|int $timestamp = null;

   // * Metadata
   private int $time {
      get => $this->timestamp ?? time();
   }


   /**
    * Configure a refresh-token manager.
    */
   public function __construct (Cache $Cache)
   {
      // * Data
      $this->Cache = $Cache;
   }

   /**
    * Freeze token time for deterministic checks.
    */
   public function freeze (int $timestamp): self
   {
      if ($timestamp < 0) {
         throw new InvalidArgumentException('JWT token timestamp must not be negative.');
      }

      $this->timestamp = $timestamp;

      return $this;
   }

   /**
    * Resume wall-clock token time.
    */
   public function resume (): self
   {
      $this->timestamp = null;

      return $this;
   }

   /**
    * Mint a new refresh token family.
    *
    * @param array<string,mixed> $claims
    */
   public function mint (string $subject, int $ttl, array $claims = []): Token
   {
      $this->guard($subject, $ttl, $claims);

      return $this->issue($subject, bin2hex(random_bytes(16)), $ttl, $claims);
   }

   /**
    * Inspect an active refresh token.
    */
   public function inspect (string $refresh): null|Token
   {
      $Record = $this->read($refresh);
      if ($Record === null) {
         return null;
      }

      if ($this->Cache->read($this->index('family', $Record['family'])) !== null) {
         return null;
      }

      return $this->cast($refresh, $Record);
   }

   /**
    * Check whether a refresh token is active.
    */
   public function check (string $refresh): bool
   {
      return $this->inspect($refresh) !== null;
   }

   /**
    * Rotate a refresh token, revoking its family on replay.
    */
   public function rotate (string $refresh, int $ttl): null|Replay|Token
   {
      if ($ttl < 1) {
         throw new InvalidArgumentException('JWT refresh token ttl must be positive.');
      }

      $Result = $this->Cache->lock(function () use ($refresh, $ttl): null|Replay|Token {
         $Record = $this->take($refresh);
         if ($Record === null) {
            $Spent = $this->detect($refresh);
            if ($Spent !== null) {
               $this->write($this->index('family', $Spent['family']), '1', $this->limit($Spent['expires']));

               return $this->report($Spent);
            }

            return null;
         }

         if ($this->Cache->read($this->index('family', $Record['family'])) !== null) {
            return null;
         }

         $this->write($this->index('spent', $refresh), $this->encode([
            'subject' => $Record['subject'],
            'family' => $Record['family'],
            'claims' => $Record['claims'],
            'expires' => $Record['expires'],
         ]), $this->limit($Record['expires']));

         return $this->issue($Record['subject'], $Record['family'], $ttl, $Record['claims']);
      });

      if ($Result === null || $Result instanceof Replay || $Result instanceof Token) {
         return $Result;
      }

      throw new RuntimeException('JWT refresh token rotation returned an invalid result.');
   }

   /**
    * Revoke a refresh token family.
    */
   public function revoke (string $refresh): bool
   {
      $Result = $this->Cache->lock(function () use ($refresh): bool {
         $Record = $this->take($refresh);
         if ($Record === null) {
            $Spent = $this->detect($refresh);
            if ($Spent === null) {
               return false;
            }

            $this->write($this->index('family', $Spent['family']), '1', $this->limit($Spent['expires']));

            return true;
         }

         $ttl = $this->limit($Record['expires']);
         $this->write($this->index('spent', $refresh), $this->encode([
            'subject' => $Record['subject'],
            'family' => $Record['family'],
            'claims' => $Record['claims'],
            'expires' => $Record['expires'],
         ]), $ttl);

         $this->write($this->index('family', $Record['family']), '1', $ttl);

         return true;
      });

      return $Result === true;
   }

   /**
    * Write critical refresh-token state.
    */
   private function write (string $key, string $value, int $ttl): void
   {
      if ($this->Cache->write($key, $value, $ttl) === false) {
         throw new RuntimeException('JWT refresh token state could not be stored.');
      }
   }

   /**
    * Issue a refresh token inside an existing family.
    *
    * @param array<string,mixed> $claims
    */
   private function issue (string $subject, string $family, int $ttl, array $claims): Token
   {
      $now = $this->time;
      $expires = $now + $ttl;

      for ($attempt = 0; $attempt < 3; $attempt++) {
         $refresh = $this->generate();
         $Token = new Token($refresh, $subject, $family, $claims, $now, $expires);

         if ($this->Cache->claim($this->index('active', $refresh), $this->encode([
            'subject' => $subject,
            'family' => $family,
            'claims' => $claims,
            'issued' => $now,
            'expires' => $expires,
         ]), $ttl)) {
            return $Token;
         }
      }

      throw new RuntimeException('JWT refresh token could not be stored.');
   }

   /**
    * Read an active refresh-token record.
    *
    * @return null|array{subject:string,family:string,claims:array<string,mixed>,issued:int,expires:int}
    */
   private function read (string $refresh): null|array
   {
      $value = $this->Cache->read($this->index('active', $refresh));
      if ($value === null) {
         return null;
      }

      return $this->decode($value);
   }

   /**
    * Take an active refresh-token record.
    *
    * @return null|array{subject:string,family:string,claims:array<string,mixed>,issued:int,expires:int}
    */
   private function take (string $refresh): null|array
   {
      $value = $this->Cache->take($this->index('active', $refresh));
      if ($value === null) {
         return null;
      }

      return $this->decode($value);
   }

   /**
    * Read a spent refresh-token tombstone.
    *
      * @return null|array{subject:string,family:string,claims:array<string,mixed>,expires:int}
    */
   private function detect (string $refresh): null|array
   {
      $value = $this->Cache->read($this->index('spent', $refresh));
      if ($value === null) {
         return null;
      }

      try {
         $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
      }
      catch (JsonException) {
         return null;
      }

      if (
         is_array($decoded) === false
         || is_string($decoded['subject'] ?? null) === false
         || is_string($decoded['family'] ?? null) === false
         || is_array($decoded['claims'] ?? null) === false
         || is_int($decoded['expires'] ?? null) === false
      ) {
         return null;
      }

      /** @var array<string,mixed> $claims */
      $claims = [];
      foreach ($decoded['claims'] as $name => $value) {
         if (is_string($name) === false) {
            return null;
         }

         $claims[$name] = $value;
      }

      return [
         'subject' => $decoded['subject'],
         'family' => $decoded['family'],
         'claims' => $claims,
         'expires' => $decoded['expires'],
      ];
   }

   /**
    * Encode a refresh-token record.
    *
    * @param array<string,mixed> $Record
    */
   private function encode (array $Record): string
   {
      try {
         return json_encode($Record, JSON_THROW_ON_ERROR);
      }
      catch (JsonException $Exception) {
         throw new RuntimeException('JWT token record JSON encoding failed.', previous: $Exception);
      }
   }

   /**
    * Decode a refresh-token record.
    *
    * @return null|array{subject:string,family:string,claims:array<string,mixed>,issued:int,expires:int}
    */
   private function decode (string $value): null|array
   {
      try {
         $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
      }
      catch (JsonException) {
         return null;
      }

      if (
         is_array($decoded) === false
         || is_string($decoded['subject'] ?? null) === false
         || is_string($decoded['family'] ?? null) === false
         || is_array($decoded['claims'] ?? null) === false
         || is_int($decoded['issued'] ?? null) === false
         || is_int($decoded['expires'] ?? null) === false
      ) {
         return null;
      }

      /** @var array<string,mixed> $claims */
      $claims = [];
      foreach ($decoded['claims'] as $name => $value) {
         if (is_string($name) === false) {
            return null;
         }

         $claims[$name] = $value;
      }

      return [
         'subject' => $decoded['subject'],
         'family' => $decoded['family'],
         'claims' => $claims,
         'issued' => $decoded['issued'],
         'expires' => $decoded['expires'],
      ];
   }

   /**
    * Build a token snapshot from a record.
    *
    * @param array{subject:string,family:string,claims:array<string,mixed>,issued:int,expires:int} $Record
    */
   private function cast (string $refresh, array $Record): Token
   {
      return new Token(
         $refresh,
         $Record['subject'],
         $Record['family'],
         $Record['claims'],
         $Record['issued'],
         $Record['expires']
      );
   }

   /**
    * Build a replay incident from a tombstone.
    *
    * @param array{subject:string,family:string,claims:array<string,mixed>,expires:int} $Record
    */
   private function report (array $Record): Replay
   {
      return new Replay(
         $Record['subject'],
         $Record['family'],
         $Record['claims'],
         $Record['expires']
      );
   }

   /**
    * Validate refresh-token inputs.
    *
    * @param array<int|string,mixed> $claims
    */
   private function guard (string $subject, int $ttl, array $claims): void
   {
      if ($subject === '') {
         throw new InvalidArgumentException('JWT refresh token subject must not be empty.');
      }
      if ($ttl < 1) {
         throw new InvalidArgumentException('JWT refresh token ttl must be positive.');
      }

      foreach ($claims as $name => $_) {
         if (is_string($name) === false) {
            throw new InvalidArgumentException('JWT refresh token claims must be string-keyed.');
         }
      }
   }

   /**
    * Build a refresh-token cache key.
    */
   private function index (string $scope, string $value): string
   {
      return "jwt:refresh:{$scope}:" . hash('sha256', $value);
   }

   /**
    * Create an opaque refresh token.
    */
   private function generate (): string
   {
      return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
   }

   /**
    * Compute a positive TTL from an expiration timestamp.
    */
   private function limit (int $expires): int
   {
      $ttl = $expires - $this->time;

      return $ttl > 0 ? $ttl : 1;
   }
}
