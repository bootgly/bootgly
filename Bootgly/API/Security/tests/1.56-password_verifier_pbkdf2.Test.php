<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\PasswordVerifierPBKDF2;


use function assert;
use function base64_encode;
use function bin2hex;
use function defined;
use function hash_pbkdf2;
use function implode;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;
use function substr;
use InvalidArgumentException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\Password\Verifiers\PBKDF2;


return new Test(
   description: 'Password\Verifiers\PBKDF2: strict format, iteration ceiling and exact key length — a malformed or out-of-bounds row answers false, never throws',
   test: function () {
      $secret = 'correct horse battery staple';
      $salt = 'sixteen-byte-key';
      // ! A row whose key IS the derivation of the secret, for any shape
      $mint = static function (int $iterations, int $length = 32, string $digest = 'sha256', string $prefix = 'v1:') use ($secret, $salt): string {
         $key = hash_pbkdf2($digest, $secret, $salt, $iterations, $length, true);
         $encodedSalt = base64_encode($salt);
         $encodedKey = base64_encode($key);

         return "{$prefix}{$iterations}:{$encodedSalt}:{$encodedKey}";
      };
      $PBKDF2 = new PBKDF2(prefix: 'v1:', ceiling: 1000);

      // # Config
      $Default = new PBKDF2(prefix: 'legacy$');

      yield assert(
         assertion: $PBKDF2->prefix === 'v1:'
            && $PBKDF2->digest === 'sha256'
            && $PBKDF2->ceiling === 1000
            && $Default->prefix === 'legacy$'
            && $Default->digest === 'sha256'
            && $Default->ceiling === 1_000_000,
         description: 'the prefix, the digest and the iteration ceiling are readable; the digest defaults to sha256 and the ceiling to one million'
      );

      // # Iteration ceiling — every row below carries the key correct for it
      yield assert(
         assertion: $PBKDF2->verify($secret, $mint(1000)) === true
            && $PBKDF2->verify($secret, $mint(999)) === true
            && $PBKDF2->verify($secret, $mint(1)) === true,
         description: 'a row at the ceiling, below it and at one iteration verifies'
      );

      yield assert(
         assertion: $PBKDF2->verify($secret, $mint(1001)) === false
            && $PBKDF2->verify($secret, $mint(2000)) === false,
         description: 'a row above the ceiling never verifies, although its key is correct for the password'
      );

      // # Key length — exactly the digest's output
      yield assert(
         assertion: $PBKDF2->verify($secret, $mint(1000, 31)) === false
            && $PBKDF2->verify($secret, $mint(1000, 33)) === false
            && $PBKDF2->verify($secret, $mint(1000, 16)) === false
            && $PBKDF2->verify($secret, $mint(1000, 64)) === false,
         description: 'a key one byte short or one byte long (and a half or a double one) never verifies, although it is the derivation of the password'
      );

      // # Password
      yield assert(
         assertion: $PBKDF2->verify("{$secret}!", $mint(1000)) === false
            && $PBKDF2->verify('', $mint(1000)) === false,
         description: 'a wrong password and an empty one do not verify'
      );

      // # Digest
      $SHA512 = new PBKDF2(prefix: 'v2:', digest: 'sha512', ceiling: 1000);

      yield assert(
         assertion: $SHA512->verify($secret, $mint(1000, 64, 'sha512', 'v2:')) === true
            && $SHA512->verify($secret, $mint(1000, 32, 'sha512', 'v2:')) === false
            && $SHA512->verify($secret, $mint(1000, 32, 'sha256', 'v2:')) === false
            && $PBKDF2->verify($secret, $mint(1000, 32, 'sha512')) === false,
         description: 'the digest is the configured one: a sha512 verifier reads 64-byte sha512 keys and nothing else'
      );

      // # Prefix
      yield assert(
         assertion: $PBKDF2->verify($secret, $mint(1000, prefix: 'v2:')) === false
            && $PBKDF2->verify($secret, $mint(1000, prefix: '')) === false
            && $PBKDF2->verify($secret, $mint(1000, prefix: ' v1:')) === false
            && $PBKDF2->verify($secret, $mint(1000, prefix: 'V1:')) === false,
         description: 'a row of another prefix, of none, or with the prefix not at the start never verifies'
      );

      // # Strict parsing — each row is the valid one with a single defect
      $valid = $mint(1000);
      $encodedSalt = base64_encode($salt);
      $encodedKey = base64_encode(hash_pbkdf2('sha256', $secret, $salt, 1000, 32, true));
      $rawKey = hash_pbkdf2('sha256', $secret, $salt, 1000, 32, true);
      $long = str_repeat('s', 900);
      $encodedLong = base64_encode($long);
      $encodedLongKey = base64_encode(hash_pbkdf2('sha256', $secret, $long, 1000, 32, true));
      $unpaddedKey = rtrim($encodedKey, '=');
      $spacedKey = substr($encodedKey, 0, 8) . "\n" . substr($encodedKey, 8);
      $emptyKey = base64_encode(hash_pbkdf2('sha256', $secret, '', 1000, 32, true));
      $hexKey = bin2hex($rawKey);
      $malformed = [
         'leading zero' => "v1:01000:{$encodedSalt}:{$encodedKey}",
         'explicit sign' => "v1:+1000:{$encodedSalt}:{$encodedKey}",
         'negative' => "v1:-1000:{$encodedSalt}:{$encodedKey}",
         'zero iterations' => "v1:0:{$encodedSalt}:{$encodedKey}",
         'no iterations' => "v1::{$encodedSalt}:{$encodedKey}",
         'non-decimal iterations' => "v1:1e3:{$encodedSalt}:{$encodedKey}",
         'spaced iterations' => "v1: 1000:{$encodedSalt}:{$encodedKey}",
         'eleven digits' => "v1:10000000000:{$encodedSalt}:{$encodedKey}",
         'forty digits' => 'v1:' . str_repeat('9', 40) . ":{$encodedSalt}:{$encodedKey}",
         'two segments' => "v1:1000:{$encodedKey}",
         'four segments' => "v1:1000:{$encodedSalt}:{$encodedKey}:",
         'five segments' => "v1:1000:{$encodedSalt}:{$encodedKey}:extra:more",
         'prefix only' => 'v1:',
         'unpadded key' => "v1:1000:{$encodedSalt}:{$unpaddedKey}",
         'whitespace in key' => "v1:1000:{$encodedSalt}:{$spacedKey}",
         'hex key' => "v1:1000:{$encodedSalt}:{$hexKey}",
         'raw key' => "v1:1000:{$encodedSalt}:{$rawKey}",
         'empty salt' => "v1:1000::{$emptyKey}",
         'empty key' => "v1:1000:{$encodedSalt}:",
         'trailing newline' => "{$valid}\n",
         'row over the length limit' => "v1:1000:{$encodedLong}:{$encodedLongKey}",
      ];
      $legs = [];
      $thrown = [];
      foreach ($malformed as $defect => $row) {
         try {
            if ($PBKDF2->verify($secret, $row) !== false) {
               $legs[] = $defect;
            }
         }
         catch (Throwable) {
            $thrown[] = $defect;
         }
      }
      $failed = implode(', ', $legs);
      $raised = implode(', ', $thrown);

      yield assert(
         assertion: $PBKDF2->verify($secret, $valid) === true
            && strlen($malformed['row over the length limit']) > 1024
            && $legs === []
            && $thrown === [],
         description: "the valid row verifies; the same row with one defect answers false without throwing — verified: [{$failed}], threw: [{$raised}]"
      );

      // # The row limit is exact: 1024 bytes verify, 1025 do not. The
      //   salt is fixed and the prefix padded, so both rows carry the key
      //   correct for the password
      $row = static function (int $length) use ($mint): array {
         $body = $mint(1000, prefix: '');
         $prefix = str_repeat('p', $length - strlen($body) - 1) . ':';

         return [new PBKDF2(prefix: $prefix, ceiling: 1000), "{$prefix}{$body}"];
      };
      [$AtLimit, $atLimit] = $row(1024);
      [$OverLimit, $overLimit] = $row(1025);

      yield assert(
         assertion: strlen($atLimit) === 1024
            && strlen($overLimit) === 1025
            && $AtLimit->verify($secret, $atLimit) === true
            && $OverLimit->verify($secret, $overLimit) === false,
         description: 'a row of exactly 1024 bytes verifies and one of 1025 bytes does not'
      );

      // # The salt is canonical Base64 too — each row carries the key that
      //   is correct for the salt a lenient decoder would read
      $unpaddedSalt = rtrim($encodedSalt, '=');
      $spacedSalt = substr($encodedSalt, 0, 4) . ' ' . substr($encodedSalt, 4);
      $URLSalt = base64_encode("ûÿ¾ salt bytes ");
      $URLKey = base64_encode(hash_pbkdf2('sha256', $secret, "ûÿ¾ salt bytes ", 1000, 32, true));
      $safeSalt = strtr($URLSalt, '+/', '-_');

      yield assert(
         assertion: $encodedSalt !== $unpaddedSalt
            && $URLSalt !== $safeSalt
            && $PBKDF2->verify($secret, "v1:1000:{$URLSalt}:{$URLKey}") === true
            && $PBKDF2->verify($secret, "v1:1000:{$unpaddedSalt}:{$encodedKey}") === false
            && $PBKDF2->verify($secret, "v1:1000:{$spacedSalt}:{$encodedKey}") === false
            && $PBKDF2->verify($secret, "v1:1000:{$safeSalt}:{$URLKey}") === false,
         description: 'an unpadded salt, one with whitespace and a URL-safe one never verify, although the key is correct for the decoded salt'
      );

      // # Constructor guards
      $legs = [];
      $guards = [
         'empty prefix' => static fn () => new PBKDF2(prefix: ''),
         'unknown digest' => static fn () => new PBKDF2(prefix: 'v1:', digest: 'nope'),
         'non-cryptographic digest' => static fn () => new PBKDF2(prefix: 'v1:', digest: 'crc32'),
         'zero ceiling' => static fn () => new PBKDF2(prefix: 'v1:', ceiling: 0),
         'negative ceiling' => static fn () => new PBKDF2(prefix: 'v1:', ceiling: -1),
         'bcrypt prefix' => static fn () => new PBKDF2(prefix: '$2y$'),
         'bcrypt-like prefix' => static fn () => new PBKDF2(prefix: '$2y$legacy:'),
      ];
      // ? PHP names the argon2 identifiers only on a build that has Argon2
      if (defined('PASSWORD_ARGON2ID')) {
         $guards['argon2i prefix'] = static fn () => new PBKDF2(prefix: '$argon2i$');
         $guards['argon2id prefix'] = static fn () => new PBKDF2(prefix: '$argon2id$v=19$');
         $guards['argon2id identifier behind another first byte'] = static fn () => new PBKDF2(prefix: 'xargon2id$');
         $guards['argon2i identifier behind another first byte'] = static fn () => new PBKDF2(prefix: ':argon2i$');
      }
      foreach ($guards as $guard => $build) {
         try {
            $build();
            $legs[] = $guard;
         }
         catch (InvalidArgumentException) {
            // ...refused, as expected
         }
         catch (Throwable) {
            $legs[] = "{$guard} (another throwable)";
         }
      }
      $failed = implode(', ', $legs);

      yield assert(
         assertion: $legs === [],
         description: "an empty prefix, a prefix PHP could read as a native hash, a digest that is not an HMAC algorithm and a ceiling below one are refused with InvalidArgumentException — accepted: [{$failed}]"
      );

      // # A prefix that only resembles a native one is accepted
      $Dollar = new PBKDF2(prefix: '$pbkdf2$', ceiling: 1000);

      yield assert(
         assertion: $Dollar->verify($secret, $mint(1000, prefix: '$pbkdf2$')) === true
            && new PBKDF2(prefix: '$2a$x:')->prefix === '$2a$x:'
            && new PBKDF2(prefix: 'x$2y$:', ceiling: 1000)->verify($secret, $mint(1000, prefix: 'x$2y$:')) === true,
         description: 'a prefix PHP names no algorithm for is accepted — a dollar-delimited one and one that only contains a native tag included'
      );

      // # The prefix is anchored at the start of the row: a row that only
      //   CONTAINS it never verifies, although what follows it parses and
      //   its key is correct for the salt found there
      $Anchored = new PBKDF2(prefix: 'AAAA', ceiling: 1000);
      $inner = base64_encode(hash_pbkdf2('sha256', $secret, "\0\0\0", 1000, 32, true));

      yield assert(
         assertion: $Anchored->verify($secret, "AAAA1000:AAAA:{$inner}") === true
            && $Anchored->verify($secret, "BBBB1000:AAAA:{$inner}") === false,
         description: 'a row that contains the prefix elsewhere than at its start never verifies'
      );

      // # The stored iteration count is the one derived with, far above
      //   the small counts the other legs use
      yield assert(
         assertion: $Default->verify($secret, $mint(20_000, prefix: 'legacy$')) === true
            && $Default->verify("{$secret}!", $mint(20_000, prefix: 'legacy$')) === false,
         description: 'a row of 20 000 iterations verifies against the default ceiling with its password only'
      );
   }
);
