<?php

namespace Bootgly\ABI\Debugging\Data\Vars;


use function assert;
use function str_contains;
use ValueError;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should resolve named dump themes from the registry',
   test: function () {
      // @ Custom named theme — registered values replace the default palette
      Dumper::$Themes['test.dump'] = [
         Dumper::TYPE_INT => '31'
      ];

      $Dumper = new Dumper('test.dump');

      yield assert(
         assertion: $Dumper->dump(5) === "\e[31m5\e[0m",
         description: 'A registered named theme paints with its own values'
      );

      unset(Dumper::$Themes['test.dump']);

      // @ Builtin `plain` — colorless render, zero escapes
      $Dumper = new Dumper('plain');

      yield assert(
         assertion: str_contains($Dumper->dump(['k' => 5]), "\e") === false,
         description: 'The builtin `plain` theme emits no escape codes'
      );

      // @ Default — the `bootgly` palette
      $Dumper = new Dumper;

      yield assert(
         assertion: str_contains($Dumper->dump(['k' => 5]), "\e[") === true,
         description: 'The default theme is the `bootgly` palette'
      );

      // @ Unknown names fail loud
      $caught = false;
      try {
         new Dumper('nonexistent');
      }
      catch (ValueError) {
         $caught = true;
      }

      yield assert(
         assertion: $caught === true,
         description: 'An unknown theme name throws a ValueError'
      );
   }
);
