<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\Encrypter;
use Bootgly\API\Security\Encrypter\Key;
use Bootgly\API\Security\Encrypter\Keyring;


return new Specification(
   description: 'Encrypter: key guards, keyring resolution and rotation',
   test: function () {
      // ! Key material and id guards
      $guards = [
         '16-byte material' => static fn () => new Key(random_bytes(16)),
         '33-byte material' => static fn () => new Key(random_bytes(33)),
         'empty id' => static fn () => new Key(random_bytes(32), ''),
         'dotted id' => static fn () => new Key(random_bytes(32), 'a.b'),
         'invalid base64 import' => static fn () => Key::import('not-base64!!!'),
         'short material import' => static fn () => Key::import(base64_encode(random_bytes(16))),
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
            description: "key guard rejects {$case}"
         );
      }

      $Generated = Key::generate('k1');

      yield assert(
         assertion: strlen($Generated->material) === 32 && $Generated->id === 'k1',
         description: 'generate mints 32 bytes of material with the given id'
      );

      $encoded = base64_encode($Generated->material);

      yield assert(
         assertion: Key::import($encoded, 'k1')->material === $Generated->material,
         description: 'import restores base64-encoded material'
      );

      // ! Keyring uniqueness guards
      $duplicated = false;
      try {
         new Keyring(Key::generate('k1'), Key::generate('k1'));
      }
      catch (InvalidArgumentException) {
         $duplicated = true;
      }

      yield assert(
         assertion: $duplicated === true,
         description: 'keyring rejects duplicate key ids'
      );

      $defaulted = false;
      try {
         new Keyring(Key::generate(), Key::generate());
      }
      catch (InvalidArgumentException) {
         $defaulted = true;
      }

      yield assert(
         assertion: $defaulted === true,
         description: 'keyring rejects a second id-less key'
      );

      // ! Resolution
      $K1 = Key::generate('k1');
      $Bare = Key::generate();
      $Keyring = new Keyring($K1, $Bare);

      yield assert(
         assertion: $Keyring->resolve('k1') === $K1
            && $Keyring->resolve(null) === $Bare
            && $Keyring->resolve('unknown') === null
            && $Keyring->resolve('') === null,
         description: 'resolve maps ids to keys, null to the id-less slot and rejects unknowns'
      );

      // ! Rotation
      $Ring = new Keyring(Key::generate('k1'));
      $Encrypter = new Encrypter($Ring);
      $old = $Encrypter->encrypt('payload');

      $K2 = Key::generate('k2');
      $Encrypter->Keyring->rotate($K2);
      $new = $Encrypter->encrypt('payload');

      yield assert(
         assertion: explode('.', $new)[1] === 'k2'
            && $Encrypter->Keyring->Primary === $K2,
         description: 'rotate promotes the new primary for encryption'
      );

      yield assert(
         assertion: $Encrypter->decrypt($old) === 'payload'
            && $Encrypter->decrypt($new) === 'payload',
         description: 'rotated keyring still decrypts old envelopes'
      );

      $conflicted = false;
      try {
         $Encrypter->Keyring->rotate(Key::generate('k1'));
      }
      catch (InvalidArgumentException) {
         $conflicted = true;
      }

      yield assert(
         assertion: $conflicted === true && $Encrypter->Keyring->Primary === $K2,
         description: 'rotation conflicts throw before the primary changes'
      );

      $Retired = new Encrypter(new Keyring($K2));

      yield assert(
         assertion: $Retired->decrypt($new) === 'payload'
            && $Retired->decrypt($old) === null,
         description: 'a keyring without the retired key no longer decrypts its envelopes'
      );
   }
);
