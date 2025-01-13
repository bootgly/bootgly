<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectation;


use Closure;

use Bootgly\ACI\Tests\Asserting;
use Bootgly\ACI\Tests\Asserting\Subassertion;


/**
 * Waiter are for assertions that wait for a condition.
 * 
 * Use both $actual (callable) and $expected (timeout in microseconds) as input in the assertion.
 * The $expected is the value to wait for.
 * 
 * It has a duration (in microseconds) as output.
 */
abstract class Waiter extends Subassertion implements Asserting
{
   // * Config
   // # Input
   /**
    * The timeout to wait for the callable in microseconds or the output handler (subassertion).
    * @var int|float
    */
   public int|float $expected;

   /**
    * The arguments to pass to the callable.
    * @var array<mixed>
    */
   public array $arguments;
   // # Subassertion
   // ..$subassertion

   // * Metadata
   // # Output
   public protected(set) float $duration {
      get => $this->duration;
      set {
         $this->duration = $value * 1000000;

         $this->actual = $this->duration;
      }
   }


   /**
    * Wait for a condition.
    * 
    * @param int|float $expected The timeout to wait for the callable in microseconds or the output handler (subassertion).
    * @param array<mixed> $arguments
    */
   public function __construct (Closure|float|int $expected, array $arguments)
   {
      // * Config
      // # Input
      $this->expected = $expected instanceof Closure 
         ? 0
         : $expected;
      $this->arguments = $arguments;
      // # Output
      if ($expected instanceof Closure) {
         $this->subassertion = $expected;
      }

      // * Metadata
      $this->duration = 0.0;
   }

   // # Output
   /**
    * Output the duration of the wait.
    * 
    * @return void
    */
   public function output (): void
   {
      // !
      $duration = $this->duration;

      $Subassertion = $this->subassertion;
      if ($Subassertion === null) {
         return;
      }

      // @ Call the output callable with $this->duration as argument
      $Subassertion($duration);
   }
}
