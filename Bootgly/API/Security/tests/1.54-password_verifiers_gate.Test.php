<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\PasswordVerifiersGate;


use const PASSWORD_ARGON2I;
use const PASSWORD_BCRYPT;
use function assert;
use function base64_encode;
use function defined;
use function implode;
use function password_hash;
use function rtrim;
use function str_repeat;
use SensitiveParameter;
use stdClass;
use Throwable;
use TypeError;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Password\Verifier;


/**
 * Verifier that accepts every hash and records the ones it was asked for.
 */
final class Acceptor implements Verifier
{
   // * Metadata
   /** @var array<int,string> */
   public private(set) array $asked = [];
   /** @var array<int,string> */
   public private(set) array $typed = [];


   public function verify (#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
   {
      $this->asked[] = $hash;
      $this->typed[] = $password;

      return true;
   }
}


return new Test(
   description: 'Password: verifiers are asked only on a native miss with no recognised algorithm — a native hash, the decoy and an empty hash never reach one',
   skip: defined('PASSWORD_ARGON2ID') === false,
   test: function () {
      $secret = 'correct horse battery staple';
      $Mint = new Password(memory: 19456, time: 2, threads: 1);
      // ! The decoy `Users` verifies for an unknown identifier: an argon2id
      //   PHC string of the policy with a zero salt and a zero tag
      $salt = rtrim(base64_encode(str_repeat("\0", 16)), '=');
      $tag = rtrim(base64_encode(str_repeat("\0", 32)), '=');
      $rows = [
         '$2y$' => password_hash($secret, PASSWORD_BCRYPT, ['cost' => 4]),
         'argon2i' => password_hash($secret, PASSWORD_ARGON2I, ['memory_cost' => 19456, 'time_cost' => 2]),
         'argon2id' => $Mint->hash($secret),
         'decoy' => "\$argon2id\$v=19\$m=19456,t=2,p=1\${$salt}\${$tag}",
         'truncated argon2id' => '$argon2id$v=19$m=19456,t=2,p=1$',
         'empty' => '',
      ];

      // @ A WRONG password against every native row, with an accept-all verifier
      $legs = [];
      foreach ($rows as $format => $hash) {
         $Acceptor = new Acceptor;
         $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Acceptor]);
         $verified = $Password->verify("{$secret}!", $hash);
         $Inspected = $Password->inspect("{$secret}!", $hash);

         if (
            $verified !== false
            || $Inspected->valid !== false
            || $Inspected->hash !== null
            || $Acceptor->asked !== []
         ) {
            $legs[] = $format;
         }
      }
      $failed = implode(', ', $legs);

      yield assert(
         assertion: $legs === [],
         description: "a wrong password against a native hash, the decoy and an empty hash answers false and the accept-all verifier is asked zero times — failed: [{$failed}]"
      );

      // @ The RIGHT password against the native rows: answered natively
      $legs = [];
      foreach (['$2y$', 'argon2i', 'argon2id'] as $format) {
         $Acceptor = new Acceptor;
         $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Acceptor]);

         if ($Password->verify($secret, $rows[$format]) !== true || $Acceptor->asked !== []) {
            $legs[] = $format;
         }
      }
      $failed = implode(', ', $legs);

      yield assert(
         assertion: $legs === [],
         description: "the right password against a native hash verifies without asking a verifier — failed: [{$failed}]"
      );

      // @ Positive control: a format PHP cannot read reaches the verifier, intact
      $legacy = 'legacy:not-a-native-format';
      $Acceptor = new Acceptor;
      $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Acceptor]);

      yield assert(
         assertion: $Password->verify($secret, $legacy) === true && $Acceptor->asked === [$legacy],
         description: 'a hash of no native format reaches the verifier once, unchanged, and its answer is the verification'
      );

      yield assert(
         assertion: new Password(memory: 19456, time: 2, threads: 1)->verify($secret, $legacy) === false,
         description: 'without verifiers a hash of no native format never verifies'
      );

      // @ Order: the first verifier that accepts wins, the rest are not asked
      $First = new Acceptor;
      $Second = new Acceptor;
      $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: ['first' => $First, 'second' => $Second]);

      yield assert(
         assertion: $Password->verify($secret, $legacy) === true
            && $First->asked === [$legacy]
            && $Second->asked === [],
         description: 'verifiers are asked in the order given (keys ignored) and the first acceptance ends the search'
      );

      // @ A list with mixed keys is a list: every verifier is kept, in order
      $First = new Acceptor;
      $Second = new Acceptor;
      $mixed = null;
      try {
         $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: ['named' => $First, $Second]);
         $mixed = $Password->verify($secret, $legacy);
      }
      catch (Throwable) {
         $mixed = null;
      }

      yield assert(
         assertion: $mixed === true && $First->asked === [$legacy] && $Second->asked === [],
         description: 'a verifier list mixing string and integer keys is accepted and asked in order'
      );

      // @ The verifier receives the password and the hash byte for byte
      $padded = " legacy:spaced \t\n";
      $typed = "  spaced secret \n";
      $Acceptor = new Acceptor;
      $Password = new Password(memory: 19456, time: 2, threads: 1, Verifiers: [$Acceptor]);
      $Password->verify($typed, $padded);

      yield assert(
         assertion: $Acceptor->asked === [$padded] && $Acceptor->typed === [$typed],
         description: 'the verifier receives the password and the stored hash unchanged, surrounding whitespace included'
      );

      // @ The constructor refuses what is not a verifier
      $refused = false;
      try {
         // @phpstan-ignore argument.type
         new Password(memory: 19456, time: 2, threads: 1, Verifiers: [new Acceptor, new stdClass]);
      }
      catch (TypeError) {
         $refused = true;
      }
      catch (Throwable) {
         $refused = false;
      }

      yield assert(
         assertion: $refused === true,
         description: 'an element that does not implement Password\Verifier is refused with a TypeError'
      );
   }
);
