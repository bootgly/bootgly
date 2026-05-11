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


use function ctype_digit;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function time;


/**
 * JWT registered-claim and policy validator.
 */
class Validator
{
   /**
    * Validate JWT registered time claims.
    *
    * @param array<string,mixed> $claims
    */
   public function validate (array $claims, int $leeway = 0, null|int $timestamp = null): null|Failures
   {
      $now = $timestamp ?? time();

      if (isset($claims['exp'])) {
         $expiration = $this->coerce($claims['exp']);
         if ($expiration === null || $expiration <= $now - $leeway) {
            return Failures::Expired;
         }
      }

      if (isset($claims['nbf'])) {
         $notBefore = $this->coerce($claims['nbf']);
         if ($notBefore === null || $notBefore > $now + $leeway) {
            return Failures::Before;
         }
      }

      if (isset($claims['iat'])) {
         $issuedAt = $this->coerce($claims['iat']);
         if ($issuedAt === null || $issuedAt > $now + $leeway) {
            return Failures::Issued;
         }
      }

      return null;
   }

   /**
    * Enforce configured claim policies.
    *
    * @param array<string,mixed> $claims
    */
   public function enforce (array $claims, Policies $Policies): null|Failures
   {
      if ($Policies->issuers !== []) {
         $issuer = $claims['iss'] ?? null;
         if (is_string($issuer) === false || in_array($issuer, $Policies->issuers, true) === false) {
            return Failures::Issuer;
         }
      }

      if ($Policies->audiences !== []) {
         $audience = $claims['aud'] ?? null;
         if ($this->accept($audience, $Policies->audiences) === false) {
            return Failures::Audience;
         }
      }

      if ($Policies->subject) {
         $subject = $claims['sub'] ?? null;
         if (is_string($subject) === false || $subject === '') {
            return Failures::Subject;
         }
      }

      if ($Policies->identifier) {
         $identifier = $claims['jti'] ?? null;
         if (is_string($identifier) === false || $identifier === '') {
            return Failures::Identifier;
         }
      }

      return null;
   }

   /**
    * Describe a JWT verification failure.
    */
   public function describe (Failures $Failure): string
   {
      return match ($Failure) {
         Failures::Malformed => 'Wrong number of JWT segments.',
         Failures::Header => 'Invalid JWT header.',
         Failures::Payload => 'Invalid JWT payload.',
         Failures::Algorithm => 'Unsupported JWT algorithm.',
         Failures::Key => 'JWT key could not be resolved.',
         Failures::Signature => 'JWT signature verification failed.',
         Failures::Expired => 'JWT expiration claim is not valid.',
         Failures::Before => 'JWT not-before claim is not valid.',
         Failures::Issued => 'JWT issued-at claim is not valid.',
         Failures::JSON => 'JWT JSON segment is not valid.',
         Failures::OpenSSL => 'JWT OpenSSL verification failed.',
         Failures::Network => 'JWT remote key resolver could not be reached.',
         Failures::Status => 'JWT remote key resolver returned an invalid status.',
         Failures::JWKS => 'JWT remote key resolver returned an invalid JWKS document.',
         Failures::Revoked => 'JWT identifier has been revoked.',
         Failures::Replay => 'JWT identifier has already been used.',
         Failures::Issuer => 'JWT issuer claim is not valid.',
         Failures::Audience => 'JWT audience claim is not valid.',
         Failures::Subject => 'JWT subject claim is required.',
         Failures::Identifier => 'JWT identifier claim is required.',
      };
   }

   /**
    * Accept a JWT audience claim against configured audiences.
    *
    * @param array<int,string> $audiences
    */
   private function accept (mixed $claim, array $audiences): bool
   {
      if (is_string($claim)) {
         return $claim !== '' && in_array($claim, $audiences, true);
      }

      if (is_array($claim) === false || $claim === []) {
         return false;
      }

      foreach ($claim as $audience) {
         if (is_string($audience) === false || $audience === '') {
            return false;
         }
      }

      foreach ($claim as $audience) {
         if (in_array($audience, $audiences, true)) {
            return true;
         }
      }

      return false;
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
