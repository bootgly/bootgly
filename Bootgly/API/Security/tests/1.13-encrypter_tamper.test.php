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

      // ! Canonical encoding only: alternate textual forms of the same bytes
      yield assert(
         assertion: $Encrypter->decrypt("{$envelope}=") === null,
         description: 'padded variant of a valid envelope yields null'
      );

      // ! Raw length 44 (¬≡ 0 mod 3) forces '=' padding in the standard form,
      // ! so the non-canonical variant always differs from the envelope blob
      $padded = $Encrypter->encrypt(str_repeat('b', 16)); // raw = 12 + 16 + 16 = 44 bytes
      [, , $pblob] = explode('.', $padded);
      $standard = "{$version}.{$id}." . base64_encode($unpack($pblob));

      yield assert(
         assertion: str_ends_with($standard, '=')
            && $Encrypter->decrypt($standard) === null
            && $Encrypter->decrypt($padded) === str_repeat('b', 16),
         description: 'standard padded base64 of the same bytes yields null'
      );

      $mod = "{$version}.{$id}." . substr($blob, 0, strlen($blob) - (strlen($blob) % 4 + 3));

      yield assert(
         assertion: strlen(explode('.', $mod)[2]) % 4 === 1
            && $Encrypter->decrypt($mod) === null,
         description: 'blob with length % 4 === 1 yields null'
      );

      // ! Non-zero trailing bits in the last character decode to the same
      // ! bytes but fail the canonical re-encode check
      $bitty = $Encrypter->encrypt(str_repeat('a', 16)); // raw = 44 bytes → 2 trailing bits
      [, , $tail] = explode('.', $bitty);
      $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
      $variant = substr($tail, 0, -1) . $alphabet[strpos($alphabet, substr($tail, -1)) ^ 1];

      yield assert(
         assertion: $Encrypter->decrypt($bitty) === str_repeat('a', 16)
            && $variant !== $tail
            && $Encrypter->decrypt("{$version}.{$id}.{$variant}") === null,
         description: 'non-zero trailing bits in the last blob character yield null'
      );

      yield assert(
         assertion: $Encrypter->decrypt($envelope) === $plaintext,
         description: 'untampered envelope still decrypts after every rejection'
      );
   }
);
