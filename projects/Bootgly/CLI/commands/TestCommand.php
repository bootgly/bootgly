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
use Bootgly\API\Tests;
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
      'Bootgly/CLI/',
      'Bootgly/Web/nodes/HTTP/Server/',
   ];


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
