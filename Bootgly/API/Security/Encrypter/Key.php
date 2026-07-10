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
use function substr;
use InvalidArgumentException;
use LogicException;
use RuntimeException;


/**
 * Symmetric AES-256-GCM key with an optional identifier.
 *
 * The key owns the GCM security invariants: it generates a fresh 12-byte
 * IV on every seal and always authenticates a full 16-byte tag on open —
 * callers cannot supply either. The class is final so accepted keys can
 * never weaken those invariants through overrides.
 *
 * The raw key material is private. It is redacted from `var_dump`, absent
 * from JSON and both serialization directions are refused. Same-process
 * reflection (e.g. `var_export`, `get_mangled_object_vars`) cannot be
 * prevented in PHP and is outside this boundary.
 */
final class Key
{
   // * Config
   /**
    * Optional key identifier carried by ciphertext envelopes.
    * Contract: `[A-Za-z0-9_-]`, at most 64 characters.
    */
   public private(set) null|string $id;

   // * Data
   /**
    * Sensitive raw key material (exactly 32 bytes). Not exposed by any
    * supported API.
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
   private const int IV_LENGTH = 12;
   /**
    * GCM authentication tag length in bytes.
    */
   private const int TAG_LENGTH = 16;


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
    * The material is **ephemeral by design**: there is no supported
    * export API for it, so data encrypted with a generated key is
    * undecryptable after the process ends. For persisted data, provision
    * the material first (`base64_encode(random_bytes(32))`) and use
    * `import()`.
    *
    * @throws \Random\RandomException When the randomness source fails.
    * @throws InvalidArgumentException When the id is unsafe.
    * @throws RuntimeException When OpenSSL symmetric encryption is unavailable.
    */
   public static function generate (null|string $id = null): self
   {
      // : 32 bytes of CSPRNG material.
      return new self(random_bytes(32), $id);
   }

   /**
    * Import base64-encoded key material.
    *
    * @throws InvalidArgumentException When the encoding, the decoded material or the id is invalid.
    * @throws RuntimeException When OpenSSL symmetric encryption is unavailable.
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
    * A fresh random 12-byte IV is generated internally per call — callers
    * cannot choose or intentionally reuse one. Random IVs follow the NIST
    * SP 800-38D budget: at most 2^32 seals per raw key, aggregated across
    * every process, host and key id sharing the same 32-byte material —
    * rotate the key well before that bound.
    *
    * @throws \Random\RandomException When the randomness source fails.
    * @throws RuntimeException When the OpenSSL encryption fails.
    *
    * @return string The raw `IV ∥ ciphertext ∥ tag` bytes.
    */
   public function seal (#[\SensitiveParameter] string $plaintext, string $AAD): string
   {
      // ! Fresh nonce per seal.
      $IV = random_bytes(self::IV_LENGTH);

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

      // : IV ∥ ciphertext ∥ tag.
      return "{$IV}{$sealed}{$tag}";
   }

   /**
    * Open a sealed payload with this key (AES-256-GCM).
    *
    * The full 16-byte tag is always authenticated — truncated tags are
    * structurally impossible, not merely rejected.
    *
    * Returns null on any failure.
    */
   public function open (string $sealed, string $AAD): null|string
   {
      // ? Sealed payload must carry at least an IV and a full tag
      if (strlen($sealed) < self::IV_LENGTH + self::TAG_LENGTH) {
         return null;
      }

      // ! Split the raw bytes into IV, ciphertext and authentication tag.
      $IV = substr($sealed, 0, self::IV_LENGTH);
      $tag = substr($sealed, -self::TAG_LENGTH);
      $ciphertext = substr($sealed, self::IV_LENGTH, -self::TAG_LENGTH);

      // @ Decrypt and verify the authentication tag.
      $plaintext = openssl_decrypt(
         $ciphertext,
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
    * Keys must never be unserialized — hydration would bypass the
    * constructor guards.
    *
    * @param array<string,mixed> $data
    */
   public function __unserialize (array $data): void
   {
      throw new LogicException('Encrypter keys must not be unserialized.');
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
