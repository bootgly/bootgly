<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Authorization;


use function array_key_exists;
use Closure;
use InvalidArgumentException;

use Bootgly\API\Security\Identity;


/**
 * Named authorization ability registry.
 */
class Abilities
{
   // * Config
   // ...

   // * Data
   /**
    * Ability checks keyed by ability name.
    *
    * @var array<string,Closure>
    */
   private array $Checks = [];

   // * Metadata
   // ...


   /**
    * Define a named authorization ability.
    *
    * @param Closure(Identity,mixed...):bool $Check
    */
   public function define (string $ability, Closure $Check): self
   {
      if ($ability === '') {
         throw new InvalidArgumentException('Authorization ability name must not be empty.');
      }

      // @
      $this->Checks[$ability] = $Check;

      return $this;
   }

   /**
    * Check a named authorization ability.
    */
   public function check (Identity $Identity, string $ability, mixed ...$arguments): bool
   {
      if (array_key_exists($ability, $this->Checks) === false) {
         return false;
      }

      // @
      $Check = $this->Checks[$ability];

      // : Strict allow only.
      return $Check($Identity, ...$arguments) === true;
   }
}
