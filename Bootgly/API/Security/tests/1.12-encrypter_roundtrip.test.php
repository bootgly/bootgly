<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\Encrypter;
use Bootgly\API\Security\Encrypter\Key;
use Bootgly\API\Security\Encrypter\Keyring;


return new Specification(
   description: 'Encrypter: AES-256-GCM envelope roundtrips',
   test: function () {
      $material = random_bytes(32);
      $Encrypter = new Encrypter($material);

      $plaintext = 'The quick brown fox jumps over the lazy dog';
      $envelope = $Encrypter->encrypt($plaintext);

      yield assert(
         assertion: $Encrypter->decrypt($envelope) === $plaintext,
         description: 'plain string roundtrips'
      );

      yield assert(
         assertion: $Encrypter->decrypt($Encrypter->encrypt('')) === '',
         description: 'empty plaintext roundtrips'
      );

      $binary = random_bytes(256);

      yield assert(
         assertion: $Encrypter->decrypt($Encrypter->encrypt($binary)) === $binary,
         description: 'binary plaintext roundtrips'
      );

      $large = str_repeat('bootgly-', 131072); // ~1 MiB

      yield assert(
         assertion: $Encrypter->decrypt($Encrypter->encrypt($large)) === $large,
         description: 'large (~1 MiB) plaintext roundtrips'
      );

      $unicode = 'Criptografia: chave secreta — 鍵 🔐';

      yield assert(
         assertion: $Encrypter->decrypt($Encrypter->encrypt($unicode)) === $unicode,
         description: 'UTF-8 multibyte plaintext roundtrips'
      );

      // ! Envelope shape: version prefix, 3 segments, kid segment
      $segments = explode('.', $envelope);

      yield assert(
         assertion: str_starts_with($envelope, 'v1.')
            && count($segments) === 3
            && $segments[1] === '',
         description: 'id-less envelopes carry v1 version and an empty kid segment'
      );

      $Keyed = new Encrypter(new Key($material, 'k2026'));
      $keyed = explode('.', $Keyed->encrypt($plaintext));

      yield assert(
         assertion: count($keyed) === 3 && $keyed[1] === 'k2026',
         description: 'keyed envelopes carry the key id in the second segment'
      );

      yield assert(
         assertion: $Encrypter->encrypt($plaintext) !== $Encrypter->encrypt($plaintext),
         description: 'encryption is non-deterministic (fresh IV per call)'
      );

      // ! Constructor accepts raw material, a Key or a Keyring equivalently
      $FromKey = new Encrypter(new Key($material));
      $FromKeyring = new Encrypter(new Keyring(new Key($material)));

      yield assert(
         assertion: $FromKey->decrypt($envelope) === $plaintext
            && $FromKeyring->decrypt($envelope) === $plaintext,
         description: 'raw material, Key and Keyring constructions interoperate'
      );
   }
);
