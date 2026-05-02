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


use function array_merge;
use function array_splice;
use function assert;
use function count;
use function fclose;
use function fopen;
use function fwrite;
use function is_bool;
use function is_int;
use function is_resource;
use function is_string;
use function max;
use function min;
use function sprintf;
use function stream_get_contents;
use function substr;

use Bootgly\ABI\Differ\Codes;
use Bootgly\ABI\Differ\Exceptions\Configuration;
use Bootgly\ABI\Differ\Output;


/**
 * Builds a strict unified-diff (with hunks) compatible with
 * `diff -u`, `patch`, and `git apply`.
 */
final class UnifiedStrict implements Output
{
   private const array DEFAULTS = [
      'collapseRanges'      => true,
      'commonLineThreshold' => 6,
      'contextLines'        => 3,
      'fromFile'            => null,
      'fromFileDate'        => null,
      'toFile'              => null,
      'toFileDate'          => null,
   ];

   // * Config
   public private(set) bool $collapse;
   /** @var positive-int */
   public private(set) int $threshold;
   /** @var int<0, max> */
   public private(set) int $context;
   public private(set) string $header;

   // * Metadata
   private bool $changed = false;


   /**
    * @param array<string, mixed> $options
    */
   public function __construct (array $options = [])
   {
      $options = array_merge(self::DEFAULTS, $options);

      $collapse = $options['collapseRanges'];

      if (! is_bool($collapse)) {
         throw new Configuration('collapseRanges', 'a bool', $collapse);
      }

      $context = $options['contextLines'];

      if (! is_int($context) || $context < 0) {
         throw new Configuration('contextLines', 'an int >= 0', $context);
      }

      $threshold = $options['commonLineThreshold'];

      if (! is_int($threshold) || $threshold <= 0) {
         throw new Configuration('commonLineThreshold', 'an int > 0', $threshold);
      }

      $fromFile     = $this->fetch($options, 'fromFile');
      $toFile       = $this->fetch($options, 'toFile');
      $fromFileDate = $this->resolve($options, 'fromFileDate');
      $toFileDate   = $this->resolve($options, 'toFileDate');

      $this->header = sprintf(
         "--- %s%s\n+++ %s%s\n",
         $fromFile,
         $fromFileDate === null ? '' : "\t" . $fromFileDate,
         $toFile,
         $toFileDate === null ? '' : "\t" . $toFileDate,
      );

      $this->collapse  = $collapse;
      $this->threshold = $threshold;
      $this->context   = $context;
   }

   public function render (array $diff): string
   {
      if (count($diff) === 0) {
         return '';
      }

      $this->changed = false;

      $buffer = fopen('php://memory', 'r+b');

      assert(is_resource($buffer));

      fwrite($buffer, $this->header);

      $this->emit($buffer, $diff);

      if (! $this->changed) {
         fclose($buffer);

         return '';
      }

      $output = stream_get_contents($buffer, -1, 0);
      fclose($buffer);

      $last = substr($output, -1);

      return $last !== "\n" && $last !== "\r"
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

         $this->changed = true;

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

      fwrite($output, '@@ -' . $fromStart);

      if (! $this->collapse || $fromRange !== 1) {
         fwrite($output, ',' . $fromRange);
      }

      fwrite($output, ' +' . $toStart);

      if (! $this->collapse || $toRange !== 1) {
         fwrite($output, ',' . $toRange);
      }

      fwrite($output, " @@\n");

      for ($i = $diffStart; $i < $diffEnd; $i++) {
         $code    = $diff[$i][1];
         $content = $diff[$i][0];

         if ($code === Codes::ADDED->value) {
            $this->changed = true;
            fwrite($output, '+' . $content);
         }
         else if ($code === Codes::REMOVED->value) {
            $this->changed = true;
            fwrite($output, '-' . $content);
         }
         else if ($code === Codes::OLD->value) {
            fwrite($output, ' ' . $content);
         }
         else if ($code === Codes::LINE_END_EOF_WARNING->value) {
            $this->changed = true;
            fwrite($output, $content);
         }
      }
   }

   /**
    * @param array<string, mixed> $options
    */
   private function fetch (array $options, string $option): string
   {
      $value = $options[$option];

      if (! is_string($value)) {
         throw new Configuration(
            $option,
            'a string',
            $value,
         );
      }

      return $value;
   }

   /**
    * @param array<string, mixed> $options
    */
   private function resolve (array $options, string $option): ?string
   {
      $value = $options[$option];

      if ($value === null) {
         return null;
      }

      if (! is_string($value)) {
         throw new Configuration(
            $option,
            'a string or <null>',
            $value,
         );
      }

      return $value;
   }
}
