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
   /**
    * Seconds a family's revocation marker outlives the horizon it covers:
    * absorbs clock skew between hosts and a rotation stalled at the edge of
    * its token's expiry.
    */
   private const int SLACK = 60;

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
    *
    * The token is consumed by the store's atomic take, after its successor
    * and its tombstone are stored — so every consumer that misses it, on any
    * host of a shared store, finds the tombstone: a concurrent double-submit
    * yields at most one `Token`, and the other caller gets a `Replay` (or
    * `null`) with the family revoked until its newest token would expire.
    * `lock()` only serializes callers on one host.
    *
    * @return null|Replay|Token The successor; a `Replay` when the token was
    *    already consumed (the family is revoked); `null` when it is unknown,
    *    expired (also when it expires during this rotation, by this host's
    *    clock within the tolerated skew) or of a revoked family — it is not
    *    consumed, unless a revocation lands during the rotation.
    * @throws RuntimeException When the store refuses a write or the evict:
    *    the presented token stays valid for a retry — unless it was already
    *    consumed, then the retry is a `Replay`.
    */
   public function rotate (string $refresh, int $ttl): null|Replay|Token
   {
      if ($ttl < 1) {
         throw new InvalidArgumentException('JWT refresh token ttl must be positive.');
      }

      $Result = $this->Cache->lock(function () use ($refresh, $ttl): null|Replay|Token {
         // ! The presented token — nothing is destroyed yet
         $Record = $this->read($refresh);
         // ? Not active: a spent token is a replay, anything else unknown
         if ($Record === null) {
            $Spent = $this->detect($refresh);
            if ($Spent === null) {
               return null;
            }

            $this->ban($Spent['family'], $Spent['horizon']);

            return $this->report($Spent);
         }
         // ? A revoked family rotates nothing: the token is not consumed
         if ($this->Cache->read($this->index('family', $Record['family'])) !== null) {
            return null;
         }

         // @ The successor first: a refused claim throws with the token intact
         $Token = $this->issue($Record['subject'], $Record['family'], $ttl, $Record['claims']);
         $successor = $this->index('active', $Token->refresh);
         $horizon = $Record['expires'] > $Token->expires ? $Record['expires'] : $Token->expires;
         // @ The tombstone before the token can disappear: a consumer that
         //   misses the token always finds it
         try {
            $this->write($this->index('spent', $refresh), $this->encode([
               'subject' => $Record['subject'],
               'family' => $Record['family'],
               'claims' => $Record['claims'],
               'expires' => $Record['expires'],
               'horizon' => $horizon,
            ]), $this->limit($horizon));
         }
         catch (RuntimeException $Exception) {
            $this->Cache->delete($successor);

            throw $Exception;
         }

         // @ The election: one consumer evicts the token
         if ($this->take($refresh) === null) {
            $this->Cache->delete($successor);
            // ? Still active: the store refused the evict — nobody consumed it
            if ($this->read($refresh) !== null) {
               throw new RuntimeException('JWT refresh token could not be consumed.');
            }
            // ?: Expired during this rotation — by this host's clock, within the
            //   skew tolerated between hosts: an expired token, not a replay.
            //   Its family is revoked first (nothing a last-instant consumer
            //   stored survives a failure past this point), then our tombstone goes
            if ($Record['expires'] <= $this->time + self::SLACK) {
               $this->ban($Record['family'], $horizon);
               $this->Cache->delete($this->index('spent', $refresh));

               return null;
            }

            // : Another consumer won: a replay (our record stands in for a
            //   tombstone the store evicted)
            $Spent = $this->detect($refresh) ?? [
               'subject' => $Record['subject'],
               'family' => $Record['family'],
               'claims' => $Record['claims'],
               'expires' => $Record['expires'],
               'horizon' => $horizon,
            ];
            $this->ban($Spent['family'], $Spent['horizon'] > $horizon ? $Spent['horizon'] : $horizon);

            return $this->report($Spent);
         }

         // @ The family horizon, then the revocation re-check: ban() writes
         //   the marker and reads the horizon, so one of the two sees the other
         try {
            $this->write($this->index('horizon', $Record['family']), (string) $Token->expires, $this->limit($Token->expires));
         }
         catch (RuntimeException $Exception) {
            $this->Cache->delete($successor);

            throw $Exception;
         }
         if ($this->Cache->read($this->index('family', $Record['family'])) !== null) {
            $this->Cache->delete($successor);
            $this->ban($Record['family'], $horizon);

            return null;
         }

         // :
         return $Token;
      });

      if ($Result === null || $Result instanceof Replay || $Result instanceof Token) {
         return $Result;
      }

      throw new RuntimeException('JWT refresh token rotation returned an invalid result.');
   }

   /**
    * Revoke a refresh token family.
    *
    * The family is revoked first, until its newest token would expire — a
    * logout racing a rotation of the same token returns `true` and leaves no
    * successor alive.
    *
    * @return bool `false` only for an unknown or expired token.
    * @throws RuntimeException When the store refuses a write: a retry with
    *    the same token completes the revocation.
    */
   public function revoke (string $refresh): bool
   {
      $Result = $this->Cache->lock(function () use ($refresh): bool {
         // ! The presented token
         $Record = $this->read($refresh);
         // ? Not active: a spent token revokes its family, anything else is unknown
         if ($Record === null) {
            $Spent = $this->detect($refresh);
            if ($Spent === null) {
               return false;
            }

            $this->ban($Spent['family'], $Spent['horizon']);

            return true;
         }

         // @ The family dies first; the rest is cleanup a retry redoes
         $horizon = $this->ban($Record['family'], $Record['expires']);
         $this->write($this->index('spent', $refresh), $this->encode([
            'subject' => $Record['subject'],
            'family' => $Record['family'],
            'claims' => $Record['claims'],
            'expires' => $Record['expires'],
            'horizon' => $horizon,
         ]), $this->limit($horizon));
         $this->take($refresh);

         // :
         return true;
      });

      return $Result === true;
   }

   /**
    * Revoke a family until its horizon — the expiry of its newest token —
    * and return the horizon covered.
    *
    * The marker holds the horizon it covers and is never written below one
    * already known (an earlier ban's, the family's horizon key). After the
    * write it reads the horizon key again: a rotation that stored a newer
    * successor writes that key before it re-reads the marker, so one of the
    * two always sees the other.
    */
   private function ban (string $family, int $horizon): int
   {
      $marker = $this->index('family', $family);
      $key = $this->index('horizon', $family);

      // ! The longest horizon already known
      foreach ([$this->Cache->read($marker), $this->Cache->read($key)] as $known) {
         if ($known !== null && $known === (string) (int) $known && (int) $known > $horizon) {
            $horizon = (int) $known;
         }
      }
      $this->write($marker, (string) $horizon, $this->limit($horizon) + self::SLACK);

      // @ A newer successor stored meanwhile extends it
      $latest = $this->Cache->read($key);
      if ($latest !== null && $latest === (string) (int) $latest && (int) $latest > $horizon) {
         $horizon = (int) $latest;
         $this->write($marker, (string) $horizon, $this->limit($horizon) + self::SLACK);
      }

      return $horizon;
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
    * Its `horizon` — the expiry of the family's newest token when it was
    * written — falls back to the consumed token's expiry for a tombstone that
    * carries none (written by an earlier release).
    *
    * @return null|array{subject:string,family:string,claims:array<string,mixed>,expires:int,horizon:int}
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

      $horizon = $decoded['expires'];
      if (is_int($decoded['horizon'] ?? null) && $decoded['horizon'] > $horizon) {
         $horizon = $decoded['horizon'];
      }

      return [
         'subject' => $decoded['subject'],
         'family' => $decoded['family'],
         'claims' => $claims,
         'expires' => $decoded['expires'],
         'horizon' => $horizon,
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
    * @param array{subject:string,family:string,claims:array<string,mixed>,expires:int,horizon:int} $Record
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
