<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz;


use function getenv;
use function is_numeric;
use function is_string;
use function mt_srand;
use Closure;


/**
 * Property — minimal property-based test runner.
 *
 * Each iteration:
 *   1. Seeds `mt_rand` with `seed + i` so individual cases are reproducible
 *      from the (seed, iteration) tuple alone.
 *   2. Calls `$generator(int $i): mixed` to produce an input.
 *   3. Calls `$invariant(mixed $input): bool|string`. Returning `true` keeps
 *      iterating; returning a string aborts and surfaces it as a fail
 *      message tagged with the failing seed + iteration index.
 *
 * Determinism: the master seed is `BOOTGLY_FUZZ_SEED` (env) or `$default`.
 * Iteration count is `BOOTGLY_FUZZ_ITERATIONS` (env) or `$iterations`.
 *
 * No shrinking: failures dump the raw input + the (seed, i) pair so the
 * exact case can be reproduced manually with one targeted run.
 */
class Property
{
   /**
    * Run a property test.
    *
    * @param Closure(int):mixed $generator
    * @param Closure(mixed):(bool|string) $invariant
    */
   public static function test (
      Closure $generator,
      Closure $invariant,
      int $default = 0xB007,
      int $iterations = 100,
   ): true|string
   {
      $seedEnv = getenv('BOOTGLY_FUZZ_SEED');
      $iterEnv = getenv('BOOTGLY_FUZZ_ITERATIONS');

      $seed = (is_string($seedEnv) && is_numeric($seedEnv))
         ? (int) $seedEnv
         : $default;
      $N = (is_string($iterEnv) && is_numeric($iterEnv))
         ? (int) $iterEnv
         : $iterations;

      for ($i = 0; $i < $N; $i++) {
         mt_srand($seed + $i);

         $input = $generator($i);
         $result = $invariant($input);

         if ($result === true) {
            continue;
         }

         // @ Failure: surface seed + iter so the exact case reproduces.
         $message = (string) $result;
         return "[seed={$seed} iter={$i}] {$message}";
      }

      return true;
   }
}
