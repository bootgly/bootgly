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


use function is_array;
use InvalidArgumentException;


/**
 * Minimal JSON Web Key Set parser for RSA public keys.
 */
class JWKS
{
   /**
    * Parse a JWKS document into a JWT key set.
    *
    * @param array<string,mixed> $jwks
    */
   public static function parse (array $jwks, null|string $algorithm = null): KeySet
   {
      $keys = $jwks['keys'] ?? null;
      if (is_array($keys) === false) {
         throw new InvalidArgumentException('JWKS must contain a keys array.');
      }
      if ($keys === []) {
         throw new InvalidArgumentException('JWKS must contain at least one key.');
      }

      $KeySet = new KeySet;
      /** @var array<int|string,mixed> $keys */
      foreach ($keys as $jwk) {
         if (is_array($jwk) === false) {
            throw new InvalidArgumentException('JWKS keys must be objects.');
         }

         /** @var array<string,mixed> $jwk */
         $KeySet->add(JWK::parse($jwk, $algorithm));
      }

      return $KeySet;
   }
}
