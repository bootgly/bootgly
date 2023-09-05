<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Bootgly\CLI\commands;


use Closure;

use Bootgly\ABI\Data\__String\Path;

use Bootgly\ACI\Tests;
use Bootgly\ACI\Tests\Suites;
use Bootgly\ACI\Tests\Tester;

use Bootgly\CLI;
use Bootgly\CLI\Command;
use Bootgly\CLI\Terminal\components\Alert\Alert;


class TestCommand extends Command
{
   // * Config
   public int $group = 1;

   // * Data
   // @ Command
   public string $name = 'test';
   public string $description = 'Perform Bootgly tests';


   public function run (array $arguments, array $options) : bool
   {
      // ! Tester
      // * Config
      Tests::$exitOnFailure = true;

      // @
      $testsDir = $arguments[0] ?? null;

      if (empty($options) && $testsDir !== null && $testsDir != (int) $testsDir) {
         $this->test($testsDir);
         return true;
      } else if ($testsDir == (int) $testsDir) {
         $options = array_merge(['i' => $testsDir], $options);
      }

      $this->load($options);

      return true;
   }

   // @
   public function test (string $dir)
   {
      $bootstrap = Path::normalize($dir . '/tests/@.php');

      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         $tests = (include BOOTGLY_WORKING_DIR . $bootstrap);
      } else {
         $tests = (include BOOTGLY_ROOT_DIR . $bootstrap);
      }

      if ($tests === false) {
         return false;
      }

      $tests = (array) $tests;

      $autoboot = $tests['autoBoot'] ?? false;
      if ($autoboot instanceof Closure) {
         $autoboot();
      } else if ($autoboot) {
         new Tester($tests);
      } else {
         $Alert = new Alert(CLI::$Terminal->Output);
         $Alert->Type::FAILURE->set();
         $Alert->emit('AutoBoot test not configured!');
      }
   }
   // @ Suites
   public function load (array $options)
   {
      $suites = [];

      // options
      $bootglyTests = $options['bootgly'] ?? $options['all'];
      $indexToTest = (int) ($options['index'] ?? $options['i'] ?? 0);

      // @ Load Author tests
      if (BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR || $bootglyTests) {
         $bootstrap0 = include(BOOTGLY_ROOT_DIR . '/tests/@.php');

         $suites = $bootstrap0['suites'] ?? [];
      }

      // @ Load Consumer tests
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         $bootstrap1 = include(BOOTGLY_WORKING_DIR . '/tests/@.php');

         $suites = array_merge($suites, $bootstrap1['suites'] ?? []);
      }

      // @
      $Suites = new Suites;
      $Suites->total = count($suites);

      foreach ($suites as $index => $dir) {
         Tester::$index++;

         if ($indexToTest > 0 && ($index + 1) !== $indexToTest) {
            $Suites->skipped++;
            continue;
         }

         $this->test($dir);

         $Suites->passed++;
      }

      $Suites->summarize();

      return true;
   }
}
