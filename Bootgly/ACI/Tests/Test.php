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


use AssertionError;
#use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests;
use Bootgly\ACI\Logs\LoggableEscaped;


class Test // extends Assertions
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
   public string $elapsed;


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
         'success' => array_reduce(
            $this->assertions,
            function ($accumulator, $assertion) {
               return $accumulator && $assertion;
            },
            true
         ),
         default => null
      };
   }

   public function describe (? string $description)
   {
      if (! $description) {
         return;
      }

      $description = "       ↪️ " . $description . '@\;';

      # ⮡ ↳➡️↪↪️
      $this->log($description);
   }
   public function separate ()
   {
      static $separatorLength;

      $separator = $this->specifications['separator.line']   ?? null;
      $left      = $this->specifications['separator.left']   ?? null;
      $header    = $this->specifications['separator.header'] ?? null;

      $width = $this->Tests->width + 25;

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

         $this->log('@#white:' . $header . ' @;@\;');
      }
   }
   // @
   public function test (...$arguments)
   {
      #ob_start();

      try {
         $test = $this->specifications['test'];
         $result = $test(...$arguments);

         $this->assertions[] = $result ?? true;

         if ($this->Tests->autoResult) {
            $this->end();
            $this->pass();
         }
      } catch (AssertionError $AssertionError) {
         $this->assertions[] = false;

         $message = $AssertionError->getMessage();

         if ($this->Tests->autoResult) {
            $this->end();
            $this->fail($message);
         }
      }

      #$this->debugged ??= ob_get_clean();

      $this->end();
   }

   private function end ()
   {
      $this->finished ??= microtime(true);

      $this->elapsed ??= Benchmark::format($this->started, $this->finished);
   }
   public function fail (? string $message = null)
   {
      $this->Tests->failed++;

      $test = str_pad($this->test . ':', $this->Tests->width, ' ', STR_PAD_RIGHT);
      $elapsed = $this->elapsed;
      $help = $message ?? $this->specifications['except']();

      // @ output
      $this->log(
         "\033[0;30;41m FAIL \033[0m "
         . $test
         . "\033[1;35m +" . $elapsed . "s\033[0m" . PHP_EOL
         . "       ↪️ \"\033[91m" . $help . "\033[0m\""
         . PHP_EOL
      );
      $this->describe($this->specifications['describe'] ?? null);
      $this->log($this->debugged);

      // @ exit
      if (Tests::$exitOnFailure) {
         $this->Tests->summarize();
         exit(1);
      }
   }
   public function pass ()
   {
      $this->Tests->passed++;

      $test = str_pad($this->test, $this->Tests->width, '.', STR_PAD_RIGHT);
      $elapsed = $this->elapsed;

      // @ output
      $this->log(
         "\033[0;30;42m PASS \033[0m " .
         "\033[90m" . $test . "\033[0m" .
         "\033[1;35m +" . $elapsed . "s\033[0m" . PHP_EOL
      );
      $this->describe($this->specifications['describe'] ?? null);
   }
}
