<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security;


use const JSON_THROW_ON_ERROR;
use function base64_decode;
use function base64_encode;
use function count;
use function ctype_digit;
use function explode;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;
use function time;
use InvalidArgumentException;
use JsonException;
use RuntimeException;


/**
 * Native JSON Web Token signer and verifier.
 *
 * This class implements compact JWS tokens with the HS256 algorithm using
 * only PHP core functions. It is suitable for Bootgly's core authentication
 * guards and intentionally avoids third-party dependencies. Tokens are
 * expected to travel through HTTP as Bearer credentials, but this primitive
 * remains independent from HTTP request/response classes.
 */
class JWT
{
   // * Config
   /**
    * HMAC secret used to sign and verify HS256 tokens.
    *
    * The constructor enforces at least 32 bytes to avoid trivially weak demo
    * or production secrets.
    */
   public private(set) string $secret;

   // * Data
   /**
    * JWS algorithm identifier.
    *
    * Currently fixed to `HS256` so Bootgly can start with one explicit,
    * auditable implementation before adding asymmetric algorithms.
    */
   public private(set) string $algorithm;
   /**
    * Clock skew tolerance, in seconds, for `exp`, `nbf`, and `iat` claims.
      *
      * Defaults to `0` for strict verification. Applications running across
      * clocks with expected drift may set a small value such as `5`.
    */
   public int $leeway = 0;

   // * Metadata
   // ...


   /**
    * Configure a JWT signer/verifier.
    *
    * @param string $secret Shared HMAC secret with at least 32 bytes.
    * @param string $algorithm Supported algorithm. Only `HS256` is accepted.
    *
    * @throws InvalidArgumentException When the algorithm or secret is unsafe.
    */
   public function __construct (string $secret, string $algorithm = 'HS256')
   {
      // ? Bootgly core supports native JWS HS256 first.
      if ($algorithm !== 'HS256') {
         throw new InvalidArgumentException('Only HS256 JWT signing is supported.');
      }

      if (strlen($secret) < 32) {
         throw new InvalidArgumentException('HS256 secrets must be at least 32 bytes.');
      }

      // * Config
      $this->secret = $secret;

      // * Data
      $this->algorithm = $algorithm;
   }

   /**
    * Sign claims as a compact JWT.
    *
    * The protected header is always normalized to `typ=JWT` and the configured
    * algorithm. JSON encoding failures throw so callers cannot accidentally
    * issue an empty token.
    *
    * @param array<string,mixed> $claims
    * @param array<string,mixed> $headers
    *
    * @throws RuntimeException When claims or headers cannot be JSON encoded.
    */
   public function sign (array $claims, array $headers = []): string
   {
      // ! Header
      $Header = $headers;
      $Header['typ'] = 'JWT';
      $Header['alg'] = $this->algorithm;

      try {
         // @ Encode protected header and payload.
         $header = $this->pack(json_encode($Header, JSON_THROW_ON_ERROR));
         $payload = $this->pack(json_encode($claims, JSON_THROW_ON_ERROR));
      }
      catch (JsonException $Exception) {
         throw new RuntimeException('JWT JSON encoding failed.', previous: $Exception);
      }

      // @ Sign.
      $data = "{$header}.{$payload}";
      $signature = $this->pack(hash_hmac('sha256', $data, $this->secret, true));

      // :
      return "{$data}.{$signature}";
   }

   /**
    * Verify a compact JWT and return trusted claims.
    *
    * Verification is strict: three segments are required, the protected header
    * algorithm must match the configured algorithm, `typ` must be `JWT` or
    * `at+jwt`, the signature must match using `hash_equals`, and registered
    * time claims must be valid considering the configured leeway.
    *
    * @return array<string,mixed>|null
    */
   public function verify (string $token): null|array
   {
      // ! Segments
      $parts = explode('.', $token);
      if (count($parts) !== 3) {
         return null;
      }

      [$header, $payload, $signature] = $parts;

      // ? Missing segment.
      if ($header === '' || $payload === '' || $signature === '') {
         return null;
      }

      // ! Decode header.
      $headerJson = $this->unpack($header);
      if ($headerJson === null) {
         return null;
      }

      try {
         $Header = json_decode($headerJson, true, 512, JSON_THROW_ON_ERROR);
      }
      catch (JsonException) {
         return null;
      }

      if (is_array($Header) === false || ($Header['alg'] ?? '') !== $this->algorithm) {
         return null;
      }

      $type = $Header['typ'] ?? '';
      if (is_string($type) === false || ($type !== 'JWT' && $type !== 'at+jwt')) {
         return null;
      }

      // @ Verify signature before trusting payload.
      $data = "{$header}.{$payload}";
      $expected = $this->pack(hash_hmac('sha256', $data, $this->secret, true));
      if (hash_equals($expected, $signature) === false) {
         return null;
      }

      // ! Decode payload.
      $payloadJson = $this->unpack($payload);
      if ($payloadJson === null) {
         return null;
      }

      try {
         $decodedClaims = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
      }
      catch (JsonException) {
         return null;
      }

      if (is_array($decodedClaims) === false) {
         return null;
      }

      /** @var array<string,mixed> $claims */
      $claims = [];
      foreach ($decodedClaims as $name => $value) {
         if (is_string($name) === false) {
            return null;
         }

         $claims[$name] = $value;
      }

      if ($this->validate($claims) === false) {
         return null;
      }

      // :
      return $claims;
   }

   /**
    * Encode binary data with base64url without padding.
    */
   private function pack (string $value): string
   {
      // : Base64url without padding.
      return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
   }

   /**
    * Decode base64url data with restored padding.
    */
   private function unpack (string $value): null|string
   {
      // ! Restore padding for strict base64 decoding.
      $base64 = strtr($value, '-_', '+/');
      $remainder = strlen($base64) % 4;
      if ($remainder !== 0) {
         $padding = str_repeat('=', 4 - $remainder);
         $base64 = "{$base64}{$padding}";
      }

      // @ Decode.
      $decoded = base64_decode($base64, true);
      if (is_string($decoded) === false) {
         return null;
      }

      // :
      return $decoded;
   }

   /**
    * Validate JWT registered time claims.
    *
    * @param array<string,mixed> $claims
    */
   private function validate (array $claims): bool
   {
      // ! Registered time claims.
      $now = time();

      if (isset($claims['exp'])) {
         $expiration = $this->coerce($claims['exp']);
         if ($expiration === null || $expiration <= $now - $this->leeway) {
            return false;
         }
      }

      if (isset($claims['nbf'])) {
         $notBefore = $this->coerce($claims['nbf']);
         if ($notBefore === null || $notBefore > $now + $this->leeway) {
            return false;
         }
      }

      if (isset($claims['iat'])) {
         $issuedAt = $this->coerce($claims['iat']);
         if ($issuedAt === null || $issuedAt > $now + $this->leeway) {
            return false;
         }
      }

      // :
      return true;
   }

   /**
    * Convert a NumericDate-like value to an integer timestamp.
    */
   private function coerce (mixed $value): null|int
   {
      if (is_int($value)) {
         return $value;
      }

      if (is_float($value)) {
         return (int) $value;
      }

      if (is_string($value) && ctype_digit($value)) {
         return (int) $value;
      }

      return null;
   }
}
