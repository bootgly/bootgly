<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\Password;


return new Specification(
   description: 'Password: rehash-on-verify policy and hash migration',
   test: function () {
      // ? Skip on PHP builds compiled without Argon2 support
      if (defined('PASSWORD_ARGON2ID') === false) {
         yield assert(
            assertion: true,
            description: 'skipped: PHP build lacks Argon2 support'
         );
         return;
      }

      $Current = new Password(memory: 65536, time: 4);
      $Legacy = new Password(memory: 19456, time: 2);
      $secret = 'correct horse battery staple';

      $fresh = $Current->hash($secret);

      yield assert(
         assertion: $Current->check($fresh) === true,
         description: 'a fresh hash conforms to the current policy'
      );

      $stale = $Legacy->hash($secret);

      yield assert(
         assertion: $Current->check($stale) === false,
         description: 'a hash minted with weaker costs fails the current policy'
      );

      // ! inspect(): the three policy branches
      $Failed = $Current->inspect('wrong password', $fresh);

      yield assert(
         assertion: $Failed->valid === false && $Failed->hash === null,
         description: 'inspect fails invalid passwords without a rehash'
      );

      $Conformant = $Current->inspect($secret, $fresh);

      yield assert(
         assertion: $Conformant->valid === true && $Conformant->hash === null,
         description: 'inspect passes conformant hashes without a rehash'
      );

      $Upgraded = $Current->inspect($secret, $stale);

      yield assert(
         assertion: $Upgraded->valid === true && $Upgraded->hash !== null,
         description: 'inspect passes stale hashes carrying an upgraded hash'
      );

      yield assert(
         assertion: $Current->verify($secret, (string) $Upgraded->hash) === true
            && $Current->check((string) $Upgraded->hash) === true,
         description: 'the upgraded hash verifies and conforms to the current policy'
      );

      // ! Migration from legacy bcrypt storage
      $bcrypt = password_hash($secret, PASSWORD_BCRYPT);

      yield assert(
         assertion: $Current->verify($secret, $bcrypt) === true
            && $Current->check($bcrypt) === false,
         description: 'legacy bcrypt hashes verify but fail the current policy'
      );

      $Migrated = $Current->inspect($secret, $bcrypt);

      yield assert(
         assertion: $Migrated->valid === true
            && $Migrated->hash !== null
            && str_starts_with((string) $Migrated->hash, '$argon2id$'),
         description: 'inspect migrates bcrypt hashes to argon2id'
      );
   }
);
