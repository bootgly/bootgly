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


use Bootgly\API\Tests;


class Tester extends Tests
{
   // * Config
   public string $autoBoot;
   public bool $autoInstance;
   public bool $autoResult;
   public bool $autoSummarize;
   public bool $exitOnFailure;

   // * Data
   // ...extended

   // * Meta
   // ...extended


   public function __construct (array &$tests)
   {
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
         $dir = $this->autoBoot . DIRECTORY_SEPARATOR;

         foreach ($this->tests as $test) {
            $specifications = require $dir . $test . '.test.php';
            $this->specifications[] = $specifications;
         }
      }
      if ($this->autoInstance) {
         foreach ($this->specifications as $specification) {
            $Test = $this->test($specification);
   
            $Test->separate();
   
            $Test->test();
         }
      }
      if ($this->autoSummarize) {
         $this->summarize();
      }

      if ($this->failed > 0 && $this->exitOnFailure) {
         exit(1);
      }
   }

   public function test (? array &$specifications) : Test|false
   {
      if ( $specifications === null || empty($specifications) ) {
         $this->skipped++;
         return false;
      }

      $Test = new Test($this, $specifications);

      if (key($this->tests) < $this->total) {
         next($this->tests);
      }

      return $Test;
   }
}
