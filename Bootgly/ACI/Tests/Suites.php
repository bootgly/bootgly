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


use function count;

use Bootgly\ACI\Benchmark;
use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Tests\Assertions;


class Suites
{
   use LoggableEscaped;

   // * Config
   /**
    * The Test Suite directories.
    *
    * @var array<string>
    */
   public array $directories;

   // * Metadata
   // # Status
   public int $failed;
   public int $passed;
   public int $skipped;
   // # Stats
   public static int $count = 0;
   public int $total;
   // # Time
   public float $started;
   public float $finished;


   /**
    * Suites constructor.
    *
    * @param array<string> $directories The Test Suite directories.
    */
   public function __construct (array $directories)
   {
      // * Config
      $this->directories = $directories;

      // * Metadata
      // # Status
      $this->failed = 0;
      $this->passed = 0;
      $this->skipped = 0;
      // # Stats
      // self::$count
      $this->total = count($this->directories);
      // # Time
      $this->started = microtime(true);
      // $finished
   }

   public function iterate (
      int $suite,
      int $case,
      callable $iterator
   ): void
   {
      foreach ($this->directories as $index => $dir) {
         self::$count++;

         if ($suite > 0 && $suite !== $index + 1) {
            $this->skipped++;
            continue;
         }

         /** @var null|true|Suite $Suite */
         $Suite = $iterator($dir, $case);

         $this->passed++;
      }
   }

   /**
    * Summarize Test Suites.
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
      $cases = '@:info:' . Assertions::$count . ' @;';
      $total = '@:info:' . $this->total . ' @;';
      // # Time
      $started = $this->started;
      $finished = $this->finished = microtime(true);
      // duration
      $duration = Benchmark::format($started, $finished);
      $duration = "@#Magenta:" . $duration . "s @;";

      $ran = '@#Black:' . 'Ran all test suites. @;';

      // TODO temp
      $this->log(<<<TESTS

      @#white:============================================================ @;
      @#white:Test Suites: @; {$failed}, {$skipped}, {$passed}
      @#white:# of Test Suites: @; {$total}
      @#white:# of Test Cases: @; {$cases}
      @#white:Total Duration: @; {$duration}
      {$ran}
      @#white:============================================================ @;
      \n
      TESTS);
   }
}
