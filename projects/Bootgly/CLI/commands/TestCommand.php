<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Bootgly\CLI\commands;


use Closure;

use Bootgly\Tests;
use Bootgly\Tests\Tester;

use Bootgly\CLI;
use Bootgly\CLI\Command;

use Bootgly\CLI\Terminal\components\Alert\Alert;


class TestCommand extends Command
{
   // * Config
   public string $name = 'test';
   public string $description = 'Perform Bootgly tests';
   // * Data
   // ...


   public function run (array $arguments, array $options) : bool
   {
      // ! Tester
      // * Config
      Tests::$exitOnFailure = true;

      // @
      $testsDir = $arguments[0] ?? null;

      if ($testsDir) {
         $tests = @include $testsDir . '@.php';

         $tests = (array) $tests;

         $autoboot = $tests['autoBoot'] ?? false;
         if ($autoboot instanceof Closure) {
            $autoboot();
         } else if ($autoboot) {
            $UnitTests = new Tester($tests);
         } else {
            $Alert = new Alert(CLI::$Terminal->Output);
            $Alert->Type::FAILURE->set();
            $Alert->emit('AutoBoot test not configured!');
         }
      } else {
         $suiteFiles0 = @include BOOTGLY_DIR . '/tests/@.php';

         $bootglyTests = null;
         $suiteFiles1 = null;
         if (BOOTGLY_DIR !== BOOTGLY_WORKABLES_DIR) {
            $suiteFiles1 = @include BOOTGLY_WORKABLES_DIR . '/tests/@.php';
         } else {
            $bootglyTests = true;
         }

         $bootglyTests ??= $options['bootgly'] ?? $options['all'];

         if ($bootglyTests) {
            foreach (@$suiteFiles0['filesSuites'] as $dir) {
               $this->run([$dir . 'tests/'], []);
            }
         }

         foreach (@$suiteFiles1['filesSuites'] as $dir) {
            $this->run([$dir . 'tests/'], []);
         }

         return true;
      }

      return true;
   }
}
