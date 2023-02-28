<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\_\Tester\Tests;


use Bootgly\CLI\_\Logger\Logging;
use Bootgly\CLI\_\Tester\Tests;
use Bootgly\Logs;


class Test
{
   use Logging;


   public Tests $Tests;

   // * Data
   public array $specifications;
   // * Meta
   public $test;
   public bool $success;
   public false|string $debugged;
   // @ Time
   public float $started;
   public float $finished;


   public function __construct (Tests $Tests, array $specifications)
   {
      $this->Tests = $Tests;

      // * Data
      $this->specifications = $specifications;
      // * Meta
      $this->test = current($this->Tests->tests); // @ file
      $this->success = false;
      $this->debugged = false;
      // @ Time
      $this->started = microtime(true);
   }

   public function assert (...$arguments)
   {
      ob_start();
      $this->success = $this->specifications['assert'](...$arguments);
      $this->debugged = ob_get_clean();
      $this->finished = microtime(true);
   }

   public function separate ()
   {
      static $length;

      $separator = @$this->specifications['separator'] ?? null;
      $header = @$this->specifications['header'] ?? null;

      if ($separator) {
         if ($separator !== true) {
            $length = strlen($separator);
            $separator = '@:i: ' . $separator . '  @;';
         }

         $this->log('-----------------' . $separator . '----------------- @\;');
      }

      if ($header) {
         $header = str_pad($header, $length ?? 0, ' ', STR_PAD_BOTH);

         $this->log('                 \\' . $header . '/                @\;');
      }
   }

   public function skip ()
   {
      $this->Tests->skipped++;
   }
   public function pass ()
   {
      $this->Tests->passed++;

      $time = number_format(round($this->finished - $this->started, 5), 6);

      $this->log(
         "\033[1;37;42m PASS \033[0m " 
         . "\033[90m" . $this->test . "\033[0m" 
         . "\033[1;35m +" . $time . "s\033[0m" . PHP_EOL
      );
   }
   public function fail ()
   {
      $this->Tests->failed++;

      $this->log(
         "\033[1;37;41m FAIL \033[0m " 
         . $this->test
         . " -> \"\033[91m" . $this->specifications['except']() . "\033[0m\"" . ':'
         . $this->debugged . PHP_EOL
      );
   }
}
