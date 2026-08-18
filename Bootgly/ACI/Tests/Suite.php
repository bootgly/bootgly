<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use const DIRECTORY_SEPARATOR;
use const PHP_EOL;
use const STR_PAD_BOTH;
use function count;
use function current;
use function is_array;
use function is_callable;
use function is_file;
use function key;
use function method_exists;
use function microtime;
use function next;
use function sprintf;
use function str_pad;
use function strlen;
use Closure;
use Exception;

use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Results;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Suite\Tester;
use Bootgly\ACI\Tests\Suites;
use Bootgly\API\Environment;


class Suite
{
   // * Data
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


   // * Config
   // auto
   public string|Closure $autoBoot;
   public bool|Closure $autoInstance;
   public bool $autoReport;
   public bool $autoSummarize;
   /**
    * Default Fixture propagated to Tests without their own Fixture.
    */
   public null|Fixture $Fixture;
   // exit
   public static bool $exitOnFailure = false;
   // output
   /** Mute per-case and per-suite human output — runner views render instead. */
   public static bool $quiet = false;
   /** Runner-view observer — notified with the owning Suite after each case record. */
   public static null|Closure $Observer = null;
   // pretesting
   /** @var array<object> */
   public array $testables;

   // * Data
   public string $name;
   /** @var array<string> */
   public array $tests;
   /** @var array<int,Test> */
   public array $Tests;
   public protected(set) Tester $Tester;
   /** @var array<string> */
   public array $artfacts;

   // * Metadata
   public int $failed;
   public int $passed;
   public int $skipped;
   /**
    * Ordered per-case records (status + per-assertion results) for runner views.
    * @var array<int,array{case:int,file:string,status:string,results:array<int,bool|null>,description:null|string,message:null|string,debug:null|string,elapsed:null|string}>
    */
   public array $records;
   // # Stats
   public int $assertions;
   // public static int $cases = 0;
   public static int $suite = 0;
   // # Time
   public float $started;
   public float $finished;
   public float $elapsed;
   // # Output
   public int $case = 0;
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
   * @param null|Fixture $Fixture
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
      null|Fixture $Fixture = null,
   )
   {
      // !
      // * Config
      // auto
      $this->autoBoot = $autoBoot ?? '';
      $this->autoInstance = $autoInstance ?? false;
      $this->autoReport = $autoReport ?? false;
      $this->autoSummarize = $autoSummarize ?? false;
      $this->Fixture = $Fixture;
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
      $this->records = [];
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

         // !
         $file = "{$dir}{$test}.Test.php";
         // ? Absent test case
         // Resolved here, not through a silenced include: silencing the include
         // also hides every diagnostic the test case emits while loading, which
         // turns a broken case into a silent no-op.
         if ( is_file($file) === false ) {
            // ? Private test case (`_*.Test.php` is not versioned)
            if ($test[0] === '_') {
               // ! A placeholder, not a `continue`: dropping the entry made the
               //   case vanish from every counter AND handed its name to the
               //   next case that ran, which then reported a pass under a file
               //   that does not exist. It is listed, so it is skipped.
               $Placeholder = new Test(
                  test: static fn (): bool => true,
                  skip: true
               );
               $Placeholder->index(case: $case_index, file: $test);

               $this->Tests[] = $Placeholder;

               continue;
            }

            throw new Exception("Test case not found: \n {$dir}{$test}");
         }

         // @
         $Test = include $file;
         // ?
         if ($Test instanceof Test === false) {
            throw new Exception("Test case must return a Test instance: \n {$dir}{$test}");
         }

         // * Metadata (Test Case)
         // target
         $this->case = $case_index;
         $Test->index(
            case: $case_index,
            last: $this->assertions === $case_index ? true : null,
            file: $test
         );

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
            // ! The case being run — skip() stamps its records with it, the same
            //   channel the WPI runners already write before each case. Without it
            //   a skipped case inherits whatever index autoboot() left behind.
            $this->case = $Test->case ?? $this->case;
            // ! The name travels with the case. The array pointer cannot be the
            //   cursor here: autoboot() walks past every non-target case without
            //   advancing it, so a targeted run read the FIRST entry's name for
            //   whatever case it actually ran.
            $file = $Test->file ?? (current($this->tests) ?: '');
            // @ Skip test
            // ? Private files — `_*.Test.php` is not versioned, so it is absent
            //   on any checkout but its author's (skip already set by autoboot)
            if (
               $file !== ''
               && $file[0] === '_'
               && ($Test->skip === true || Environment::match(Environment::CI_CD) === true)
            ) {
               $this->skip('(@private)', $file);

               continue;
            }
            // ? Skip
            if ($Test->skip === true && $Test->ignore === false) {
               $this->skip(file: $file);

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
      if (Results::$enabled || self::$quiet) {
         return;
      }

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
         $this->Logger->log(debug: "@#white:$header @;@\\;");
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

            $result = [
               ...$result, ...self::list($value, $newPrefix)
            ];
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
    * @param null|Test $Test
    *
    * @return Tester|null
    */
   public function test (null|Test $Test): Tester|null
   {
      if ($Test === null) {
         $this->skipped++;

         next($this->tests);

         return null;
      }

      $Test->Fixture ??= $this->Fixture;

      $this->Tester = new Tester($this, $Test);

      if (key($this->tests) < $this->assertions) {
         next($this->tests);
      }

      return $this->Tester;
   }
   /**
    * Skip a Test Case.
    * 
    * @param null|string $info
    * @param null|string $file The case's file name. Falls back to the internal
    *        array pointer for runners that walk the list themselves (the WPI
    *        SAPI runner, the Mail E2E autoboot), which keep it in sync.
    * @return void
    */
   public function skip (null|string $info = null, null|string $file = null): void
   {
      $file ??= current($this->tests);

      $this->skipped++;

      next($this->tests);

      $case_index = sprintf('%03d', $this->case);

      // @ Record the case for runner views
      $this->records[] = [
         'case' => $this->case,
         'file' => $file ?: '',
         'status' => 'skipped',
         'results' => [],
         'description' => null,
         'message' => $info,
         'debug' => null,
         'elapsed' => null,
      ];
      if (self::$Observer !== null) {
         (self::$Observer)($this);
      }

      if (Results::$enabled === false && self::$quiet === false) {
         // @ Set additional info
         if ($info) {
            $info = "\033[1;35m $info \033[0m";
         }

         $this->Logger->log(debug: 
            "\e[30m\e[47m $case_index \e[0m\e[0;30;43m SKIP \e @; \e[90m$file\e[0m$info" . PHP_EOL
         );
      }

      // @ Record result for AI agent output
      Results::record(
         suite: $this->name,
         case: $this->case,
         file: $file ?: '',
         status: 'skipped'
      );
   }

   // # Summary
   /**
    * Summarize the Test Suite.
    *
    * @return void
    */
   public function summarize (): void
   {
      // # Time
      $started = $this->started;
      $finished = $this->finished = microtime(true);

      if (Results::$enabled) {
         // @ Feed incremental stats to Results so the JSON reflects what
         // actually ran (Suites::summarize() may never run if a case fails
         // and exitOnFailure triggers exit(1) before reaching the outer
         // iterator's summarize()).
         Results::$suitesTotal++;
         if ($this->failed > 0) {
            Results::$suitesFailed++;
         } else if ($this->passed === 0 && $this->skipped > 0) {
            Results::$suitesSkipped++;
         } else {
            Results::$suitesPassed++;
         }
         Results::$assertions += $this->assertions;
         Results::$durationMs += ($finished - $started) * 1000;
         return;
      }

      // ? Quiet — runner views render their own per-suite summary
      if (self::$quiet) {
         return;
      }

      // # Result
      $failed = "@:error:{$this->failed} failed @;";
      $skipped = "@:notice:{$this->skipped} skipped @;";
      $passed = "@:success:{$this->passed} passed @;";
      // # Stats
      $assertions = "@:info:{$this->assertions} @;";
      // # Time
      $started = $this->started;
      $finished = $this->finished = microtime(true);
      // duration
      $duration = Benchmark::format($started, $finished);
      $duration = "@#Magenta:{$duration}s @;";

      $ran = "@#Black:Ran all tests cases. @;";

      // TODO temp
      $this->Logger->log(debug: <<<TESTS

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
