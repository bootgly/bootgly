<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security;


use function array_values;
use function defined;
use function get_debug_type;
use function password_get_info;
use function password_hash;
use function password_needs_rehash;
use function password_verify;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TypeError;

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\API\Security\Password\Verification;
use Bootgly\API\Security\Password\Verifier;


/**
 * Password hashing helper (argon2id) with rehash-on-verify policy.
 *
 * Verification reads every format `password_verify()` reads: hashes minted
 * by other algorithms (legacy bcrypt, argon2i, the crypt-family hashes of
 * other stacks) still verify and are upgraded to the current policy through
 * `inspect()`. A format PHP cannot read (PBKDF2 strings, salted digests
 * imported from another stack) verifies through the configured
 * `Password\Verifier`s, and is upgraded the same way.
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
   /**
    * Legacy-format verifiers, asked in order for a stored hash the native
    * algorithms did not accept and do not recognise.
    *
    * @var array<Verifier>
    */
   private array $Verifiers = [];

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
    * @param array<Verifier> $Verifiers Legacy-format verifiers, asked in
    *    order for a stored hash `password_verify()` did not accept and that
    *    names no native algorithm (accounts imported from another stack).
    *
    * @throws RuntimeException When the PHP build lacks Argon2 support.
    *    This branch cannot run under a CI whose PHP ships Argon2 (the
    *    suite skips instead) — it is smoke-tested manually on such builds.
    * @throws InvalidArgumentException When a cost parameter is below the safe floor.
    * @throws TypeError When a verifier does not implement `Password\Verifier`.
    */
   public function __construct (int $memory = 65536, int $time = 4, int $threads = 1, array $Verifiers = [])
   {
      // * Config
      $this->memory = $memory;
      $this->time = $time;
      $this->threads = $threads;

      // * Data
      $this->attach(...array_values($Verifiers));

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
    *
    * Native formats first: `password_verify()` is asked for every hash, and
    * a hash `password_hash()` can mint (`$2y$`, argon2i, argon2id) is
    * answered by it alone, whatever the password. The configured verifiers
    * are asked, in order, only for a hash it did not accept AND that names
    * no native algorithm — a legacy format, or a crypt-family hash of
    * another stack (`$2a$`, `$6$`) that the password did not open. A
    * verifier that throws fails the verification: the throwable is reported
    * through `Throwables::notify()` and no other verifier is asked for that
    * hash.
    */
   public function verify (#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
   {
      // ? Empty stored hashes never verify
      if ($hash === '') {
         return false;
      }

      // ?: Native formats — every hash password_verify() reads
      if (password_verify($password, $hash)) {
         return true;
      }

      // ? No verifier to ask, or a native hash (a wrong password, a decoy)
      if ($this->Verifiers === [] || password_get_info($hash)['algo'] !== null) {
         return false;
      }

      // @@ Legacy formats — the first verifier that accepts the hash wins
      foreach ($this->Verifiers as $Verifier) {
         try {
            if ($Verifier->verify($password, $hash) === true) {
               return true;
            }
         }
         catch (Throwable $Throwable) {
            // ! A verifier that throws never yields a pass: reported, and
            //   the sign-in fails as a wrong password does
            Throwables::notify($Throwable, ['origin' => 'password', 'verifier' => get_debug_type($Verifier)]);

            return false;
         }
      }

      // :
      return false;
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
   public function inspect (#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): Verification
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
    * Keep the legacy-format verifiers — the signature is the type check.
    */
   private function attach (Verifier ...$Verifiers): void
   {
      // * Data
      $this->Verifiers = $Verifiers;
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
