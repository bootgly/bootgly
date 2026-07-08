<?php

use function chr;
use function hash;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_private_decrypt;
use function ord;
use function sha1;
use function str_repeat;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Authentication;


return new Specification(
   description: 'MySQL: authentication scrambles and RSA password exchange',
   test: function () {
      $nonce = 'abcdefghijklmnopqrst';
      $Authentication = new Authentication(new Config([
         'driver' => 'mysql',
         'username' => 'root',
         'password' => 'secret',
      ]));

      // # mysql_native_password — SHA1(pass) XOR SHA1(nonce . SHA1(SHA1(pass)))
      $hashed = sha1('secret', true);
      $mask = sha1($nonce . sha1($hashed, true), true);
      $expected = '';

      for ($index = 0; $index < 20; $index++) {
         $expected .= chr(ord($hashed[$index]) ^ ord($mask[$index]));
      }

      $native = $Authentication->scramble(Authentication::NATIVE, $nonce);

      yield assert(
         assertion: $native === $expected && strlen($native) === 20,
         description: 'mysql_native_password produces the 20-byte SHA1 scramble'
      );

      // # caching_sha2_password — SHA256(pass) XOR SHA256(SHA256(SHA256(pass)) . nonce)
      $hashed = hash('sha256', 'secret', true);
      $mask = hash('sha256', hash('sha256', $hashed, true) . $nonce, true);
      $expected = '';

      for ($index = 0; $index < 32; $index++) {
         $expected .= chr(ord($hashed[$index]) ^ ord($mask[$index]));
      }

      $sha2 = $Authentication->scramble(Authentication::SHA2, $nonce);

      yield assert(
         assertion: $sha2 === $expected && strlen($sha2) === 32,
         description: 'caching_sha2_password produces the 32-byte SHA256 scramble'
      );

      // # Empty password
      $Anonymous = new Authentication(new Config(['driver' => 'mysql', 'password' => '']));

      yield assert(
         assertion: $Anonymous->scramble(Authentication::NATIVE, $nonce) === ''
            && $Anonymous->scramble(Authentication::SHA2, $nonce) === '',
         description: 'Empty passwords answer with empty scrambles on both plugins'
      );

      // # Unsupported plugin
      $rejected = false;

      try {
         $Authentication->scramble('ed25519', $nonce);
      }
      catch (InvalidArgumentException) {
         $rejected = true;
      }

      yield assert(
         assertion: $rejected,
         description: 'Unsupported plugins are rejected with a clear error'
      );

      // # RSA full authentication — decrypt with the private key and unmask
      $Key = openssl_pkey_new([
         'private_key_bits' => 2048,
         'private_key_type' => OPENSSL_KEYTYPE_RSA,
      ]);
      $details = $Key === false ? false : openssl_pkey_get_details($Key);

      if ($Key !== false && $details !== false) {
         $encrypted = $Authentication->encrypt((string) $details['key'], $nonce);
         $decrypted = '';
         openssl_private_decrypt($encrypted, $decrypted, $Key, OPENSSL_PKCS1_OAEP_PADDING);
         $password = '';
         $length = strlen($decrypted);

         for ($index = 0; $index < $length; $index++) {
            $password .= chr(ord($decrypted[$index]) ^ ord($nonce[$index % strlen($nonce)]));
         }

         yield assert(
            assertion: $password === "secret\0",
            description: 'RSA exchange encrypts the null-terminated nonce-masked password'
         );
      }

      // # Invalid public key
      $failed = false;

      try {
         $Authentication->encrypt('not-a-pem', $nonce);
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed,
         description: 'Invalid RSA public keys are rejected'
      );
   }
);
