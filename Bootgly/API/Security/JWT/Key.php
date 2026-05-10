<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT;


use function function_exists;
use function is_array;
use function is_string;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function strlen;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;


/**
 * JWT cryptographic key material with an expected algorithm.
 */
class Key
{
   // * Config
   /**
    * Algorithm this key is allowed to verify/sign.
    */
   public private(set) string $algorithm;
   /**
    * Optional key identifier used by JWT `kid` headers.
    */
   public private(set) null|string $id;
   /**
    * Sensitive key material.
    */
   public private(set) string|OpenSSLAsymmetricKey|OpenSSLCertificate $Material;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Create a JWT key descriptor.
    *
    * @throws InvalidArgumentException When the key material or algorithm is unsafe.
    */
   public function __construct (
      #[\SensitiveParameter] mixed $material,
      string $algorithm = 'HS256',
      null|string $id = null
   )
   {
      if (
         is_string($material) === false
         && $material instanceof OpenSSLAsymmetricKey === false
         && $material instanceof OpenSSLCertificate === false
      ) {
         throw new InvalidArgumentException('JWT key material must be a string or OpenSSL key.');
      }

      if ($id === '') {
         throw new InvalidArgumentException('JWT key id must not be empty.');
      }

      // * Config
      $this->algorithm = $algorithm;
      $this->id = $id;
      $this->Material = $material;

      // @ Validate.
      $this->guard();
   }

   /**
    * Resolve private key material for signing.
    */
   public function open (): null|OpenSSLAsymmetricKey
   {
      if ($this->algorithm !== 'RS256') {
         return null;
      }

      if ($this->Material instanceof OpenSSLAsymmetricKey) {
         return $this->Material;
      }

      if (is_string($this->Material) === false) {
         return null;
      }

      $Private = openssl_pkey_get_private($this->Material);
      return $Private instanceof OpenSSLAsymmetricKey ? $Private : null;
   }

   /**
    * Derive public verification material.
    */
   public function derive (): null|string|OpenSSLAsymmetricKey|OpenSSLCertificate
   {
      if ($this->algorithm !== 'RS256') {
         return null;
      }

      if ($this->Material instanceof OpenSSLCertificate) {
         return $this->Material;
      }

      if ($this->Material instanceof OpenSSLAsymmetricKey) {
         $details = openssl_pkey_get_details($this->Material);
         if (is_array($details) && is_string($details['key'] ?? null)) {
            return $details['key'];
         }

         return $this->Material;
      }

      $Public = openssl_pkey_get_public($this->Material);
      if ($Public instanceof OpenSSLAsymmetricKey) {
         return $Public;
      }

      $Private = openssl_pkey_get_private($this->Material);
      if ($Private instanceof OpenSSLAsymmetricKey === false) {
         return null;
      }

      $details = openssl_pkey_get_details($Private);
      if (is_array($details) && is_string($details['key'] ?? null)) {
         return $details['key'];
      }

      return null;
   }

   /**
    * Validate key material for the selected algorithm.
    */
   private function guard (): void
   {
      if ($this->algorithm === 'HS256') {
         if (is_string($this->Material) === false) {
            throw new InvalidArgumentException('HS256 JWT keys must be strings.');
         }

         if (strlen($this->Material) < 32) {
            throw new InvalidArgumentException('HS256 secrets must be at least 32 bytes.');
         }

         return;
      }

      if ($this->algorithm !== 'RS256') {
         throw new InvalidArgumentException('Unsupported JWT algorithm.');
      }

      if (
         function_exists('openssl_pkey_get_details') === false
         || function_exists('openssl_pkey_get_private') === false
         || function_exists('openssl_pkey_get_public') === false
      ) {
         throw new InvalidArgumentException('RS256 JWT keys require the OpenSSL extension.');
      }

      $material = $this->derive();
      $Key = null;
      if ($material instanceof OpenSSLAsymmetricKey) {
         $Key = $material;
      }
      elseif ($material instanceof OpenSSLCertificate || is_string($material)) {
         $Key = openssl_pkey_get_public($material) ?: null;
      }

      if ($Key instanceof OpenSSLAsymmetricKey === false) {
         throw new InvalidArgumentException('Invalid RS256 JWT key material.');
      }

      $details = openssl_pkey_get_details($Key);
      if (is_array($details) === false || ($details['bits'] ?? 0) < 2048) {
         throw new InvalidArgumentException('RS256 keys must be at least 2048 bits.');
      }
   }
}
