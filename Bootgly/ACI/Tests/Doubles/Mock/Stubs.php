<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Doubles\Mock;


use function count;


/**
 * Collection of Stub rules — last-match-wins so tests can override stubs.
 */
class Stubs
{
   // * Data
   /**
    * @var array<int,Stub>
    */
   private array $List = [];


   /**
    * Append a stub rule to the LIFO registry.
    */
   public function add (Stub $Stub): void
   {
      $this->List[] = $Stub;
   }

   /**
    * Remove every configured stub rule.
    */
   public function reset (): void
   {
      $this->List = [];
   }

   /**
    * @param array<int,mixed> $arguments
    */
   public function match (string $method, array $arguments): null|Stub
   {
      // LIFO so a later stub() call shadows earlier ones for the same method.
      for ($i = count($this->List) - 1; $i >= 0; $i--) {
         if ($this->List[$i]->check($method, $arguments)) {
            return $this->List[$i];
         }
      }

      return null;
   }
}