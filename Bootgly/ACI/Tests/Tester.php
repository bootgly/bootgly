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


use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Tests;
use Bootgly\API\Environment;


class Tester extends Tests
{
   use LoggableEscaped;


   // * Config
   // ...inherited from Tests

   // * Data
   // ...inherited from Tests
   /** @var array<string> */
   public array $artfacts;

   // * Metadata
   // ...inherited from Tests


   /**
    * Tester constructor.
    * 
    * @param array<string,mixed> $specifications
    */
   public function __construct (array &$specifications)
   {
      // ✓
      // * Config
      // auto
      $this->autoBoot = $specifications['autoBoot'] ?? '';
      $this->autoInstance = $specifications['autoInstance'] ?? false;
      $this->autoResult = $specifications['autoResult'] ?? false;
      $this->autoSummarize = $specifications['autoSummarize'] ?? false;
      // exit
      self::$exitOnFailure = $specifications['exitOnFailure'] ?? self::$exitOnFailure;
      // pretesting
      $this->testables = $specifications['testables'] ?? [];

      // * Data
      $this->tests = self::list($specifications['tests'] ?? $specifications);
      $this->specifications = [];

      // * Metadata
      // @ Status
      $this->failed = 0;
      $this->passed = 0;
      $this->skipped = 0;
      // @ Stats
      $this->assertions = 0;
      $this->total = count($this->tests);
      Tests::$cases += $this->total;
      // @ Time
      $this->started = microtime(true);
      // @ Output
      // width
      $width = 0;
      foreach ($this->tests as $file) {
         $length = strlen($file);
         if ($length > $width) {
            $width = $length;
         }
      }
      Tests::$width = $width + 1;


      $this->log('@\;');

      // @ Automate
      if ($this->autoBoot) {
         $this->autoboot($this->autoBoot, $specifications);
      }
      if ($this->autoInstance) {
         $this->autoinstance($this->autoInstance);
      }
      if ($this->autoSummarize) {
         $this->summarize();
      }
      // @ Pretest
      $testables = $this->testables;
      foreach ($testables as $testable) {
         method_exists($testable, 'pretest') ? $testable::pretest(): false;
      }
   }

   /**
    * Autoboot test suite.
    * 
    * @param string $boot
    * @param array<string,array<string,mixed>> $specifications
    */
   public function autoboot (string $boot, array $specifications): void
   {
      $this->separate(header: $specifications['suiteName'] ?? ''); // Test Suite Specs

      $dir = $boot . DIRECTORY_SEPARATOR;

      // * Config (Test Suite)
      $testCaseTarget = (int) ($specifications['index'] ?? 0);
      // @
      foreach ($this->tests as $index => $test) {
         $specifications = @include($dir . $test . '.test.php'); // Test Case Specs

         if ($specifications === false) {
            $specifications = null;
         }

         // * Metadata (Test Case)
         if ($this->total === $index + 1) {
            $specifications['last'] = true;
         }
         if ($testCaseTarget > 0 && ($index + 1) !== $testCaseTarget) {
            $specifications['skip'] = true;
         }

         $this->specifications[] = $specifications;
      }
   }
   /**
    * Autoinstance test suite.
    * 
    * @param bool|callable $instance
    */
   public function autoinstance (bool|callable $instance): void
   {
      if ($instance === true) {
         foreach ($this->specifications as $specifications) {
            $file = current($this->tests);

            // @ Skip test
            // if private (_(.*).test.php) && script is running in a CI/CD enviroment
            if ($file[0] === '_' && Environment::match(Environment::CI_CD) === true) {
               $this->skip('(@private)');
               continue;
            }

            // @ Test
            $Test = $this->test($specifications);
            if ($Test !== false) {
               $Test->test();
            }
         }
      }

      // @ Check if is callable
      if ( is_callable($instance) ) {
         // @ Pass artfacts returned by autoboot
         $instance(...$this->artfacts);
      }
   }
   /**
    * Get the next test case.
    * 
    * @param array<string,mixed> $specifications
    *
    * @return Test|false
    */
   public function test (? array &$specifications): Test|false
   {
      if ( empty($specifications) ) {
         $this->skipped++;
         next($this->tests);
         return false;
      }

      $Test = new Test($this, $specifications);

      if (key($this->tests) < $this->total) {
         next($this->tests);
      }

      return $Test;
   }
   /**
    * Add a separator to the test suite.
    * 
    * @param string $header
    *
    * @return void
    */
   public function separate (string $header): void
   {
      if ($header) {
         // @ Add blue color to header text
         $header = ' @#Cyan:(' . self::$suite . ') @;' . ' @#Blue: ' . $header . '  @;';

         // @ Pad string with `=`
         $length = Tests::$width + 43;

         $header = str_pad(
            string: $header,
            length: $length,
            pad_string: '=',
            pad_type: STR_PAD_BOTH
         );

         // @ Output header separator
         $this->log('@#white:' . $header . ' @;@\;');
      }
   }
   /**
    * Skip a test case.
    * 
    * @param null|string $info 
    * @return void 
    */
   public function skip (? string $info = null): void
   {
      $file = current($this->tests);

      Tests::$case++;
      $this->skipped++;

      next($this->tests);

      $case = sprintf('%03d', Tests::$case);
      // @ Set additional info
      if ($info) {
         $info = "\033[1;35m $info \033[0m";
      }

      $this->log(
         "\033[30m\033[47m " . $case . " \033[0m" .
         "\033[0;30;43m SKIP \033 @; " .
         "\033[90m" . $file . "\033[0m" .
         $info . PHP_EOL
      );
   }

   /**
    * Summarize the test cases.
    *
    * @return void
    */
   public function summarize (): void
   {
      // @ Result
      $failed = '@:error:' . $this->failed . ' failed @;';
      $skipped = '@:notice:' . $this->skipped . ' skipped @;';
      $passed = '@:success:' . $this->passed . ' passed @;';
      // @ Stats
      $total = '@#white:' . $this->total . ' total @;';
      // @ Time
      $started = $this->started;
      $finished = $this->finished = microtime(true);
      // assertions
      $assertions = '@:info:' . $this->assertions . ' @;';
      // duration
      $duration = Benchmark::format($started, $finished);
      $duration = "@#Magenta:" . $duration . "s @;";

      $ran = '@#Black:' . 'Ran all tests. @;';

      // TODO temp
      $this->log(<<<TESTS

      @#white:-------------------------------------------------- @;
      @#white:Tests: @; {$failed}, {$skipped}, {$passed}, {$total}
      @#white:# of Assertions: @; {$assertions}
      @#white:Duration: @; {$duration}
      {$ran}
      @#white:-------------------------------------------------- @;
      \n
      TESTS);
   }
}
