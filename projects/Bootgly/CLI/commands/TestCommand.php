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


use Bootgly\API\Tests\Tester;
use Bootgly\CLI;
use Bootgly\CLI\ { Command, Commanding };
use Bootgly\CLI\Terminal\components\Alert\Alert;


class TestCommand extends Command implements Commanding
{
   // * Config
   public string $name = 'test';
   public string $description = 'Run Bootgly tests';
   // * Data
   private array $tests = [
      'Bootgly/-core/tests/'
   ];


   public function run (array $arguments, array $options) : bool
   {
      $testsDir = $arguments[0] ?? null;

      if ($testsDir) {
         $tests = @include $testsDir . '@.php';

         $tests = (array) $tests;

         if ($tests['autoBoot'] ?? false) {
            $UnitTests = new Tester($tests);
         } else {
            $Output = CLI::$Terminal->Output->render('@.;');

            $Alert = new Alert($Output);
            $Alert->Type::FAILURE->set();
            $Alert->emit('Autoboot test not configured!');
         }
      } else {
         // TODO autoboot recursively all tests
         foreach ($this->tests as $test) {
            $this->run([$test], []);
         }
         return true;
      }

      return true;
   }
}
