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


use function array_values;
use function count;
use function is_a;
use function property_exists;
use Closure;
use Exception;
use Generator;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

use Bootgly\ACI\Tests\Assertions\Hook;


class Assertions
{
   // * Config
   /**
    * Fixture injected by the owning Specification/Test runner.
    */
   public null|Fixture $Fixture = null;
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
      $this->Fixture = null;

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
      $arguments = array_values($this->arguments ?? $arguments);
      $arguments = $this->inject($arguments, $this->Closure);
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

   /**
    * @param array<int,mixed> $arguments
    *
    * @return array<int,mixed>
    */
   private function inject (array $arguments, Closure $Closure): array
   {
      $Fixture = $this->Fixture;
      if ($Fixture === null) {
         return $arguments;
      }

      $Function = new ReflectionFunction($Closure);
      $parameters = $Function->getParameters();
      $Parameter = $parameters[count($arguments)] ?? null;

      if ($Parameter === null) {
         foreach ($parameters as $Candidate) {
            if ($Candidate->isVariadic()) {
               $Parameter = $Candidate;
               break;
            }
         }
      }

      if ($Parameter === null) {
         return $arguments;
      }

      if ($this->check($Parameter->getType(), $Fixture) === false) {
         return $arguments;
      }

      $arguments[] = $Fixture;

      return $arguments;
   }

   /**
    * Check if a reflected parameter type accepts the active Fixture.
    */
   private function check (null|ReflectionType $Type, Fixture $Fixture): bool
   {
      if ($Type === null) {
         return true;
      }

      if ($Type instanceof ReflectionUnionType) {
         foreach ($Type->getTypes() as $Inner) {
            if ($this->check($Inner, $Fixture)) {
               return true;
            }
         }

         return false;
      }

      if ($Type instanceof ReflectionIntersectionType) {
         foreach ($Type->getTypes() as $Inner) {
            if ($this->check($Inner, $Fixture) === false) {
               return false;
            }
         }

         return true;
      }

      if ($Type instanceof ReflectionNamedType) {
         $name = $Type->getName();
         if ($name === 'mixed' || $name === 'object') {
            return true;
         }
         if ($Type->isBuiltin()) {
            return false;
         }

         return is_a($Fixture, $name);
      }

      return false;
   }
}
