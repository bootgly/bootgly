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


use const OPENSSL_ALGO_SHA256;
use function function_exists;
use function hash_equals;
use function hash_hmac;
use function is_string;
use function openssl_error_string;
use function openssl_sign;
use function openssl_verify;
use OpenSSLAsymmetricKey;
use RuntimeException;


/**
 * Compact JWT signature engine.
 */
class Signer
{
   /**
    * Sign compact JWT data with a key.
    *
    * @throws RuntimeException When signing cannot be completed.
    */
   public function seal (string $data, Key $Key): string
   {
      $material = $Key->Material;
      if ($Key->algorithm === 'HS256') {
         if (is_string($material) === false) {
            throw new RuntimeException('HS256 signing requires a string key.');
         }

         return hash_hmac('sha256', $data, $material, true);
      }

      if ($Key->algorithm !== 'RS256') {
         throw new RuntimeException('Unsupported JWT signing algorithm.');
      }

      if (function_exists('openssl_sign') === false) {
         throw new RuntimeException('RS256 signing requires the OpenSSL extension.');
      }

      $Private = $Key->open();
      if ($Private instanceof OpenSSLAsymmetricKey === false) {
         throw new RuntimeException('RS256 signing requires a private key.');
      }

      $signature = '';
      if (openssl_sign($data, $signature, $Private, OPENSSL_ALGO_SHA256) === false) {
         throw new RuntimeException('RS256 signing failed.');
      }

      return $signature;
   }

   /**
    * Verify compact JWT data with a key.
    */
   public function check (string $data, string $signature, Key $Key): null|Failures
   {
      $material = $Key->Material;
      if ($Key->algorithm === 'HS256') {
         return is_string($material) && hash_equals(hash_hmac('sha256', $data, $material, true), $signature)
            ? null
            : Failures::Signature;
      }

      if ($Key->algorithm !== 'RS256') {
         return Failures::Algorithm;
      }

      if (function_exists('openssl_verify') === false) {
         return Failures::OpenSSL;
      }

      $Public = $Key->derive();
      if ($Public === null) {
         return Failures::Key;
      }

      $verified = openssl_verify($data, $signature, $Public, OPENSSL_ALGO_SHA256);
      if ($verified === 1) {
         return null;
      }

      if ($verified === -1) {
         openssl_error_string();
         return Failures::OpenSSL;
      }

      return Failures::Signature;
   }

   /**
    * Check algorithm support.
    */
   public function support (string $algorithm): bool
   {
      return $algorithm === 'HS256' || $algorithm === 'RS256';
   }
}
