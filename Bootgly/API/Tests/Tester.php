<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Tests;


use Closure;
use Bootgly\API\Environment;
use Bootgly\API\Tests;


class Tester extends Tests
{
   // * Config
   public bool|string|Closure $autoBoot;
   public mixed $autoInstance;
   public bool $autoResult;
   public bool $autoSummarize;
   public bool $exitOnFailure;

   // * Data
   // ...extended

   // * Meta
   public array $artfacts;
   // ...extended


   public function __construct (array &$tests)
   {
      // ✓
      // * Config
      $this->autoBoot = $tests['autoBoot'] ?? '';
      $this->autoInstance = $tests['autoInstance'] ?? false;
      $this->autoResult = $tests['autoResult'] ?? false;
      $this->autoSummarize = $tests['autoSummarize'] ?? false;
      $this->exitOnFailure = $tests['exitOnFailure'] ?? false;

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
      // @ Screen
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

      if ($this->failed > 0 && $this->exitOnFailure) {
         exit(1);
      }
   }

   public function autoboot (bool|string|Closure $boot, array $tests)
   {
      $this->separate(header: $tests['suiteName'] ?? '');

      if ( is_string($boot) ) {
         $dir = $boot . DIRECTORY_SEPARATOR;

         foreach ($this->tests as $test) {
            $specifications = @include $dir . $test . '.test.php';
   
            if ($specifications === false) {
               $specifications = null;
            }
   
            $this->specifications[] = $specifications;
         }
      } else {
         $this->artfacts = $boot();
      }
   }
   public function autoinstance (bool|callable $instance)
   {
      if ($instance === true) {
         foreach ($this->specifications as $specification) {
            $file = current($this->tests);

            // @ Skip test if private (_(.*).test.php) and script is running in a CI/CD enviroment
            // TODO abstract all CI/CD Environment into one
            $CI_CD = (
               Environment::get('GITHUB_ACTIONS')
               || Environment::get('TRAVIS')
               || Environment::get('CIRCLECI')
               || Environment::get('GITLAB_CI')
            );
            if ($file[0] === '_' && $CI_CD) {
               $this->skip('(private test)');
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
         // @ Text + `=`
         $header = '@#Blue: ' . $header . '  @;';
         $header = str_pad($header, $this->width + 28, '=', STR_PAD_BOTH);

         $this->log($header . ' @\;');
      }
   }

   public function skip (string $info)
   {
      $file = current($this->tests);

      $this->skipped++;

      next($this->tests);

      $this->log(
         "\033[0;30;43m SKIP \033 @; " .
         "\033[90m" . $file . "\033[0m" .
         "\033[1;35m $info \033[0m" . PHP_EOL
      );
   }
}
