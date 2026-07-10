<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security;


use function defined;
use function password_hash;
use function password_needs_rehash;
use function password_verify;
use InvalidArgumentException;
use RuntimeException;

use Bootgly\API\Security\Password\Verification;


/**
 * Password hashing helper (argon2id) with rehash-on-verify policy.
 *
 * Verification is format-agnostic: hashes minted by other algorithms
 * (e.g. legacy bcrypt) still verify and are upgraded to the current
 * policy through `inspect()`.
 */
class Password
{
   // * Config
   /**
    * Argon2id memory cost in KiB.
    */
   public private(set) int $memory;
   /**
    * Argon2id iterations (time cost).
    */
   public private(set) int $time;
   /**
    * Argon2id parallelism (threads).
    */
   public private(set) int $threads;

   // * Data
   // ...

   // * Metadata
   /**
    * Hashing algorithm — the PASSWORD_ARGON2ID literal, which is
    * undefined on PHP builds compiled without Argon2 support.
    */
   private const string ALGORITHM = 'argon2id';


   /**
    * Create a password hasher.
    *
    * Defaults match PHP's own Argon2 defaults (64 MiB, 4 iterations,
    * 1 thread) and the guards enforce the OWASP minimum (19 MiB, 2
    * iterations, 1 thread).
    *
    * @throws RuntimeException When the PHP build lacks Argon2 support.
    *    This branch cannot run under a CI whose PHP ships Argon2 (the
    *    suite skips instead) — it is smoke-tested manually on such builds.
    * @throws InvalidArgumentException When a cost parameter is below the safe floor.
    */
   public function __construct (int $memory = 65536, int $time = 4, int $threads = 1)
   {
      // * Config
      $this->memory = $memory;
      $this->time = $time;
      $this->threads = $threads;

      // @ Validate.
      $this->guard();
   }

   /**
    * Hash a password with the current policy.
    */
   public function hash (#[\SensitiveParameter] string $password): string
   {
      return password_hash($password, self::ALGORITHM, [
         'memory_cost' => $this->memory,
         'time_cost' => $this->time,
         'threads' => $this->threads
      ]);
   }

   /**
    * Verify a password against a stored hash.
    */
   public function verify (#[\SensitiveParameter] string $password, string $hash): bool
   {
      // ? Empty stored hashes never verify
      if ($hash === '') {
         return false;
      }

      return password_verify($password, $hash);
   }

   /**
    * Check whether a stored hash conforms to the current policy.
    */
   public function check (string $hash): bool
   {
      return password_needs_rehash($hash, self::ALGORITHM, [
         'memory_cost' => $this->memory,
         'time_cost' => $this->time,
         'threads' => $this->threads
      ]) === false;
   }

   /**
    * Verify a password and apply the rehash-on-verify policy.
    *
    * When the password is valid but the stored hash no longer conforms
    * to the current policy, the result carries a fresh hash to persist.
    */
   public function inspect (#[\SensitiveParameter] string $password, string $hash): Verification
   {
      // ? Invalid passwords never trigger a rehash
      if ($this->verify($password, $hash) === false) {
         return Verification::fail();
      }

      // ?: Stored hash already conforms to the current policy
      if ($this->check($hash)) {
         return Verification::pass();
      }

      // : Upgrade the stored hash to the current policy.
      return Verification::pass($this->hash($password));
   }

   /**
    * Validate runtime support and cost parameters.
    */
   private function guard (): void
   {
      if (defined('PASSWORD_ARGON2ID') === false) {
         throw new RuntimeException('Password hashing requires a PHP build with Argon2 support.');
      }

      if ($this->memory < 19456) {
         throw new InvalidArgumentException('Password memory cost must be at least 19456 KiB (19 MiB).');
      }

      if ($this->time < 2) {
         throw new InvalidArgumentException('Password time cost must be at least 2 iterations.');
      }

      if ($this->threads < 1) {
         throw new InvalidArgumentException('Password threads must be at least 1.');
      }
   }
}
