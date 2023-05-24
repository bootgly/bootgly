<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Tester;


use Bootgly\Tests;
use Bootgly\Logger\Escaped\Logging;
use Bootgly\Tester\UnitTests\Test;


class UnitTests implements Tests
{
   use Logging;


   // * Config
   public string $autoboot;
   public bool $autoinstance;
   public bool $autoresult;
   public bool $autosummarize;
   public bool $exit;

   // * Data
   public array $tests; // TODO rename to files?
   public array $specifications;

   // * Meta
   public int $failed;
   public int $passed;
   public int $skipped;
   // @ Stats
   public int $total;
   // @ Time
   public float $started;
   public float $finished;
   // @ Screen
   public int $width;


   public function __construct (array &$tests)
   {
      // * Config
      $this->autoboot = $tests['autoboot'] ?? '';
      $this->autoinstance = $tests['autoinstance'] ?? false;
      $this->autoresult = $tests['autoresult'] ?? false;
      $this->autosummarize = $tests['autosummarize'] ?? false;
      $this->exit = $tests['exit'] ?? false;

      // * Data
      $this->tests = $tests['files'];
      $this->specifications = [];

      // * Meta
      $this->failed = 0;
      $this->passed = 0;
      $this->skipped = 0;
      // @ Stats
      $this->total = count($this->tests);
      // @ Time
      $this->started = microtime(true);
      // @ Screen
      // width
      $width = 0;
      foreach ($tests['files'] as $file) {
         $length = strlen($file);
         if ($length > $width) {
            $width = $length;
         }
      }
      $this->width = $width;


      $this->log('@\;');

      // @ Automate
      if ($this->autoboot) {
         $dir = $this->autoboot . DIRECTORY_SEPARATOR;

         foreach ($this->tests as $test) {
            $specifications = require $dir . $test . '.test.php';
            $this->specifications[] = $specifications;
         }
      }
      if ($this->autoinstance) {
         foreach ($this->specifications as $specification) {
            $Test = $this->test($specification);
   
            $Test->separate();
   
            $Test->test();
         }
      }
      if ($this->autosummarize) {
         $this->summarize();
      }

      if ($this->exit && $this->failed > 0) {
         exit(1);
      }
   }

   public function test (? array &$specifications) : Test|false
   {
      if ($specifications === null) {
         $this->skipped++;
         return false;
      }

      $Test = new Test($this, $specifications);

      if (key($this->tests) < $this->total) {
         next($this->tests);
      }

      return $Test;
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
      $duration = number_format(round($finished - $started, 5), 6);

      $this->log(<<<TESTS
      
      Tests: @:e:{$failed} failed @;, @:n:{$skipped} skipped @;, @:s:{$passed} passed @;, {$total} total
      Duration: \033[1;35m{$duration}s \033[0m
      \033[90mRan all tests.\033[0m
      \n
      TESTS);
   }
}
