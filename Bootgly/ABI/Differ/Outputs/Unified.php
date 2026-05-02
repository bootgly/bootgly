<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ\Outputs;


use function array_splice;
use function assert;
use function count;
use function fclose;
use function fopen;
use function fwrite;
use function is_resource;
use function max;
use function min;
use function str_ends_with;
use function stream_get_contents;
use function substr;

use Bootgly\ABI\Differ\Codes;
use Bootgly\ABI\Differ\Output;


/**
 * Builds a diff in the (loose) unified-diff format used by tools like PHPUnit:
 * `--- Original` / `+++ New` headers, hunks with `@@ @@` (or with line numbers
 * when `$numbered` is `true`).
 */
final class Unified implements Output
{
   // * Config
   public string $header;
   public bool $numbered;
   /** @var positive-int */
   public int $context;

   public bool $collapse = true;
   public int $threshold = 6;


   /**
    * @param positive-int $context
    */
   public function __construct (
      string $header = "--- Original\n+++ New\n",
      bool $numbered = false,
      int $context = 3
   ) {
      $this->header   = $header;
      $this->numbered = $numbered;
      $this->context  = $context;
   }

   public function render (array $diff): string
   {
      $buffer = fopen('php://memory', 'r+b');

      assert(is_resource($buffer));

      if ($this->header !== '') {
         fwrite($buffer, $this->header);

         if (! str_ends_with($this->header, "\n")) {
            fwrite($buffer, "\n");
         }
      }

      if (count($diff) !== 0) {
         $this->emit($buffer, $diff);
      }

      $output = stream_get_contents($buffer, -1, 0);
      fclose($buffer);

      // If the diff is non-empty and last char is not a linebreak: add one.
      $last = substr($output, -1);

      return $output !== '' && $last !== "\n" && $last !== "\r"
         ? $output . "\n"
         : $output;
   }

   /**
    * @param resource                              $output
    * @param array<int, array{0: string, 1: int}>  $diff
    */
   private function emit (mixed $output, array $diff): void
   {
      assert(is_resource($output));

      // Insert "No newline at end of file" warning(s) where appropriate.
      $upper = count($diff);

      if ($diff[$upper - 1][1] === Codes::OLD->value) {
         $lc = substr($diff[$upper - 1][0], -1);

         if ($lc !== "\n") {
            array_splice(
               $diff,
               $upper,
               0,
               [["\n\\ No newline at end of file\n", Codes::LINE_END_EOF_WARNING->value]]
            );
         }
      }
      else {
         $toFind = [Codes::ADDED->value => true, Codes::REMOVED->value => true];

         for ($i = $upper - 1; $i >= 0; $i--) {
            if (isset($toFind[$diff[$i][1]])) {
               unset($toFind[$diff[$i][1]]);

               $lc = substr($diff[$i][0], -1);

               if ($lc !== "\n") {
                  array_splice(
                     $diff,
                     $i + 1,
                     0,
                     [["\n\\ No newline at end of file\n", Codes::LINE_END_EOF_WARNING->value]]
                  );
               }

               if ($toFind === []) {
                  break;
               }
            }
         }
      }

      // Hunk emission.
      $cutOff      = max($this->threshold, $this->context);
      $hunkCapture = false;
      $sameCount   = 0;
      $toRange     = 0;
      $fromRange   = 0;
      $toStart     = 1;
      $fromStart   = 1;

      foreach ($diff as $i => $entry) {
         if ($entry[1] === Codes::OLD->value) {
            if ($hunkCapture === false) {
               $fromStart++;
               $toStart++;
               continue;
            }

            $sameCount++;
            $toRange++;
            $fromRange++;

            if ($sameCount === $cutOff) {
               $contextStart = ($hunkCapture - $this->context) < 0
                  ? $hunkCapture
                  : $this->context;

               $this->write(
                  $diff,
                  $hunkCapture - $contextStart,
                  $i - $cutOff + $this->context + 1,
                  $fromStart - $contextStart,
                  $fromRange - $cutOff + $contextStart + $this->context,
                  $toStart - $contextStart,
                  $toRange - $cutOff + $contextStart + $this->context,
                  $output,
               );

               $fromStart += $fromRange;
               $toStart   += $toRange;

               $hunkCapture = false;
               $sameCount   = 0;
               $toRange     = 0;
               $fromRange   = 0;
            }

            continue;
         }

         $sameCount = 0;

         if ($entry[1] === Codes::LINE_END_EOF_WARNING->value) {
            continue;
         }

         if ($hunkCapture === false) {
            $hunkCapture = $i;
         }

         if ($entry[1] === Codes::ADDED->value) {
            $toRange++;
         }

         if ($entry[1] === Codes::REMOVED->value) {
            $fromRange++;
         }
      }

      if ($hunkCapture === false) {
         return;
      }

      $contextStart = $hunkCapture - $this->context < 0
         ? $hunkCapture
         : $this->context;
      $contextEnd   = min($sameCount, $this->context);

      $fromRange -= $sameCount;
      $toRange   -= $sameCount;

      assert(isset($i));

      $this->write(
         $diff,
         $hunkCapture - $contextStart,
         $i - $sameCount + $contextEnd + 1,
         $fromStart - $contextStart,
         $fromRange + $contextStart + $contextEnd,
         $toStart - $contextStart,
         $toRange + $contextStart + $contextEnd,
         $output,
      );
   }

   /**
    * @param array<int, array{0: string, 1: int}> $diff
    * @param resource                             $output
    */
   private function write (
      array $diff,
      int $diffStart,
      int $diffEnd,
      int $fromStart,
      int $fromRange,
      int $toStart,
      int $toRange,
      mixed $output
   ): void {
      assert(is_resource($output));

      if ($this->numbered) {
         fwrite($output, '@@ -' . $fromStart);

         if (! $this->collapse || $fromRange !== 1) {
            fwrite($output, ',' . $fromRange);
         }

         fwrite($output, ' +' . $toStart);

         if (! $this->collapse || $toRange !== 1) {
            fwrite($output, ',' . $toRange);
         }

         fwrite($output, " @@\n");
      }
      else {
         fwrite($output, "@@ @@\n");
      }

      for ($i = $diffStart; $i < $diffEnd; $i++) {
         $code    = $diff[$i][1];
         $content = $diff[$i][0];

         if ($code === Codes::ADDED->value) {
            fwrite($output, '+' . $content);
         }
         else if ($code === Codes::REMOVED->value) {
            fwrite($output, '-' . $content);
         }
         else if ($code === Codes::LINE_END_EOF_WARNING->value) {
            fwrite($output, "\n");
         }
         else {
            // OLD / LINE_END_WARNING / unknown — render as context.
            fwrite($output, ' ' . $content);
         }
      }
   }
}
