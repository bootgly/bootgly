<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\Encrypter;
use Bootgly\API\Security\Encrypter\Key;
use Bootgly\API\Security\Encrypter\Keyring;


return new Specification(
   description: 'Encrypter: key guards, keyring resolution and rotation',
   test: function () {
      // ! Key material and id guards — ids follow [A-Za-z0-9_-]{1,64}
      $guards = [
         '16-byte material' => static fn () => new Key(random_bytes(16)),
         '33-byte material' => static fn () => new Key(random_bytes(33)),
         'empty id' => static fn () => new Key(random_bytes(32), ''),
         'dotted id' => static fn () => new Key(random_bytes(32), 'a.b'),
         'id with space' => static fn () => new Key(random_bytes(32), 'k 1'),
         'id with newline' => static fn () => new Key(random_bytes(32), "k1\n"),
         'id with control character' => static fn () => new Key(random_bytes(32), "k\x01"),
         '65-character id' => static fn () => new Key(random_bytes(32), str_repeat('k', 65)),
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

      yield assert(
         assertion: new Key(random_bytes(32), 'k2026_07-A')->id === 'k2026_07-A'
            && new Key(random_bytes(32), str_repeat('k', 64))->id === str_repeat('k', 64),
         description: 'key guard accepts conservative ids up to 64 characters'
      );

      $Generated = Key::generate('k1');
      $Probe = new Encrypter($Generated);

      yield assert(
         assertion: $Generated->id === 'k1'
            && $Probe->decrypt($Probe->encrypt('probe')) === 'probe',
         description: 'generate mints a working key with the given id'
      );

      $material = random_bytes(32);
      $Imported = new Encrypter(Key::import(base64_encode($material)));
      $cross = new Encrypter(new Key($material))->encrypt('probe');

      yield assert(
         assertion: $Imported->decrypt($cross) === 'probe',
         description: 'import restores base64-encoded material'
      );

      // ! Secrecy: raw material never leaks through public surfaces
      $secret = random_bytes(32);
      $Secret = new Key($secret, 'k9');

      ob_start();
      var_dump($Secret);
      $dumped = (string) ob_get_clean();

      yield assert(
         assertion: str_contains($dumped, '[redacted]')
            && str_contains($dumped, $secret) === false
            && json_encode($Secret) === '{"id":"k9"}',
         description: 'key material is redacted from var_dump and absent from JSON'
      );

      $refused = false;
      try {
         serialize($Secret);
      }
      catch (LogicException) {
         $refused = true;
      }

      yield assert(
         assertion: $refused === true,
         description: 'keys refuse serialization'
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
