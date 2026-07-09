<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\Encrypter;
use Bootgly\API\Security\Encrypter\Key;
use Bootgly\API\Security\Encrypter\Keyring;


return new Specification(
   description: 'Encrypter: Additional Authenticated Data binds context',
   test: function () {
      $Encrypter = new Encrypter(random_bytes(32));
      $plaintext = 'attribute value';
      $envelope = $Encrypter->encrypt($plaintext, AAD: 'user-1');

      yield assert(
         assertion: $Encrypter->decrypt($envelope, AAD: 'user-1') === $plaintext,
         description: 'matching AAD decrypts'
      );

      yield assert(
         assertion: $Encrypter->decrypt($envelope) === null,
         description: 'missing AAD fails authentication'
      );

      yield assert(
         assertion: $Encrypter->decrypt($envelope, AAD: 'user-2') === null,
         description: 'different AAD fails authentication'
      );

      $bare = $Encrypter->encrypt($plaintext);

      yield assert(
         assertion: $Encrypter->decrypt($bare, AAD: 'user-1') === null
            && $Encrypter->decrypt($bare) === $plaintext,
         description: 'AAD supplied for an AAD-less envelope fails authentication'
      );

      // ! Key id is authenticated: same material under two ids — swapping the
      // ! kid segment resolves a valid key but breaks the envelope prefix AAD
      $material = random_bytes(32);
      $Twins = new Encrypter(new Keyring(
         new Key($material, 'a'),
         new Key($material, 'b')
      ));

      $under = $Twins->encrypt($plaintext);
      [, , $blob] = explode('.', $under);
      $spliced = "v1.b.{$blob}";

      yield assert(
         assertion: $Twins->decrypt($under) === $plaintext
            && $Twins->decrypt($spliced) === null,
         description: 'kid segment participates in authentication (splice rejected)'
      );
   }
);
