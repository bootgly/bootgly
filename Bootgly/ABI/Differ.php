<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI;


use const PHP_INT_SIZE;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;
use function array_shift;
use function array_unshift;
use function array_values;
use function count;
use function current;
use function end;
use function is_string;
use function key;
use function min;
use function preg_split;
use function prev;
use function reset;
use function str_ends_with;
use function substr;

use Bootgly\ABI\Differ\Calculating;
use Bootgly\ABI\Differ\Calculators\Memory;
use Bootgly\ABI\Differ\Calculators\Time;
use Bootgly\ABI\Differ\Codes;
use Bootgly\ABI\Differ\Output;


/**
 * Computes the difference between two text inputs (string or `list<string>`),
 * delegating LCS computation to a `Calculating` strategy and string rendering
 * to an `Output` builder.
 *
 * Default LCS strategy: chosen automatically based on input size to balance
 * speed and memory. Override by passing a `Calculating` instance to `diff()`
 * or to the constructor.
 */
final class Differ
{
   // @ Memory budget (bytes) above which the time-efficient calculator is
   //   skipped in favor of the memory-efficient one. ~100 MiB.
   private const int MEMORY_LIMIT = 104857600;

   // * Config
   public private(set) Output $Output;
   public private(set) ?Calculating $Calculator;


   public function __construct (Output $Output, ?Calculating $Calculator = null)
   {
      $this->Output     = $Output;
      $this->Calculator = $Calculator;
   }

   /**
    * @param list<string>|string $from
    * @param list<string>|string $to
    */
   public function diff (array|string $from, array|string $to, ?Calculating $Calculator = null): string
   {
      $diff = $this->compose($from, $to, $Calculator);

      return $this->Output->render($diff);
   }

   /**
    * Compose the internal diff array (`[content, code]` entries).
    *
    * @param  list<string>|string $from
    * @param  list<string>|string $to
    * @return array<int, array{0: string, 1: int}>
    */
   public function compose (array|string $from, array|string $to, ?Calculating $Calculator = null): array
   {
      if (is_string($from)) {
         $from = $this->split($from);
      }

      if (is_string($to)) {
         $to = $this->split($to);
      }

      [$from, $to, $start, $end] = self::part($from, $to);

      $Calculator ??= $this->Calculator ?? $this->select($from, $to);

      $common = $Calculator->calculate(array_values($from), array_values($to));
      $diff   = [];

      foreach ($start as $token) {
         $diff[] = [$token, Codes::OLD->value];
      }

      reset($from);
      reset($to);

      foreach ($common as $token) {
         while (count($from) > 0 && reset($from) !== $token) {
            $diff[] = [array_shift($from), Codes::REMOVED->value];
         }

         while (count($to) > 0 && reset($to) !== $token) {
            $diff[] = [array_shift($to), Codes::ADDED->value];
         }

         $diff[] = [$token, Codes::OLD->value];

         array_shift($from);
         array_shift($to);
      }

      while (count($from) > 0) {
         $token = array_shift($from);

         $diff[] = [$token, Codes::REMOVED->value];
      }

      while (count($to) > 0) {
         $token = array_shift($to);

         $diff[] = [$token, Codes::ADDED->value];
      }

      foreach ($end as $token) {
         $diff[] = [$token, Codes::OLD->value];
      }

      if ($this->detect($diff)) {
         array_unshift(
            $diff,
            ["#Warning: Strings contain different line endings!\n", Codes::LINE_END_WARNING->value]
         );
      }

      return $diff;
   }

   /**
    * @return list<string>
    */
   private function split (string $input): array
   {
      $tokens = preg_split('/(.*\R)/', $input, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

      return $tokens === false ? [] : $tokens;
   }

   /**
    * @param array<int, string> $from
    * @param array<int, string> $to
    */
   private function select (array $from, array $to): Calculating
   {
      // Footprint estimate for the time-efficient matrix:
      // ~76 bytes/cell on 32-bit, ~144 bytes/cell on 64-bit PHP.
      $itemSize  = PHP_INT_SIZE === 4 ? 76 : 144;
      $footprint = $itemSize * min(count($from), count($to)) ** 2;

      if ($footprint > self::MEMORY_LIMIT) {
         return new Memory;
      }

      return new Time;
   }

   /**
    * @param array<int, array{0: string, 1: int}> $diff
    */
   private function detect (array $diff): bool
   {
      $newBreaks = ['' => true];
      $oldBreaks = ['' => true];

      foreach ($diff as $entry) {
         $code = $entry[1];
         $br   = $this->resolve($entry[0]);

         if ($code === Codes::OLD->value) {
            $oldBreaks[$br] = true;
            $newBreaks[$br] = true;
         }
         else if ($code === Codes::ADDED->value) {
            $newBreaks[$br] = true;
         }
         else if ($code === Codes::REMOVED->value) {
            $oldBreaks[$br] = true;
         }
      }

      // No warning if either side has no real line breaks.
      if ($newBreaks === ['' => true] || $oldBreaks === ['' => true]) {
         return false;
      }

      foreach ($newBreaks as $br => $_) {
         if (! isset($oldBreaks[$br])) {
            return true;
         }
      }

      foreach ($oldBreaks as $br => $_) {
         if (! isset($newBreaks[$br])) {
            return true;
         }
      }

      return false;
   }

   private function resolve (int|string $line): string
   {
      if (! is_string($line)) {
         return '';
      }

      $lc = substr($line, -1);

      if ($lc === "\r") {
         return "\r";
      }

      if ($lc !== "\n") {
         return '';
      }

      if (str_ends_with($line, "\r\n")) {
         return "\r\n";
      }

      return "\n";
   }

   /**
    * Strip identical leading and trailing tokens from `$from`/`$to`,
    * returning `[$fromTrimmed, $toTrimmed, $startCommon, $endCommon]`.
    *
    * @param  array<int, string> $from
    * @param  array<int, string> $to
    * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>, 3: array<int, string>}
    */
   private static function part (array &$from, array &$to): array
   {
      $start = [];
      $end   = [];

      reset($to);

      foreach ($from as $k => $v) {
         $toK = key($to);

         if ($toK === $k && $v === $to[$k]) {
            $start[$k] = $v;

            unset($from[$k], $to[$k]);
         }
         else {
            break;
         }
      }

      end($from);
      end($to);

      while (true) {
         $fromK = key($from);
         $toK   = key($to);

         if ($fromK === null || $toK === null || current($from) !== current($to)) {
            break;
         }

         prev($from);
         prev($to);

         $end = [$fromK => $from[$fromK]] + $end;
         unset($from[$fromK], $to[$toK]);
      }

      return [$from, $to, $start, $end];
   }
}
