<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\Password;


return new Specification(
   description: 'Password: argon2id hashing and verification',
   test: function () {
      // ? Skip on PHP builds compiled without Argon2 support
      if (defined('PASSWORD_ARGON2ID') === false) {
         yield assert(
            assertion: true,
            description: 'skipped: PHP build lacks Argon2 support'
         );
         return;
      }

      // ! OWASP floor costs keep the spec fast
      $Password = new Password(memory: 19456, time: 2);
      $hash = $Password->hash('correct horse battery staple');

      yield assert(
         assertion: str_starts_with($hash, '$argon2id$'),
         description: 'hashes use the argon2id format'
      );

      $info = password_get_info($hash);

      yield assert(
         assertion: $info['options'] === ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1],
         description: 'hashes carry the configured cost parameters'
      );

      yield assert(
         assertion: $Password->verify('correct horse battery staple', $hash) === true,
         description: 'the original password verifies'
      );

      yield assert(
         assertion: $Password->verify('wrong password', $hash) === false,
         description: 'a wrong password fails verification'
      );

      yield assert(
         assertion: $Password->verify('correct horse battery staple', '') === false,
         description: 'an empty stored hash never verifies'
      );

      $unicode = 'sênha-çom-acentós — 密码 🔑';

      yield assert(
         assertion: $Password->verify($unicode, $Password->hash($unicode)) === true,
         description: 'unicode passwords roundtrip'
      );

      $long = str_repeat('a', 100); // beyond bcrypt's 72-byte limit

      yield assert(
         assertion: $Password->verify($long, $Password->hash($long)) === true
            && $Password->verify(str_repeat('a', 73), $Password->hash($long)) === false,
         description: 'argon2id has no 72-byte truncation'
      );

      yield assert(
         assertion: $Password->verify('', $Password->hash('')) === true,
         description: 'empty passwords roundtrip (validation is the app\'s job)'
      );

      // ! Cost parameter guards
      $guards = [
         'memory below 19456 KiB' => static fn () => new Password(memory: 1024),
         'time below 2 iterations' => static fn () => new Password(time: 1),
         'zero threads' => static fn () => new Password(threads: 0),
      ];
      foreach ($guards as $case => $Guard) {
         $failed = false;
         try {
            $Guard();
         }
         catch (InvalidArgumentException) {
            $failed = true;
         }

         yield assert(
            assertion: $failed === true,
            description: "constructor rejects {$case}"
         );
      }
   }
);
