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


use function array_key_exists;
use function base64_decode;
use function base64_encode;
use function chr;
use function chunk_split;
use function implode;
use function is_string;
use function ltrim;
use function ord;
use function pack;
use function str_repeat;
use function strlen;
use function strtr;
use InvalidArgumentException;


/**
 * Minimal JSON Web Key parser for RSA public keys.
 */
class JWK
{
   private const string RSA_ALGORITHM = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

   /**
    * Parse a single RSA JWK into a JWT key.
    *
    * @param array<string,mixed> $jwk
    */
   public static function parse (array $jwk, null|string $algorithm = null): Key
   {
      $type = $jwk['kty'] ?? null;
      if ($type !== 'RSA') {
         throw new InvalidArgumentException('Only RSA JWK keys are supported.');
      }

      if (array_key_exists('d', $jwk)) {
         throw new InvalidArgumentException('RSA private JWK keys are not supported.');
      }

      $alg = $jwk['alg'] ?? $algorithm;
      if (is_string($alg) === false || $alg === '') {
         throw new InvalidArgumentException('JWK algorithm is required.');
      }
      if ($alg !== 'RS256') {
         throw new InvalidArgumentException('Only RS256 JWK keys are supported.');
      }

      $modulus = $jwk['n'] ?? null;
      $exponent = $jwk['e'] ?? null;
      if (is_string($modulus) === false || $modulus === '' || is_string($exponent) === false || $exponent === '') {
         throw new InvalidArgumentException('RSA JWK keys require modulus and exponent.');
      }

      $id = $jwk['kid'] ?? null;
      if ($id !== null && is_string($id) === false) {
         throw new InvalidArgumentException('JWK key id must be a string.');
      }

      $pem = self::format(self::decode($modulus), self::decode($exponent));

      return new Key($pem, $alg, $id);
   }

   /**
    * Decode base64url JWK values.
    */
   private static function decode (string $value): string
   {
      $base64 = strtr($value, '-_', '+/');
      $remainder = strlen($base64) % 4;
      if ($remainder !== 0) {
         $base64 .= str_repeat('=', 4 - $remainder);
      }

      $decoded = base64_decode($base64, true);
      if (is_string($decoded) === false || $decoded === '') {
         throw new InvalidArgumentException('Invalid base64url JWK value.');
      }

      return $decoded;
   }

   /**
    * Build a PEM SubjectPublicKeyInfo from RSA modulus and exponent.
    */
   private static function format (string $modulus, string $exponent): string
   {
      $rsa = self::wrap("\x30", implode('', [
         self::cast($modulus),
         self::cast($exponent),
      ]));
      $public = self::wrap("\x30", implode('', [
         self::RSA_ALGORITHM,
         self::wrap("\x03", "\x00{$rsa}"),
      ]));
      $encoded = chunk_split(base64_encode($public), 64, "\n");

      return "-----BEGIN PUBLIC KEY-----\n{$encoded}-----END PUBLIC KEY-----\n";
   }

   /**
    * Wrap an ASN.1 DER value with a tag.
    */
   private static function wrap (string $tag, string $value): string
   {
      return implode('', [$tag, self::measure(strlen($value)), $value]);
   }

   /**
    * Encode an ASN.1 DER integer.
    */
   private static function cast (string $value): string
   {
      $value = ltrim($value, "\x00");
      if ($value === '') {
         $value = "\x00";
      }
      if ((ord($value[0]) & 0x80) !== 0) {
         $value = "\x00{$value}";
      }

      return self::wrap("\x02", $value);
   }

   /**
    * Encode an ASN.1 DER length.
    */
   private static function measure (int $length): string
   {
      if ($length < 128) {
         return chr($length);
      }

      $bytes = ltrim(pack('N', $length), "\x00");
      return implode('', [chr(0x80 | strlen($bytes)), $bytes]);
   }
}
