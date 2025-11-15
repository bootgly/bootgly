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


use function is_array;
use Closure;
use Exception;

use Bootgly\ACI\Benchmark;
use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Suites;
use Bootgly\API\Environment;


class Suite
{
   use LoggableEscaped;


   // * Config
   // auto
   public string|Closure $autoBoot;
   public bool|Closure $autoInstance;
   public bool $autoReport;
   public bool $autoSummarize;
   // exit
   public static bool $exitOnFailure = false;
   // pretesting
   /** @var array<object> */
   public array $testables;

   // * Data
   public string $name;
   /** @var array<string> */
   public array $tests;
   /** @var array<int,array<string,mixed>> */
   public array $Tests;
   public protected(set) Test $Test;
   /** @var array<string> */
   public array $artfacts;

   // * Metadata
   public int $failed;
   public int $passed;
   public int $skipped;
   // # Stats
   public int $assertions;
   // public static int $cases = 0;
   public static int $suite = 0;
   // # Time
   public float $started;
   public float $finished;
   public float $elapsed;
   // # Output
   public int $case;
   public int $target;
   public static int $width = 0;


   /**
    * Construct Test Suite.
    * 
    * @param null|string|Closure $autoBoot
    * @param null|bool $autoInstance
    * @param null|bool $autoReport
    * @param null|bool $autoSummarize
    * @param null|bool $exitOnFailure
    * @param null|array<object> $testables
    * @param null|string $suiteName
    * @param array<int|string,string|array<string>> $tests
    */
   public function __construct (
      // * Data (required)
      array $tests,
      // * Config (optional)
      null|string|Closure $autoBoot = null,
      null|bool $autoInstance = null,
      null|bool $autoReport = null,
      null|bool $autoSummarize = null,
      null|bool $exitOnFailure = null,
      null|array $testables = null,
      null|string $suiteName = null,
   )
   {
      // !
      // * Config
      // auto
      $this->autoBoot = $autoBoot ?? '';
      $this->autoInstance = $autoInstance ?? false;
      $this->autoReport = $autoReport ?? false;
      $this->autoSummarize = $autoSummarize ?? false;
      // exit
      self::$exitOnFailure = $exitOnFailure ?? self::$exitOnFailure;
      // pretesting
      $this->testables = $testables ?? [];

      // * Data
      $this->name = $suiteName ?? '';
      $this->tests = self::list($tests);
      $this->Tests = [];

      // * Metadata
      // # Status
      $this->failed = 0;
      $this->passed = 0;
      $this->skipped = 0;
      // # Stats
      $this->assertions = count($this->tests);
      Assertions::$count += $this->assertions;
      // # Time
      $this->started = microtime(true);
      // # Output
      // width
      $width = 0;
      foreach ($this->tests as $file) {
         $length = strlen($file);
         if ($length > $width) {
            $width = $length;
         }
      }
      self::$width = $width + 1;


      // @
      // # Pretest
      $testables = $this->testables;
      foreach ($testables as $testable) {
         method_exists($testable, 'pretest')
            ? $testable::pretest()
            : false;
      }
   }

   // # Test Suite
   /**
    * Autoboot Test Suite.
    * 
    * @param string $pathbase
    */
   public function autoboot (string $pathbase): void
   {
      $this->separate(header: $this->name); // Test Suite Specs

      // @@
      // !
      $case_target = $this->target ?? 0;
      $dir = $pathbase . DIRECTORY_SEPARATOR;
      foreach ($this->tests as $index => $test) {
         // !
         $case_index = $index + 1;
         // ?
         if ($case_target > 0 && $case_index !== $case_target) {
            continue;
         }

         // @
         /** @var array<string,mixed>|false $Test */
         $Test = @include "{$dir}{$test}.test.php";
         // ?
         if ($test[0] === '_' && $Test === false) {
            continue;
         }
         else if ($test[0] !== '_' && $Test === false) {
            throw new Exception("Test case not found: \n {$dir}{$test}");
         }
         else if (is_array($Test) === false) {
            throw new Exception("Test case must return an array: \n {$dir}{$test}");
         }

         // * Metadata (Test Case)
         // target
         $this->case = $case_index;
         $Test['case'] = $case_index;
         // last
         if ($this->assertions === $case_index) {
            $Test['last'] = true;
         }

         $this->Tests[] = $Test;
      }
   }
   /**
    * Autoinstance Test Suite.
    * 
    * @param bool|callable $instance
    */
   public function autoinstance (bool|callable $instance): void
   {
      if ($instance === true) {
         foreach ($this->Tests as $Test) {
            // !
            $file = current($this->tests);
            // @ Ignore
            if ($file === false) {
               break;
            }
            // @ Skip test
            // ? Private files
            if ($file[0] === '_' && Environment::match(Environment::CI_CD) === true) {
               $this->skip('(@private)');

               continue;
            }
            // ? Skip
            $skip = $Test['skip'] ?? false;
            $ignore = $Test['ignore'] ?? false;
            if ($skip === true && $ignore === false) {
               $this->skip();

               continue;
            }

            // @
            $this->test($Test)?->test();
         }
      }

      // @ Check if is callable
      if ( is_callable($instance) ) {
         // @ Pass artfacts returned by autoboot
         $instance(...$this->artfacts);
      }
   }
   /**
    * Add a separator to the Test Suite.
    * 
    * @param string $header
    *
    * @return void
    */
   public function separate (string $header): void
   {
      if ($header) {
         // @ Add blue color to header text
         $header = ' @#Cyan:(' . Suites::$count . ') @;' . ' @#Blue: ' . $header . '  @;';

         // @ Pad string with `=`
         $length = self::$width + 44;

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

   // # Test Case(s)
   /**
    * List Test Cases.
    * 
    * @param array<string|array<string>> $tests
    * @param string $prefix
    * 
    * @return array<string>
    */
   public static function list (array $tests, string $prefix = ''): array
   {
      $result = [];

      foreach ($tests as $key => $value) {
         if ( is_array($value) ) {
            $newPrefix = "{$prefix}{$key}";

            $result = array_merge(
               $result, self::list($value, $newPrefix)
            );
         }
         else {
            $result[] = "{$prefix}{$value}";
         }
      }

      return $result;
   }
   /**
    * Test the current Test Case.
    * 
    * @param null|array<string,mixed> $Test
    *
    * @return Test|null
    */
   public function test (null|array &$Test): Test|null
   {
      if ( empty($Test) ) {
         $this->skipped++;

         next($this->tests);

         return null;
      }

      $this->Test = new Test($this, $Test);

      if (key($this->tests) < $this->assertions) {
         next($this->tests);
      }

      return $this->Test;
   }
   /**
    * Skip a Test Case.
    * 
    * @param null|string $info 
    * @return void 
    */
   public function skip (null|string $info = null): void
   {
      $file = current($this->tests);

      $this->skipped++;

      next($this->tests);

      $case_index = sprintf('%03d', $this->case);
      // @ Set additional info
      if ($info) {
         $info = "\033[1;35m $info \033[0m";
      }

      $this->log(
         "\033[30m\033[47m " . $case_index . " \033[0m" .
         "\033[0;30;43m SKIP \033 @; " .
         "\033[90m" . $file . "\033[0m" .
         $info . PHP_EOL
      );
   }
   /**
    * Summarize the Test Suite.
    *
    * @return void
    */
   public function summarize (): void
   {
      // # Result
      $failed = '@:error:' . $this->failed . ' failed @;';
      $skipped = '@:notice:' . $this->skipped . ' skipped @;';
      $passed = '@:success:' . $this->passed . ' passed @;';
      // # Stats
      $assertions = '@:info:' . $this->assertions . ' @;';
      // # Time
      $started = $this->started;
      $finished = $this->finished = microtime(true);
      // duration
      $duration = Benchmark::format($started, $finished);
      $duration = "@#Magenta:" . $duration . "s @;";

      $ran = '@#Black:' . 'Ran all tests cases. @;';

      // TODO temp
      $this->log(<<<TESTS

      @#white:------------------------------------------------------------ @;
      @#white:Test Cases: @; {$failed}, {$skipped}, {$passed}
      @#white:# of Assertions: @; {$assertions}
      @#white:Duration: @; {$duration}
      {$ran}
      @#white:------------------------------------------------------------ @;
      \n
      TESTS);
   }
}
