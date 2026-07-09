<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\Encrypter;
use Bootgly\API\Security\Encrypter\Key;


return new Specification(
   description: 'Encrypter: tampered or malformed envelopes decrypt to null',
   test: function () {
      // ! Local base64url helpers to surgically corrupt envelope blobs
      $unpack = static function (string $value): string {
         $base64 = strtr($value, '-_', '+/');
         $remainder = strlen($base64) % 4;
         if ($remainder !== 0) {
            $base64 .= str_repeat('=', 4 - $remainder);
         }
         return (string) base64_decode($base64, true);
      };
      $pack = static function (string $value): string {
         return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
      };
      $corrupt = static function (string $envelope, int $offset) use ($pack, $unpack): string {
         [$version, $id, $blob] = explode('.', $envelope);
         $raw = $unpack($blob);
         $raw[$offset] = $raw[$offset] === "\x00" ? "\x01" : "\x00";
         return "{$version}.{$id}." . $pack($raw);
      };

      $Encrypter = new Encrypter(new Key(random_bytes(32), 'k1'));
      $plaintext = 'sensitive payload';
      $envelope = $Encrypter->encrypt($plaintext);
      [$version, $id, $blob] = explode('.', $envelope);
      $raw = $unpack($blob);

      yield assert(
         assertion: $Encrypter->decrypt($corrupt($envelope, 15)) === null,
         description: 'flipped ciphertext byte fails authentication'
      );

      yield assert(
         assertion: $Encrypter->decrypt($corrupt($envelope, 0)) === null,
         description: 'flipped IV byte fails authentication'
      );

      yield assert(
         assertion: $Encrypter->decrypt($corrupt($envelope, strlen($raw) - 1)) === null,
         description: 'flipped tag byte fails authentication'
      );

      $truncated = "{$version}.{$id}." . $pack(substr($raw, 0, -1));

      yield assert(
         assertion: $Encrypter->decrypt($truncated) === null,
         description: 'truncated blob fails authentication'
      );

      yield assert(
         assertion: $Encrypter->decrypt("{$version}.unknown.{$blob}") === null,
         description: 'altered key id segment yields null'
      );

      yield assert(
         assertion: $Encrypter->decrypt("v2.{$id}.{$blob}") === null,
         description: 'unsupported envelope version yields null'
      );

      $Stranger = new Encrypter(new Key(random_bytes(32), 'k1'));

      yield assert(
         assertion: $Stranger->decrypt($envelope) === null,
         description: 'wrong key material fails authentication'
      );

      // ! Malformed envelopes: parse failures never throw
      yield assert(
         assertion: $Encrypter->decrypt('') === null
            && $Encrypter->decrypt('v1.k1') === null
            && $Encrypter->decrypt("v1.k1.{$blob}.extra") === null,
         description: 'empty, 2-segment and 4-segment envelopes yield null'
      );

      yield assert(
         assertion: $Encrypter->decrypt('v1.k1.!!!') === null,
         description: 'non-base64url blob yields null'
      );

      $short = "{$version}.{$id}." . $pack(random_bytes(27));

      yield assert(
         assertion: $Encrypter->decrypt($short) === null,
         description: 'blob shorter than IV + tag yields null'
      );

      yield assert(
         assertion: $Encrypter->decrypt($envelope) === $plaintext,
         description: 'untampered envelope still decrypts after every rejection'
      );
   }
);
