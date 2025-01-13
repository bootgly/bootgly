<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;



use Exception;
use Closure;
use Generator;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Assertions\Hook;


class Assertions
{
   // * Config
   /**
    * The descriptions of the Assertion.
    * @var array<string|null>
    */
   public array $descriptions = [];

   // * Data
   protected Closure $Closure;
   /** @var array<bool|null> */
   protected array $results = [];
   // # Dataset
   /**
    * The arguments to be passed to the test case.
    *
    * @var mixed[]
    */
   protected array $arguments;
   // # Hooks
   protected Closure $onBeforeAll;
   protected Closure $onAfterAll;
   protected Closure $onBeforeEach;
   protected Closure $onAfterEach;

   // * Metadata
   public static int $count = 0;
   private bool $ran;

   /**
    * Assertions constructor.
    *
    * @param Closure $Case The Test Case Closure.
    */
   public function __construct (Closure $Case)
   {
      // * Config
      // -

      // * Data
      $this->Closure = $Case->bindTo(
         newThis: $this,
         newScope: 'static'
      );
      // # Dataset
      // $this->arguments = undefined;
      // # Hooks
      // $this->onBeforeAll = undefined;
      // $this->onAfterAll = undefined;
      // $this->onBeforeEach = undefined;
      // $this->onAfterEach = undefined;

      // * Metadata
      $this->ran = false;
   }

   // @ Configuring
   // # Hooks
   /**
    * Set a hook to assertions.
    *
    * @param Hook|string $Event The hook to be set.
    * @param Closure $Callback The callback to be set.
    *
    * @return self
    */
   public function on (Hook|string $Event, Closure $Callback): self
   {
      // ?!
      if ($Event instanceof Hook) {
         $Event = $Event->value;
      }
      // ! Hook
      $hook = "on{$Event}";

      // ?
      if (property_exists($this, $hook) === false) {
         throw new InvalidArgumentException(
            "The hook '{$hook}' does not exist."
         );
      }

      // @
      $this->$hook = $Callback;

      // :
      return $this;
   }

   // # Dataset
   /**
    * Input dataset for the assertions.
    *
    * @param mixed[] $data
    *
    * @return self
    */
   public function input (mixed ...$data): self
   {
      // * Data
      $this->arguments = $data;

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
   public function run (...$arguments): Generator
   {
      // ?
      if ($this->ran === true) {
         throw new Exception('Assertions have already been run!');
      }

      // * Config
      // -
      // * Data
      // # Dataset
      $arguments = $this->arguments ?? $arguments;
      // # Hooks
      $OnBeforeAll = $this->onBeforeAll ?? null;
      $OnAfterAll = $this->onAfterAll ?? null;
      $OnBeforeEach = $this->onBeforeEach ?? null;
      $OnAfterEach = $this->onAfterEach ?? null;

      // * Metadata
      $this->ran = true;

      // @
      $Assertions = ($this->Closure)(...$arguments);

      // ?!
      if ($Assertions instanceof Generator === false) {
         $Assertions = [$Assertions];
      }

      if ($OnBeforeAll) {
         $OnBeforeAll($Assertions, $arguments);
      }

      foreach ($Assertions as $Assertion) {
         if ($OnBeforeEach) {
            $OnBeforeEach($Assertion, $arguments);
         }

         // : ...
         yield $Assertion;

         if ($OnAfterEach) {
            $OnAfterEach($Assertion, $arguments);
         }
      }

      if ($OnAfterAll) {
         $OnAfterAll($Assertions, $arguments);
      }
   }
}
