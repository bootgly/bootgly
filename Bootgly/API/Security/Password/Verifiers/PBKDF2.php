<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Password\Verifiers;


use function base64_decode;
use function base64_encode;
use function explode;
use function hash;
use function hash_equals;
use function hash_hmac_algos;
use function hash_pbkdf2;
use function in_array;
use function password_get_info;
use function str_starts_with;
use function strlen;
use function substr;
use InvalidArgumentException;
use SensitiveParameter;

use Bootgly\API\Security\Password\Verifier;


/**
 * Verifier of PBKDF2 hashes stored as
 * `<prefix><iterations>:<salt>:<key>` — the iteration count in decimal, the
 * salt and the derived key in standard padded Base64.
 *
 * One layout only: the key is exactly as long as the digest's output (32
 * bytes for `sha256`), the iteration count has no sign and no leading zero,
 * and a row asking for more iterations than the ceiling never verifies. A
 * row in any other shape (another separator, hex or unpadded Base64, a
 * truncated or longer key) answers false — write a `Verifier` for it.
 */
class PBKDF2 implements Verifier
{
   // * Config
   /**
    * Format prefix that marks a stored hash as this verifier's (`v1:`).
    */
   public private(set) string $prefix;
   /**
    * HMAC digest of the derivation (`sha256`, `sha512`, `sha1`, …).
    *
    * @var non-falsy-string
    */
   public private(set) string $digest;
   /**
    * Iteration ceiling — a stored hash asking for more never verifies, so
    * a hostile row cannot pin a worker.
    */
   public private(set) int $ceiling;

   // * Data
   // ...

   // * Metadata
   /**
    * Longest stored hash read, in bytes — a longer row never verifies.
    */
   private const int LIMIT = 1024;
   /**
    * Derived-key length in bytes — the digest's output size.
    */
   private int $length;


   /**
    * Create a PBKDF2 verifier for one stored format.
    *
    * @param string $prefix Format prefix of the stored hashes — never
    *    empty, and never one PHP could read as a native hash: `$2y$…`
    *    (bcrypt is named for such a row when it is exactly 60 bytes long)
    *    or one carrying the identifier of an algorithm PHP names
    *    (`$argon2i$`, `$argon2id$`). A row PHP names an algorithm for is
    *    answered natively and never reaches this verifier.
    * @param string $digest HMAC digest of the derivation.
    * @param int $ceiling Highest iteration count a stored hash may ask
    *    for — set it to the highest count present in the imported rows.
    *
    * @throws InvalidArgumentException When the prefix is empty or starts
    *    like a native hash, the digest is not an HMAC algorithm of this PHP
    *    build or the ceiling is below 1.
    */
   public function __construct (string $prefix, string $digest = 'sha256', int $ceiling = 1_000_000)
   {
      // ?
      if ($prefix === '') {
         throw new InvalidArgumentException('PBKDF2 verifier prefix must not be empty.');
      }
      // ? A prefix PHP could read as a native hash: bcrypt is named for a
      //   `$2y$` row of exactly 60 bytes (a length a row can happen to have),
      //   any other algorithm for every row carrying its identifier. Such a
      //   row is answered natively and never reaches a verifier
      if (str_starts_with($prefix, '$2y$') || password_get_info("{$prefix}1:AAAA:AAAA")['algo'] !== null) {
         throw new InvalidArgumentException('PBKDF2 verifier prefix must not start like a native hash.');
      }
      if (in_array($digest, hash_hmac_algos(), true) === false) {
         throw new InvalidArgumentException("PBKDF2 verifier digest is not an HMAC algorithm: {$digest}.");
      }
      if ($ceiling < 1) {
         throw new InvalidArgumentException('PBKDF2 verifier iteration ceiling must be at least 1.');
      }

      // * Config
      $this->prefix = $prefix;
      $this->digest = $digest;
      $this->ceiling = $ceiling;

      // * Metadata
      $this->length = strlen(hash($digest, '', true));
   }

   /**
    * Verify a password against a stored PBKDF2 hash.
    *
    * Answers false — never throws — for a hash of another format, a
    * malformed one, one above the iteration ceiling or one whose key is
    * not exactly the digest's length. The comparison is constant-time.
    */
   public function verify (#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
   {
      // ? Another format, or a row too long to be one of this format
      if (str_starts_with($hash, $this->prefix) === false || strlen($hash) > self::LIMIT) {
         return false;
      }

      // ! <iterations>:<salt>:<key>
      $parts = explode(':', substr($hash, strlen($this->prefix)));
      // ?
      if (isSet($parts[2]) === false || isSet($parts[3])) {
         return false;
      }
      [$count, $salt, $key] = $parts;

      // ? Iterations: canonical decimal (the integer spells the segment
      //   back — no sign, no leading zero, no overflow), within 1..ceiling
      $iterations = (int) $count;
      if ((string) $iterations !== $count || $iterations < 1 || $iterations > $this->ceiling) {
         return false;
      }

      // ? Salt and key: canonical padded Base64, the key of the digest's length
      $salt = self::decode($salt);
      $expected = self::decode($key);
      if ($salt === null || $expected === null || strlen($expected) !== $this->length) {
         return false;
      }

      // @
      $derived = hash_pbkdf2($this->digest, $password, $salt, $iterations, $this->length, true);

      // :
      return hash_equals($expected, $derived);
   }

   /**
    * Decode one canonical padded Base64 segment, or null when it is not one.
    */
   private static function decode (string $segment): null|string
   {
      $decoded = base64_decode($segment, true);

      // ?: Not Base64, empty, or a non-canonical spelling (unpadded, whitespace)
      if ($decoded === false || $decoded === '' || base64_encode($decoded) !== $segment) {
         return null;
      }

      // :
      return $decoded;
   }
}
