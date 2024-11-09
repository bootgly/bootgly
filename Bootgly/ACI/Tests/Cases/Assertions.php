<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Cases;


use Exception;
use Closure;
use Generator;

use Bootgly\ACI\Tests;
use Bootgly\ACI\Tests\Assertion\Comparator;


class Assertions extends Tests\Assertions
{
   // * Config
   public ?Comparator $Comparator = null {
      get => $this->Comparator;
      set {
         Assertion::$Comparator = $value;
      }
   }

   // * Data
   protected Closure $Closure;

   // * Metadata
   private bool $ran;

   /**
    * Assertions constructor.
    *
    * @param Closure $Case The Test Case Closure.
    */
   public function __construct (Closure $Case)
   {
      // * Config
      // ...

      // * Data
      $this->Closure = $Case;

      // * Metadata
      $this->ran = false;

      // @
      $this->Closure = $this->Closure->bindTo(
         newThis: $this,
         newScope: 'static'
      );
   }

   // @ Configuring
   /**
    * Configure the Comparator to be used as default in the assertions.
    *
    * @param Comparator $With The Comparator to use in the assertions.
    *
    * @return $this 
    */
   public function assertAll (Comparator $With): self
   {
      // * Config
      $this->Comparator = $With;

      // :
      return $this;
   }

   // @
   /**
    * Run a collection of assertions.
    *
    * @param mixed[] $arguments
    *
    * @return Generator
    */
   public function run (mixed ...$arguments): Generator
   {
      // ?
      if ($this->ran === true) {
         throw new Exception('Assertions have already been run!');
      }

      // * Metadata
      $this->ran = true;
      // !
      $Assertions = ($this->Closure)(...$arguments);
      // ?!
      if ($Assertions instanceof Generator === false) {
         $Assertions = [$Assertions];
      }

      // @ ...
      foreach ($Assertions as $Assertion) {
         // : ...
         yield $Assertion;
      }
   }
}
