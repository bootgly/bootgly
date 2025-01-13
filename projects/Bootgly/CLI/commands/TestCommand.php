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
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suites;
use Bootgly\ACI\Tests;
use const Bootgly\CLI;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Components\Alert;


class TestCommand extends Command
{
   // * Config
   public int $group = 1;

   // * Data
   // @ Command
   public string $name = 'test';
   public string $description = 'Perform Bootgly tests';


   public function run (array $arguments = [], array $options = []): bool
   {
      // ! Tester
      // * Config
      Suite::$exitOnFailure = true;

      // !
      // arguments
      $suite_index = (int) ($arguments[0] ?? 0);
      $case_index = (int) ($arguments[1] ?? 0);
      if ($case_index < 1) {
         $case_index = 0;
      }
      if ($suite_index < 1) {
         $suite_index = 0;
      }
      // options
      // ...


      // @
      $Tests = new Tests;
      // $Tester = new Tester;
      $Tests->Suites->iterate(
         $suite_index,
         $case_index,
         fn (string $suiteDir, int $index) => $this->test($suiteDir, $index)
      );
      $Tests->Suites->summarize();

      return true;
   }

   // # Test Suite
   public function test (string $suiteDir, null|int $index): null|true|Suite
   {
      // !
      $bootstrapFile = Path::normalize($suiteDir . '/tests/@.php');
      BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR
         ? $Suite = (include BOOTGLY_WORKING_DIR . $bootstrapFile)
         : $Suite = (include BOOTGLY_ROOT_DIR . $bootstrapFile);
      // ?
      if ($Suite instanceof Suite === false) {
         return null;
      }

      // ?!
      // * Config
      if ($index) {
         $Suite->target = $index;
      }

      // @
      $autoBoot = $Suite->autoBoot ?? false;
      if ($autoBoot instanceof Closure) {
         return $autoBoot($Suite);
      }
      else if ($autoBoot) {
         $Suite->autoboot($autoBoot);

         if ($Suite->autoInstance) {
            $Suite->autoinstance($Suite->autoInstance);
         }
         if ($Suite->autoSummarize) {
            $Suite->summarize();
         }

         return $Suite;
      }

      $Alert = new Alert(CLI->Terminal->Output);
      $Alert->Type::Failure->set();
      $Alert->message = 'AutoBoot test not configured!';
      $Alert->render();

      return null;
   }
}
