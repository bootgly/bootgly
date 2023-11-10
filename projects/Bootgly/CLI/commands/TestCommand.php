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
      $indexOfTestableSuite = (int) ($arguments[0] ?? 0);
      $indexOfTestableCase = (int) ($arguments[1] ?? 0);
      if ($indexOfTestableSuite < 1) {
         $indexOfTestableCase = 0;
      }
      // options
      $bootglyTests = $options['bootgly'] ?? $options['all'] ?? null;

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

         if ($indexOfTestableSuite > 0 && ($index + 1) !== $indexOfTestableSuite) {
            $Suites->skipped++;
            continue;
         }

         $this->test($dir, $indexOfTestableCase);

         $Suites->passed++;
      }

      $Suites->summarize();

      return true;
   }

   // @
   public function test (string $suiteDir, ? int $index)
   {
      $bootstrapFile = Path::normalize($suiteDir . '/tests/@.php');
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         $suiteSpecs = (include BOOTGLY_WORKING_DIR . $bootstrapFile);
      } else {
         $suiteSpecs = (include BOOTGLY_ROOT_DIR . $bootstrapFile);
      }

      if ($suiteSpecs === false) {
         return false;
      }

      $suiteSpecs = (array) $suiteSpecs;

      // * Config
      if ($index) {
         $suiteSpecs['index'] = $index;
      }

      // @
      $autoBoot = $suiteSpecs['autoBoot'] ?? false;
      if ($autoBoot instanceof Closure) {
         unset($suiteSpecs['autoBoot']);
         $autoBoot($suiteSpecs);
      } else if ($autoBoot) {
         new Tester($suiteSpecs);
      } else {
         $Alert = new Alert(CLI::$Terminal->Output);
         $Alert->Type::FAILURE->set();
         $Alert->message = 'AutoBoot test not configured!';
         $Alert->render();
      }
   }
}
