<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Tests;


use AssertionError;
use Bootgly\API\Tests;
use Bootgly\API\Logs\Escaped\Loggable as LoggableEscaped;


class Test
{
   use LoggableEscaped;


   public Tests $Tests;

   // * Config
   // ...

   // * Data
   public array $specifications;

   // * Meta
   public $test;
   public array $assertions;
   public false|string $debugged;
   // @ Time
   public float $started;
   public float $finished;


   public function __construct (Tests $Tests, array $specifications)
   {
      $this->Tests = $Tests;

      // * Config
      // ...

      // * Data
      $this->specifications = $specifications;

      // * Meta
      $this->test = current($this->Tests->tests); // @ file
      $this->assertions = [];
      $this->debugged = false;
      // @ Time
      $this->started = microtime(true);


      // @ Set PHP assert options
      // 1
      assert_options(ASSERT_ACTIVE, 1);
      // 2
      #assert_options(ASSERT_CALLBACK, function ($file, $line, $code) {});
      // 3
      assert_options(ASSERT_BAIL, 0);
      // 4
      assert_options(ASSERT_WARNING, 0);
      // 5
      assert_options(ASSERT_EXCEPTION, 1);
   }
   public function __get (string $name)
   {
      return match ($name) {
         'success' => array_reduce($this->assertions, function ($accumulator, $assertion) {
            return $accumulator && $assertion;
         }, true),
         default => null
      };
   }

   // @ Arrange
   public function describe (? string $description)
   {
      if (! $description) {
         return;
      }

      # ⮡ ↳
      $this->log("       ⮡ " . $description . '@\;');
   }
   public function separate ()
   {
      static $separatorLength;

      $separators = @$this->specifications['separators'] ?? [];

      $suite = @$separators['suite'] ?? null;
      $separator = @$separators['separator'] ?? null;
      $left = @$separators['left'] ?? null;
      $header = @$separators['header'] ?? null;

      $width = $this->Tests->width + 25;

      if ($suite) {
         // @ Text + `=`
         $suite = '@#Blue: ' . $suite . '  @;';
         $suite = str_pad($suite, $width + 3, '=', STR_PAD_BOTH);

         $this->log($suite . ' @\;');
      }

      if ($separator) {
         // @ `-`
         if ($separator !== true) {
            $separatorLength = strlen($separator);
            $separator = '@:i: ' . $separator . '  @;';

            // @ Text + `-`
            $separator = str_pad($separator, $width, '-', STR_PAD_BOTH);
         } else {
            // @ `-`
            $separator = str_repeat('-', $width - 7);
         }

         $this->log($separator . ' @\;');
      }

      if ($left) {
         $this->log("@\;       \033[3;90m" . $left . ":\033[0m @\;");
      }

      if ($header) {
         $header = '\\' .str_pad($header, $separatorLength ?? 0, ' ', STR_PAD_BOTH) . '/';
         $header = str_pad($header, $width - 7, ' ', STR_PAD_BOTH);

         $this->log($header . ' @\;');
      }
   }
   // @
   public function test (...$arguments)
   {
      #ob_start();

      try {
         $test = $this->specifications['test'];
         $test(...$arguments);

         $this->assertions[] = true;

         if ($this->Tests->autoResult) {
            $this->finished = microtime(true);
            $this->pass();
         }
      } catch (AssertionError $AssertionError) {
         $this->assertions[] = false;

         $message = $AssertionError->getMessage();

         if ($this->Tests->autoResult) {
            $this->finished = microtime(true);
            $this->fail($message);
         }
      }

      #$this->debugged ??= ob_get_clean();
      $this->finished ??= microtime(true);
   }

   public function fail (? string $message = null)
   {
      $this->Tests->failed++;

      $this->log(
         "\033[1;37;41m FAIL \033[0m "
         . $this->test
         . " -> \"\033[91m" . ($message ?? $this->specifications['except']()) . "\033[0m\"" . ':'
         . PHP_EOL
      );

      $this->describe($this->specifications['describe'] ?? null);

      $this->log($this->debugged);
   }
   public function pass ()
   {
      $this->Tests->passed++;

      $time = number_format(round($this->finished - $this->started, 5), 6);

      $test = str_pad($this->test, $this->Tests->width, '.', STR_PAD_RIGHT);

      $this->log(
         "\033[0;30;42m PASS \033[0m " .
         "\033[90m" . $test . "\033[0m" .
         "\033[1;35m +" . $time . "s\033[0m" . PHP_EOL
      );

      $this->describe($this->specifications['describe'] ?? null);
   }

   public function skip ()
   {
      $this->Tests->skipped++;
   }
}
