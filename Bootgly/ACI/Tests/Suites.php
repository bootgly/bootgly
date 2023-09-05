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


class Suites
{
   use LoggableEscaped;

   // * Data
   public array $suites;
   // * Meta
   // @ Status
   public int $failed;
   public int $passed;
   public int $skipped;
   // @ Stats
   public int $total;
   // @ Time
   public float $started;
   public float $finished;


   public function __construct ()
   {
      // * Meta
      // @ Status
      $this->failed = 0;
      $this->passed = 0;
      $this->skipped = 0;
      // @ Time
      $this->started = microtime(true);
   }

   public function summarize ()
   {
      // @ Result
      $failed = '@:error:' . $this->failed . ' failed @;';
      $skipped = '@:notice:' . $this->skipped . ' skipped @;';
      $passed = '@:success:' . $this->passed . ' passed @;';
      // @ Stats
      $cases = '@:info:' . Tests::$cases . ' @;';
      $total = $this->total . ' total';
      // @ Time
      $started = $this->started;
      $finished = $this->finished = microtime(true);
      // duration
      $duration = Benchmark::format($started, $finished);
      $duration = "@#Magenta:" . $duration . "s @;";

      $ran = '@#Black:' . 'Ran all test suites. @;';

      // TODO temp
      $this->log(<<<TESTS

      ==================================================
      Suites: {$failed}, {$skipped}, {$passed}, {$total}
      Test Cases (total): {$cases}
      Duration (total): {$duration}
      {$ran}
      ==================================================
      \n
      TESTS);
   }
}
