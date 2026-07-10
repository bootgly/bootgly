<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Encrypter;


use const OPENSSL_RAW_DATA;
use function base64_decode;
use function function_exists;
use function is_string;
use function openssl_decrypt;
use function openssl_encrypt;
use function preg_match;
use function random_bytes;
use function strlen;
use InvalidArgumentException;
use LogicException;
use RuntimeException;


/**
 * Symmetric AES-256-GCM key with an optional identifier.
 *
 * The raw key material is private: the key performs the cryptographic
 * operations itself and never exposes its secret through public state,
 * debug dumps or serialization.
 */
class Key
{
   // * Config
   /**
    * Optional key identifier carried by ciphertext envelopes.
    * Contract: `[A-Za-z0-9_-]`, at most 64 characters.
    */
   public private(set) null|string $id;

   // * Data
   /**
    * Sensitive raw key material (exactly 32 bytes). Never exposed.
    */
   private string $material;

   // * Metadata
   /**
    * Symmetric cipher used by every key.
    */
   private const string CIPHER = 'aes-256-gcm';
   /**
    * GCM initialization vector length in bytes.
    */
   public const int IV_LENGTH = 12;
   /**
    * GCM authentication tag length in bytes.
    */
   public const int TAG_LENGTH = 16;


   /**
    * Create an encryption key descriptor.
    *
    * @throws InvalidArgumentException When the key material or id is unsafe.
    * @throws RuntimeException When OpenSSL symmetric encryption is unavailable.
    */
   public function __construct (
      #[\SensitiveParameter] string $material,
      null|string $id = null
   )
   {
      // * Config
      $this->id = $id;

      // * Data
      $this->material = $material;

      // @ Validate.
      $this->guard();
   }

   /**
    * Generate a key with fresh random material.
    *
    * @throws \Random\RandomException When the randomness source fails.
    */
   public static function generate (null|string $id = null): self
   {
      // : 32 bytes of CSPRNG material.
      return new self(random_bytes(32), $id);
   }

   /**
    * Import base64-encoded key material.
    *
    * @throws InvalidArgumentException When the encoding or the decoded material is invalid.
    */
   public static function import (
      #[\SensitiveParameter] string $encoded,
      null|string $id = null
   ): self
   {
      // ? Strict base64 decoding only
      $material = base64_decode($encoded, true);
      if (is_string($material) === false) {
         throw new InvalidArgumentException('Encrypter key material must be valid base64.');
      }

      // : Constructor guard enforces the 32-byte length.
      return new self($material, $id);
   }

   /**
    * Seal a payload with this key (AES-256-GCM).
    *
    * @throws RuntimeException When the OpenSSL encryption fails.
    *
    * @return string The raw ciphertext with the authentication tag appended.
    */
   public function seal (#[\SensitiveParameter] string $plaintext, string $IV, string $AAD): string
   {
      // @ Encrypt and authenticate.
      $tag = '';
      $sealed = openssl_encrypt(
         $plaintext,
         self::CIPHER,
         $this->material,
         OPENSSL_RAW_DATA,
         $IV,
         $tag,
         $AAD,
         self::TAG_LENGTH
      );

      // ? Environmental failure only — never caused by user input
      if ($sealed === false) {
         throw new RuntimeException('Encrypter key failed to seal the payload.');
      }

      // : Ciphertext ∥ tag.
      return "{$sealed}{$tag}";
   }

   /**
    * Open a sealed payload with this key (AES-256-GCM).
    * Returns null on any authentication failure.
    */
   public function open (string $sealed, string $IV, string $tag, string $AAD): null|string
   {
      // @ Decrypt and verify the authentication tag.
      $plaintext = openssl_decrypt(
         $sealed,
         self::CIPHER,
         $this->material,
         OPENSSL_RAW_DATA,
         $IV,
         $tag,
         $AAD
      );

      // ?: Authentication failures yield null
      if ($plaintext === false) {
         return null;
      }

      return $plaintext;
   }

   /**
    * Redact the key material from debug dumps.
    *
    * @return array<string,null|string>
    */
   public function __debugInfo (): array
   {
      return [
         'id' => $this->id,
         'material' => '[redacted]'
      ];
   }

   /**
    * Keys must never be serialized — that would persist the raw material.
    *
    * @return array<string,mixed>
    */
   public function __serialize (): array
   {
      throw new LogicException('Encrypter keys must not be serialized.');
   }

   /**
    * Validate key material, identifier and runtime support.
    */
   private function guard (): void
   {
      if (strlen($this->material) !== 32) {
         throw new InvalidArgumentException('Encrypter keys must be exactly 32 bytes.');
      }

      if ($this->id !== null && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $this->id) !== 1) {
         throw new InvalidArgumentException(
            'Encrypter key ids must match [A-Za-z0-9_-] with at most 64 characters.'
         );
      }

      if (
         function_exists('openssl_encrypt') === false
         || function_exists('openssl_decrypt') === false
      ) {
         throw new RuntimeException('Encrypter keys require the OpenSSL extension.');
      }
   }
}
