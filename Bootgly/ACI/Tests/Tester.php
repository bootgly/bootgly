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
use Bootgly\API\Environment;
use Bootgly\ACI\Tests;


class Tester extends Tests
{
   use LoggableEscaped;


   // * Config
   // ...inherited from Tests

   // * Data
   // ...inherited from Tests

   // * Meta
   public array $artfacts;
   // ...inherited from Tests


   public function __construct (array &$tests)
   {
      // ✓
      // * Config
      // auto
      $this->autoBoot = $tests['autoBoot'] ?? '';
      $this->autoInstance = $tests['autoInstance'] ?? false;
      $this->autoResult = $tests['autoResult'] ?? false;
      $this->autoSummarize = $tests['autoSummarize'] ?? false;
      // exit
      self::$exitOnFailure = $tests['exitOnFailure'] ?? self::$exitOnFailure;

      // * Data
      $this->tests = $tests['files'] ?? $tests;
      $this->specifications = [];

      // * Meta
      $this->failed = 0;
      $this->passed = 0;
      $this->skipped = 0;
      // @ Stats
      $this->total = count($this->tests);
      // @ Time
      $this->started = microtime(true);
      // @ Screen? Output?
      // width
      $width = 0;
      foreach ($this->tests as $file) {
         $length = strlen($file);
         if ($length > $width) {
            $width = $length;
         }
      }
      $this->width = $width;


      $this->log('@\;');

      // @ Automate
      if ($this->autoBoot) {
         $this->autoboot($this->autoBoot, $tests);
      }
      if ($this->autoInstance) {
         $this->autoinstance($this->autoInstance);
      }
      if ($this->autoSummarize) {
         $this->summarize();
      }
   }

   public function autoboot (string $boot, array $tests)
   {
      $this->separate(header: $tests['suiteName'] ?? '');

      $dir = $boot . DIRECTORY_SEPARATOR;

      foreach ($this->tests as $test) {
         $specifications = @include $dir . $test . '.test.php';

         if ($specifications === false) {
            $specifications = null;
         }

         $this->specifications[] = $specifications;
      }
   }
   public function autoinstance (bool|callable $instance)
   {
      if ($instance === true) {
         foreach ($this->specifications as $specification) {
            $file = current($this->tests);

            // @ Skip test if private (_(.*).test.php) && script is running in a CI/CD enviroment
            // TODO abstract all CI/CD Environment into one
            $CI_CD = (
               Environment::get('GITHUB_ACTIONS')
               || Environment::get('TRAVIS')
               || Environment::get('CIRCLECI')
               || Environment::get('GITLAB_CI')
            );
            if ($file[0] === '_' && $CI_CD) {
               $this->skip('(@private)');
               continue;
            }

            $Test = $this->test($specification);
   
            if ($Test instanceof Test) {
               $Test->separate();
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

   public function test (? array &$specifications) : Test|false
   {
      if ( $specifications === null || empty($specifications) ) {
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

   public function separate (string $header)
   {
      if ($header) {
         // @ Add blue color to header text
         $header = '@#Blue: ' . $header . '  @;';

         // @ Pad string with `=`
         $length = $this->width + 28;
         $header = str_pad(
            string: $header,
            length: $length,
            pad_string: '=',
            pad_type: STR_PAD_BOTH
         );

         // @ Output header separator
         $this->log($header . ' @\;');
      }
   }

   public function skip (? string $info = null)
   {
      $file = current($this->tests);

      $this->skipped++;

      next($this->tests);

      // @ Set additional info
      if ($info) {
         $info = "\033[1;35m $info \033[0m";
      }

      $this->log(
         "\033[0;30;43m SKIP \033 @; " .
         "\033[90m" . $file . "\033[0m" .
         $info . PHP_EOL
      );
   }

   public function summarize ()
   {
      $failed = $this->failed;
      $passed = $this->passed;
      $skipped = $this->skipped;
      // @ Stats
      $total = $this->total;
      // @ Time
      $started = $this->started;
      $finished = $this->finished = microtime(true);

      // @ Benchmark Tests time
      // TODO use Benchmark class?
      $duration = number_format(round($finished - $started, 5), 6);

      $this->log(<<<TESTS
      
      Tests: @:e:{$failed} failed @;, @:n:{$skipped} skipped @;, @:s:{$passed} passed @;, {$total} total
      Duration: \033[1;35m{$duration}s \033[0m
      \033[90mRan all tests.\033[0m
      \n
      TESTS);
   }
}
