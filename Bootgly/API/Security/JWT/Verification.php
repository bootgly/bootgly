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


/**
 * Result of a JWT verification attempt.
 */
class Verification
{
   // * Config
   // ...

   // * Data
   public private(set) bool $valid;
   public private(set) null|Failures $failure;
   public private(set) string $message;
   /**
    * Trusted claims. Filled only when `valid` is true.
    *
    * @var array<string,mixed>
    */
   public private(set) array $claims;
   public private(set) null|Header $Header;
   public private(set) null|Key $Key;

   // * Metadata
   // ...


   /**
    * Create a verification result.
    *
    * @param array<string,mixed> $claims
    */
   private function __construct (
      bool $valid,
      null|Failures $Failure,
      array $claims = [],
      null|Header $Header = null,
      null|Key $Key = null,
      string $message = ''
   )
   {
      // * Data
      $this->valid = $valid;
      $this->failure = $Failure;
      $this->claims = $claims;
      $this->Header = $Header;
      $this->Key = $Key;
      $this->message = $message;
   }

   /**
    * Build a successful verification result.
    *
    * @param array<string,mixed> $claims
    */
   public static function pass (array $claims, Header $Header, Key $Key): self
   {
      return new self(true, null, $claims, $Header, $Key);
   }

   /**
    * Build a failed verification result.
    */
   public static function fail (Failures $Failure, string $message = ''): self
   {
      return new self(false, $Failure, message: $message);
   }
}
