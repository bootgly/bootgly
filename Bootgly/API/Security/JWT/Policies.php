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


use function is_string;
use InvalidArgumentException;


/**
 * Declarative JWT registered-claim policies.
 */
class Policies
{
   // * Config
   /**
    * Accepted issuer values for the `iss` claim.
    *
    * @var array<int,string>
    */
   public private(set) array $issuers;
   /**
    * Accepted audience values for the `aud` claim.
    *
    * @var array<int,string>
    */
   public private(set) array $audiences;
   /**
    * Whether `sub` must exist as a non-empty string.
    */
   public private(set) bool $subject;
   /**
    * Whether `jti` must exist as a non-empty string.
    */
   public private(set) bool $identifier;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Configure claim policies.
    *
    * @param string|array<int,string> $issuers
    * @param string|array<int,string> $audiences
    */
   public function __construct (
      string|array $issuers = [],
      string|array $audiences = [],
      bool $subject = false,
      bool $identifier = false
   )
   {
      // * Config
      $this->issuers = $this->normalize($issuers, 'issuer');
      $this->audiences = $this->normalize($audiences, 'audience');
      $this->subject = $subject;
      $this->identifier = $identifier;
   }

   /**
    * Normalize a policy string list.
    *
    * @param string|array<int|string,mixed> $values
    * @return array<int,string>
    */
   private function normalize (string|array $values, string $name): array
   {
      if (is_string($values)) {
         if ($values === '') {
            throw new InvalidArgumentException("JWT {$name} policy must not be empty.");
         }

         return [$values];
      }

      $Values = [];
      foreach ($values as $value) {
         if (is_string($value) === false || $value === '') {
            throw new InvalidArgumentException("JWT {$name} policies must be non-empty strings.");
         }

         $Values[] = $value;
      }

      return $Values;
   }
}