<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
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
   /**
    * @var array<int,Stub>
    */
   private array $list = [];


   /**
    * Append a stub rule to the LIFO registry.
    */
   public function add (Stub $Stub): void
   {
      $this->list[] = $Stub;
   }

   /**
    * Remove every configured stub rule.
    */
   public function reset (): void
   {
      $this->list = [];
   }

   /**
    * @param array<int,mixed> $arguments
    */
   public function match (string $method, array $arguments): null|Stub
   {
      // LIFO so a later stub() call shadows earlier ones for the same method.
      for ($i = count($this->list) - 1; $i >= 0; $i--) {
         if ($this->list[$i]->check($method, $arguments)) {
            return $this->list[$i];
         }
      }

      return null;
   }
}