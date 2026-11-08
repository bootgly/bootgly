<?php

use Bootgly\ABI\Syntax\Lint\Scope;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Scope: find() names the innermost named class-like declaration around a line; anonymous classes, Foo::class and keyword method names never open one',
   test: function () {
      $source = <<<'PHP'
      <?php
      namespace Demo;
      $outside = 1;
      class Alpha
      {
         public function run () {
            $x = Alpha::class;
            return new class {
               public function inner () {}
            };
         }
         public function class () {}
      }
      interface Beta
      {
         public function go ();
      }
      trait Gamma { public function mix () { return "{$this->x}"; } }
      enum Delta: string
      {
         case One = '1';
      }
      $after = 2;
      PHP;
      $Scope = new Scope($source);

      $lines = [3 => '', 4 => 'Alpha', 6 => 'Alpha', 9 => 'Alpha', 12 => 'Alpha', 13 => 'Alpha', 16 => 'Beta', 18 => 'Gamma', 21 => 'Delta', 23 => ''];
      $found = [];
      foreach ($lines as $line => $expected) {
         $found[$line] = $Scope->find($line);
      }
      yield assert(
         assertion: $found === $lines,
         description: 'find() must name the enclosing declaration of each line, got: ' . json_encode($found)
      );
      yield assert(
         assertion: count($Scope->ranges) === 4,
         description: 'Only the four named declarations open a range, got: ' . json_encode($Scope->ranges)
      );
   }
);
