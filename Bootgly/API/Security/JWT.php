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
use function count;
use function explode;
use function is_string;
use function json_encode;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

use Bootgly\API\Security\JWT\Encoder;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Header;
use Bootgly\API\Security\JWT\Key;
use Bootgly\API\Security\JWT\KeySet;
use Bootgly\API\Security\JWT\Policies;
use Bootgly\API\Security\JWT\Signer;
use Bootgly\API\Security\JWT\Validator;
use Bootgly\API\Security\JWT\Verification;


/**
 * Native JSON Web Token signer and verifier.
 *
 * This class implements compact JWS tokens using Bootgly core primitives. It
 * keeps the original HS256 `sign()`/`verify()` API intact while exposing the
 * richer `inspect()` path for key ids, verified headers, typed failures, and
 * asymmetric algorithms.
 */
class JWT
{
   // * Config
   /**
      * Backwards-compatible key material mirror for legacy HS256 consumers.
      *
      * Asymmetric JWT instances expose `null`; RS256 callers should use `Key`
      * objects instead of treating RSA material as a shared secret.
    */
   public private(set) null|string $secret;

   // * Data
   /**
    * Active signing algorithm.
    */
   public private(set) string $algorithm;
   /**
    * Clock skew tolerance, in seconds, for `exp`, `nbf`, and `iat` claims.
    */
   public int $leeway = 0;
   /**
      * Optional timestamp override for deterministic verification.
    */
   public private(set) null|int $timestamp = null;
   /**
    * Active signing key.
    */
   private Key $Key;
   /**
    * Verification key collection.
    */
   private KeySet $Keys;
   /**
    * Compact segment encoder.
    */
   private Encoder $Encoder;
   /**
    * Signature engine.
    */
   private Signer $Signer;
   /**
    * Registered-claim validator.
    */
   private Validator $Validator;

   // * Metadata
   // ...


   /**
    * Configure a JWT signer/verifier.
    *
    * @throws InvalidArgumentException When the algorithm or key is unsafe.
    */
   public function __construct (#[\SensitiveParameter] string $secret, string $algorithm = 'HS256')
   {
      $Key = new Key($secret, $algorithm);

      // * Config
      $this->secret = $algorithm === 'HS256' ? $secret : null;

      // * Data
      $this->algorithm = $algorithm;
      $this->Key = $Key;
      $this->Keys = new KeySet($Key);
      $this->Encoder = new Encoder;
      $this->Signer = new Signer;
      $this->Validator = new Validator;
   }

   /**
    * Set the active signing key and replace the verification set with it.
    */
   public function select (Key $Key): self
   {
      // * Data
      $this->Key = $Key;
      $this->algorithm = $Key->algorithm;

      $material = $Key->Material;
      $this->secret = $Key->algorithm === 'HS256' && is_string($material) ? $material : null;

      $this->Keys = new KeySet($Key);

      return $this;
   }

   /**
    * Add a verification key without changing the active signing key.
    */
   public function add (Key $Key): self
   {
      $this->Keys->add($Key);

      return $this;
   }

   /**
    * Replace the verification key set.
    */
   public function trust (KeySet $Keys): self
   {
      $this->Keys = $Keys;

      return $this;
   }

   /**
    * Freeze verification time for deterministic checks.
    */
   public function freeze (int $timestamp): self
   {
      if ($timestamp < 0) {
         throw new InvalidArgumentException('JWT timestamp must not be negative.');
      }

      $this->timestamp = $timestamp;

      return $this;
   }

   /**
    * Resume wall-clock verification time.
    */
   public function resume (): self
   {
      $this->timestamp = null;

      return $this;
   }

   /**
    * Sign claims as a compact JWT.
    *
    * The protected header always reserves `alg` and `kid` for the active key.
    * `typ` remains normalized to `JWT` for compatibility with the previous
    * Bootgly contract.
    *
    * @param array<string,mixed> $claims
    * @param array<string,mixed> $headers
    *
    * @throws RuntimeException When claims/headers cannot be encoded or signed.
    */
   public function sign (array $claims, array $headers = []): string
   {
      // ! Header
      $Header = $headers;
      unset($Header['alg'], $Header['kid']);
      $Header['typ'] = 'JWT';
      $Header['alg'] = $this->Key->algorithm;
      if ($this->Key->id !== null) {
         $Header['kid'] = $this->Key->id;
      }

      try {
         // @ Encode protected header and payload.
         $header = $this->Encoder->pack(json_encode($Header, JSON_THROW_ON_ERROR));
         $payload = $this->Encoder->pack(json_encode($claims, JSON_THROW_ON_ERROR));
      }
      catch (JsonException $Exception) {
         throw new RuntimeException('JWT JSON encoding failed.', previous: $Exception);
      }

      // @ Sign.
      $data = "{$header}.{$payload}";
      $signature = $this->Encoder->pack($this->Signer->seal($data, $this->Key));

      // :
      return "{$data}.{$signature}";
   }

   /**
    * Inspect a compact JWT and return a typed verification result.
    */
   public function inspect (string $token, null|Policies $Policies = null): Verification
   {
      // ! Segments
      $parts = explode('.', $token);
      if (count($parts) !== 3) {
         return Verification::fail(Failures::Malformed, 'Wrong number of JWT segments.');
      }

      [$header, $payload, $signature] = $parts;
      if ($header === '' || $payload === '' || $signature === '') {
         return Verification::fail(Failures::Malformed, 'JWT segments must not be empty.');
      }

      // ! Decode header.
      $decodedHeader = $this->Encoder->decode($header, Failures::Header);
      if ($decodedHeader instanceof Failures) {
         return Verification::fail($decodedHeader, $this->Validator->describe($decodedHeader));
      }

      $algorithm = $decodedHeader['alg'] ?? null;
      if (is_string($algorithm) === false || $this->Signer->support($algorithm) === false) {
         return Verification::fail(Failures::Algorithm, 'Unsupported JWT algorithm.');
      }

      $type = $decodedHeader['typ'] ?? null;
      if (is_string($type) === false || ($type !== 'JWT' && $type !== 'at+jwt')) {
         return Verification::fail(Failures::Header, 'Unsupported JWT type.');
      }

      $id = $decodedHeader['kid'] ?? null;
      if ($id !== null && is_string($id) === false) {
         return Verification::fail(Failures::Header, 'JWT key id must be a string.');
      }

      $Key = $this->Keys->resolve($id, $algorithm);
      if ($Key === null) {
         return Verification::fail(Failures::Key, 'JWT key could not be resolved.');
      }

      // @ Verify signature before trusting payload or header.
      $signatureBytes = $this->Encoder->unpack($signature);
      if ($signatureBytes === null) {
         return Verification::fail(Failures::Signature, 'Invalid JWT signature encoding.');
      }

      $Failure = $this->Signer->check("{$header}.{$payload}", $signatureBytes, $Key);
      if ($Failure !== null) {
         return Verification::fail($Failure, $this->Validator->describe($Failure));
      }

      // ! Decode payload.
      $claims = $this->Encoder->decode($payload, Failures::Payload);
      if ($claims instanceof Failures) {
         return Verification::fail($claims, $this->Validator->describe($claims));
      }

      $Failure = $this->Validator->validate($claims, $this->leeway, $this->timestamp);
      if ($Failure !== null) {
         return Verification::fail($Failure, $this->Validator->describe($Failure));
      }

      if ($Policies !== null) {
         $Failure = $this->Validator->enforce($claims, $Policies);
         if ($Failure !== null) {
            return Verification::fail($Failure, $this->Validator->describe($Failure));
         }
      }

      // : Trusted result.
      return Verification::pass($claims, new Header($decodedHeader), $Key);
   }

   /**
    * Verify a compact JWT and return trusted claims.
    *
    * @return array<string,mixed>|null
    */
   public function verify (string $token): null|array
   {
      $Verification = $this->inspect($token);

      return $Verification->valid ? $Verification->claims : null;
   }
}
