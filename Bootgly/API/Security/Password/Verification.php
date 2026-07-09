<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Password;


/**
 * Result of a password verification attempt with rehash-on-verify policy.
 */
class Verification
{
   // * Config
   // ...

   // * Data
   public private(set) bool $valid;
   /**
    * Fresh policy-conformant hash to persist.
    *
    * Filled only when `valid` is true and the stored hash no longer
    * conforms to the current hashing policy. Null when the password is
    * invalid or the stored hash is already up to date.
    */
   public private(set) null|string $hash;

   // * Metadata
   // ...


   /**
    * Create a verification result.
    */
   private function __construct (bool $valid, null|string $hash = null)
   {
      // * Data
      $this->valid = $valid;
      $this->hash = $hash;
   }

   /**
    * Build a successful verification result.
    */
   public static function pass (null|string $hash = null): self
   {
      return new self(true, $hash);
   }

   /**
    * Build a failed verification result.
    */
   public static function fail (): self
   {
      return new self(false);
   }
}
