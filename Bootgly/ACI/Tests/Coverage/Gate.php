<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage;


use const T_CLASS;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_ENUM;
use const T_STRING;
use const T_TRAIT;
use const T_WHITESPACE;
use function abs;
use function count;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_finite;
use function is_readable;
use function round;
use function sprintf;
use function token_get_all;
use InvalidArgumentException;


/**
 * Coverage gate — checks a measured hit map against the thresholds a run
 * must meet.
 *
 * A gate never passes on an empty measurement: a hit map with no executable
 * statement fails every rule, so a report written by a run that measured
 * nothing cannot read as a pass.
 *
 *   $Gate = new Gate(floor: 80.0, classes: true);
 *   if ($Gate->check($Coverage->data) === false) {
 *      foreach ($Gate->failures as $failure) { ... }
 *   }
 */
final class Gate
{
   // * Config
   /**
    * Statements floor, in percent (0–100, compared at four decimal places):
    * the share of executable statements that must be covered. `null`
    * disables the floor.
    */
   public private(set) null|float $floor;
   /**
    * Fail when a source that declares a class, trait or enum has executable
    * statements and none of them is covered.
    */
   public private(set) bool $classes;

   // * Metadata
   /**
    * Executable statements measured by the last `check()`.
    */
   public private(set) int $statements = 0;
   /**
    * Covered statements measured by the last `check()`.
    */
   public private(set) int $covered = 0;
   /**
    * Covered share of the last `check()`, in percent (`0.0` when nothing
    * was measured).
    */
   public float $percent {
      get => $this->statements > 0
         ? ($this->covered / $this->statements) * 100
         : 0.0;
   }
   /**
    * Sources of the last `check()` that declare a class, trait or enum and
    * have no covered statement — filled only when `$classes` is on.
    *
    * @var array<int,string>
    */
   public private(set) array $zeros = [];
   /**
    * Human-readable reasons the last `check()` failed (empty on a pass).
    *
    * @var array<int,string>
    */
   public private(set) array $failures = [];


   /**
    * Create a coverage gate.
    *
    * @param null|float $floor Statements floor in percent (0–100), or `null`.
    * @param bool $classes Fail on a class, trait or enum source at zero.
    *
    * @throws InvalidArgumentException When the floor is not finite, is
    *    outside 0–100 or has more than four decimals.
    */
   public function __construct (null|float $floor = null, bool $classes = false)
   {
      // ?
      if ($floor !== null && is_finite($floor) === false) {
         throw new InvalidArgumentException('Coverage floor must be a finite percentage between 0 and 100.');
      }
      if ($floor !== null && abs($floor * 10_000 - round($floor * 10_000)) > 1e-6) {
         throw new InvalidArgumentException(
            "Coverage floor takes at most four decimals, got {$floor}."
         );
      }
      if ($floor !== null && ($floor < 0.0 || $floor > 100.0)) {
         throw new InvalidArgumentException(
            "Coverage floor must be a percentage between 0 and 100, got {$floor}."
         );
      }

      // * Config
      $this->floor = $floor;
      $this->classes = $classes;
   }

   /**
    * Check a hit map against the gate and record its metrics and failures.
    *
    * @param array<string,array<int,int>> $data Canonical hit map
    *    (`[file => [line => hits]]`), as `Coverage::$data` holds it.
    *
    * @return bool `true` when every enabled rule passes.
    */
   public function check (array $data): bool
   {
      // !
      $statements = 0;
      $covered = 0;
      $zeros = [];
      $failures = [];

      // @@ Count statements per source
      foreach ($data as $file => $lines) {
         $total = count($lines);
         $hit = 0;
         foreach ($lines as $hits) {
            if ($hits > 0) {
               $hit++;
            }
         }

         $statements += $total;
         $covered += $hit;

         if ($this->classes && $total > 0 && $hit === 0 && self::declare($file)) {
            $zeros[] = $file;
         }
      }

      // @ Rules
      if (($this->floor !== null || $this->classes) && $statements === 0) {
         $failures[] = 'Coverage gate: no executable statement was measured, '
            . 'so no threshold can pass.';
      }
      else {
         // ? Integer cross-multiplication on the floor in ten-thousandths of
         //   a percent: a float product would fail a share equal to the floor
         if ($this->floor !== null && $covered * 1_000_000 < (int) round($this->floor * 10_000) * $statements) {
            $failures[] = sprintf(
               'Coverage gate: %.2f%% of %d statements covered (%d), below the floor of %s%%.',
               ($covered / $statements) * 100,
               $statements,
               $covered,
               (string) $this->floor
            );
         }

         if ($zeros !== []) {
            $count = count($zeros);
            $failures[] = "Coverage gate: {$count} class source(s) with no covered statement:";
            foreach ($zeros as $zero) {
               $failures[] = "  {$zero}";
            }
         }
      }

      // * Metadata
      $this->statements = $statements;
      $this->covered = $covered;
      $this->zeros = $zeros;
      $this->failures = $failures;

      // :
      return $failures === [];
   }

   /**
    * Tell whether a source declares a named class, trait or enum.
    *
    * Token-based: the keyword must be followed by a name, which rules out
    * `Foo::class` and anonymous `new class`.
    */
   private static function declare (string $file): bool
   {
      // ?
      if (is_file($file) === false || is_readable($file) === false) {
         return false;
      }

      $source = file_get_contents($file);
      if ($source === false) {
         return false;
      }

      // !
      $tokens = token_get_all($source);
      $total = count($tokens);

      // @@
      for ($index = 0; $index < $total; $index++) {
         $token = $tokens[$index];
         if (is_array($token) === false) {
            continue;
         }

         $id = $token[0];
         if ($id !== T_CLASS && $id !== T_TRAIT && $id !== T_ENUM) {
            continue;
         }

         // @ Next significant token
         for ($next = $index + 1; $next < $total; $next++) {
            $following = $tokens[$next];
            if (
               is_array($following)
               && ($following[0] === T_WHITESPACE || $following[0] === T_COMMENT || $following[0] === T_DOC_COMMENT)
            ) {
               continue;
            }

            if (is_array($following) && $following[0] === T_STRING) {
               return true;
            }

            break;
         }
      }

      // :
      return false;
   }
}
