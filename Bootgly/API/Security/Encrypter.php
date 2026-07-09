<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security;


use const OPENSSL_RAW_DATA;
use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function function_exists;
use function is_string;
use function openssl_decrypt;
use function openssl_encrypt;
use function random_bytes;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;
use function substr;
use RuntimeException;

use Bootgly\API\Security\Encrypter\Key;
use Bootgly\API\Security\Encrypter\Keyring;


/**
 * Authenticated symmetric encryption (AES-256-GCM) with key rotation.
 *
 * Payloads are sealed into portable `v1.<kid>.<blob>` envelopes whose
 * version and key id segments are authenticated together with the
 * caller-provided Additional Authenticated Data.
 */
class Encrypter
{
   // * Config
   /**
    * Encryption keys: the primary encrypts, every registered key decrypts.
    */
   public private(set) Keyring $Keyring;

   // * Data
   // ...

   // * Metadata
   /**
    * Envelope format version.
    */
   private const string VERSION = 'v1';
   /**
    * Symmetric cipher used by this envelope version.
    */
   private const string CIPHER = 'aes-256-gcm';
   /**
    * GCM initialization vector length in bytes.
    */
   private const int IV_LENGTH = 12;
   /**
    * GCM authentication tag length in bytes.
    */
   private const int TAG_LENGTH = 16;


   /**
    * Create an encrypter from raw 32-byte key material, a Key or a Keyring.
    *
    * @throws RuntimeException When OpenSSL symmetric encryption is unavailable.
    */
   public function __construct (#[\SensitiveParameter] string|Key|Keyring $key)
   {
      // ? OpenSSL symmetric functions availability
      if (
         function_exists('openssl_encrypt') === false
         || function_exists('openssl_decrypt') === false
      ) {
         throw new RuntimeException('Encrypter requires the OpenSSL extension.');
      }

      // * Config
      $this->Keyring = match (true) {
         is_string($key) => new Keyring(new Key($key)),
         $key instanceof Key => new Keyring($key),
         default => $key
      };
   }

   /**
    * Encrypt a payload into a portable envelope.
    *
    * @throws RuntimeException When the OpenSSL encryption fails.
    */
   public function encrypt (#[\SensitiveParameter] string $plaintext, string $AAD = ''): string
   {
      // ! Primary key, fresh IV per call and authenticated envelope prefix.
      $Key = $this->Keyring->Primary;
      $id = $Key->id ?? '';
      $IV = random_bytes(self::IV_LENGTH);
      $prefix = self::VERSION . ".{$id}.";

      // @ Seal — the envelope prefix is authenticated alongside the caller AAD.
      $tag = '';
      $sealed = openssl_encrypt(
         $plaintext,
         self::CIPHER,
         $Key->material,
         OPENSSL_RAW_DATA,
         $IV,
         $tag,
         "{$prefix}{$AAD}",
         self::TAG_LENGTH
      );

      // ? Environmental failure only — never caused by user input
      if ($sealed === false) {
         throw new RuntimeException('Encrypter failed to seal the payload.');
      }

      // : Versioned envelope: v1.<kid>.<base64url(IV ∥ ciphertext ∥ tag)>.
      return $prefix . $this->pack("{$IV}{$sealed}{$tag}");
   }

   /**
    * Decrypt an envelope. Returns null on any failure — no reason is disclosed.
    */
   public function decrypt (string $ciphertext, string $AAD = ''): null|string
   {
      // ? Envelope must have exactly 3 segments and a supported version
      $segments = explode('.', $ciphertext);
      if (count($segments) !== 3 || $segments[0] !== self::VERSION) {
         return null;
      }

      // ? Key id must resolve to a registered key
      $id = $segments[1] === '' ? null : $segments[1];
      $Key = $this->Keyring->resolve($id);
      if ($Key === null) {
         return null;
      }

      // ? Blob must decode and carry at least an IV and a tag
      $raw = $this->unpack($segments[2]);
      if ($raw === null || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH) {
         return null;
      }

      // ! Split the raw blob into IV, ciphertext and authentication tag.
      $IV = substr($raw, 0, self::IV_LENGTH);
      $tag = substr($raw, -self::TAG_LENGTH);
      $sealed = substr($raw, self::IV_LENGTH, -self::TAG_LENGTH);

      // @ Open — the envelope prefix is authenticated alongside the caller AAD.
      $prefix = self::VERSION . ".{$segments[1]}.";
      $plaintext = openssl_decrypt(
         $sealed,
         self::CIPHER,
         $Key->material,
         OPENSSL_RAW_DATA,
         $IV,
         $tag,
         "{$prefix}{$AAD}"
      );

      // ?: Authentication failures yield null
      if ($plaintext === false) {
         return null;
      }

      return $plaintext;
   }

   /**
    * Encode binary data with base64url without padding.
    */
   private function pack (string $value): string
   {
      return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
   }

   /**
    * Decode base64url data with restored padding.
    */
   private function unpack (string $value): null|string
   {
      $base64 = strtr($value, '-_', '+/');
      $remainder = strlen($base64) % 4;
      if ($remainder !== 0) {
         $padding = str_repeat('=', 4 - $remainder);
         $base64 = "{$base64}{$padding}";
      }

      $decoded = base64_decode($base64, true);
      if (is_string($decoded) === false) {
         return null;
      }

      return $decoded;
   }
}
