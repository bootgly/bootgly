<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Drivers\MySQL;


use const OPENSSL_PKCS1_OAEP_PADDING;
use function chr;
use function hash;
use function openssl_pkey_get_public;
use function openssl_public_encrypt;
use function ord;
use function sha1;
use function str_starts_with;
use function strlen;
use InvalidArgumentException;

use Bootgly\ADI\Database\Config;


/**
 * MySQL authentication helper.
 *
 * Implements the challenge-response scrambles for `mysql_native_password`
 * and `caching_sha2_password`, plus the RSA password exchange used by the
 * caching_sha2 full authentication path over plaintext connections.
 */
class Authentication
{
   public const string NATIVE = 'mysql_native_password';
   public const string SHA2 = 'caching_sha2_password';

   // * Config
   public Config $Config;

   // * Data
   public string $plugin = '';
   public bool $authenticated = false;

   // * Metadata
   // ...


   public function __construct (Config $Config)
   {
      // * Config
      $this->Config = $Config;
   }

   /**
    * Create a challenge-response scramble for one authentication plugin.
    */
   public function scramble (string $plugin, string $nonce): string
   {
      $password = $this->Config->password;

      // ? Empty passwords answer with an empty scramble on both plugins.
      if ($password === '') {
         return '';
      }

      if ($plugin === self::NATIVE) {
         // : SHA1(password) XOR SHA1(nonce . SHA1(SHA1(password)))
         $hashed = sha1($password, true);
         $mask = sha1($nonce . sha1($hashed, true), true);

         return $this->mix($hashed, $mask);
      }

      if ($plugin === self::SHA2) {
         // : SHA256(password) XOR SHA256(SHA256(SHA256(password)) . nonce)
         $hashed = hash('sha256', $password, true);
         $mask = hash('sha256', hash('sha256', $hashed, true) . $nonce, true);

         return $this->mix($hashed, $mask);
      }

      throw new InvalidArgumentException("MySQL authentication plugin is not supported: {$plugin}.");
   }

   /**
    * Encrypt the password with the pinned server RSA public key (full authentication).
    */
   public function encrypt (string $key, string $nonce): string
   {
      // ? Pinned keys may reference a PEM file path instead of inline PEM content
      if (str_starts_with($key, '-----') === false && str_starts_with($key, 'file://') === false) {
         $key = "file://{$key}";
      }

      $public = openssl_pkey_get_public($key);

      if ($public === false) {
         throw new InvalidArgumentException('MySQL server RSA public key is invalid.');
      }

      // ! Null-terminated password XOR-rotated with the handshake nonce
      $password = "{$this->Config->password}\0";
      $length = strlen($password);
      $window = strlen($nonce);
      $masked = '';

      // @@
      for ($index = 0; $index < $length; $index++) {
         $masked .= chr(ord($password[$index]) ^ ord($nonce[$index % $window]));
      }

      $encrypted = '';

      // @
      if (openssl_public_encrypt($masked, $encrypted, $public, OPENSSL_PKCS1_OAEP_PADDING) === false) {
         throw new InvalidArgumentException('MySQL RSA password encryption failed.');
      }

      // :
      return $encrypted;
   }

   /**
    * Mix a hashed password with its plugin mask (byte-wise XOR).
    */
   private function mix (string $hashed, string $mask): string
   {
      $length = strlen($hashed);
      $mixed = '';

      // @@
      for ($index = 0; $index < $length; $index++) {
         $mixed .= chr(ord($hashed[$index]) ^ ord($mask[$index]));
      }

      // :
      return $mixed;
   }
}
