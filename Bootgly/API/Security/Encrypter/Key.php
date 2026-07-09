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


use function base64_decode;
use function is_string;
use function random_bytes;
use function str_contains;
use function strlen;
use InvalidArgumentException;


/**
 * Symmetric AES-256 key material with an optional identifier.
 */
class Key
{
   // * Config
   /**
    * Optional key identifier carried by ciphertext envelopes.
    */
   public private(set) null|string $id;
   /**
    * Sensitive raw key material (exactly 32 bytes).
    */
   public private(set) string $material;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Create an encryption key descriptor.
    *
    * @throws InvalidArgumentException When the key material or id is unsafe.
    */
   public function __construct (
      #[\SensitiveParameter] string $material,
      null|string $id = null
   )
   {
      // * Config
      $this->id = $id;
      $this->material = $material;

      // @ Validate.
      $this->guard();
   }

   /**
    * Generate a key with fresh random material.
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
    * Validate key material and identifier.
    */
   private function guard (): void
   {
      if (strlen($this->material) !== 32) {
         throw new InvalidArgumentException('Encrypter keys must be exactly 32 bytes.');
      }

      if ($this->id === '') {
         throw new InvalidArgumentException('Encrypter key id must not be empty.');
      }

      if ($this->id !== null && str_contains($this->id, '.')) {
         throw new InvalidArgumentException('Encrypter key id must not contain dots.');
      }
   }
}
