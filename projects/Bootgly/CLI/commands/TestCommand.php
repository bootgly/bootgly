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
   public string $description = 'Perform Bootgly tests';
   // * Data
   private array $tests = [
      'Bootgly/-core/',
      'Bootgly/Web/nodes/HTTP/Server/',
      'Bootgly/CLI/',
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
            $Alert->emit('AutoBoot test not configured!');
         }
      } else {
         // TODO autoboot recursively all tests
         foreach ($this->tests as $dir) {
            $this->run([$dir . 'tests/'], []);
         }

         return true;
      }

      return true;
   }
}
