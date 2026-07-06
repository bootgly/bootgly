<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use Bootgly\ACI\Tests\Doubles\Doubling;


/**
 * Collection of test doubles registered during a test.
 */
class Doubles
{
   /**
    * @var array<int, Doubling>
    */
   public array $list = [];


   /**
    * Register a Double in the collection.
    */
   public function add (Doubling $Double): Doubling
   {
      $this->list[] = $Double;

      return $Double;
   }

   /**
    * Reset every registered Double.
    */
   public function reset (): void
   {
      foreach ($this->list as $Double) {
         $Double->reset();
      }
   }

   /**
    * Remove all registered Doubles from the collection.
    */
   public function clear (): void
   {
      $this->list = [];
   }
}
