<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\_\Tester;


use Bootgly\Logs;


class Tests implements \Bootgly\Tests
{
   // * Meta
   public int $failed;
   public int $passed;
   public int $skipped;
   // @ Stats
   public int $total;
   // @ Time
   public float $started;
   public float $finished;


   public function __construct (array $tests)
   {
      // * Meta
      $this->failed = 0;
      $this->passed = 0;
      $this->skipped = 0;
      // @ Stats
      $this->total = count($tests);
      // @ Time
      $this->started = microtime(true);
   }

   public function summarize (Logs $Instance)
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
      $finished = microtime(true);
      $duration = number_format(round($finished - $started, 5), 6);

      $Instance->log(<<<TESTS

      Tests: @:e: {$failed} failed @;, @:n:{$skipped} skipped @;, @:s:{$passed} passed @;, {$total} total
      Time: {$duration}s
      \033[90mRan all tests.\033[0m

      TESTS);
   }
}
