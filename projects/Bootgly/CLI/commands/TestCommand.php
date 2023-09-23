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
      // arguments
      $indexOfSuiteToTest = (int) ($arguments[0] ?? 0);
      $indexOfCaseToTest = (int) ($arguments[1] ?? 0);
      // options
      $bootglyTests = $options['bootgly'] ?? $options['all'];

      $suites = [];

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
         Tester::$suite++;

         if ($indexOfSuiteToTest > 0 && ($index + 1) !== $indexOfSuiteToTest) {
            $Suites->skipped++;
            continue;
         }

         $this->test($dir, $indexOfCaseToTest);

         $Suites->passed++;
      }

      $Suites->summarize();

      return true;
   }

   // @
   public function test (string $dir, ? int $index)
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

      if ($index) {
         $tests['index'] = $index;
      }

      $autoboot = $tests['autoBoot'] ?? false;
      if ($autoboot instanceof Closure) {
         $autoboot();
      } else if ($autoboot) {
         new Tester($tests, $index);
      } else {
         $Alert = new Alert(CLI::$Terminal->Output);
         $Alert->Type::FAILURE->set();
         $Alert->emit('AutoBoot test not configured!');
      }
   }
}
