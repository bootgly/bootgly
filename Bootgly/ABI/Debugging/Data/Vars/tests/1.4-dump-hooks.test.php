<?php

namespace Bootgly\ABI\Debugging\Data\Vars;


use function assert;
use function str_contains;
use function strtoupper;

use Bootgly\ACI\Tests\Suite\Test\Specification;


class DumpHooked
{
   public string $backed = 'stored' {
      get => strtoupper($this->backed);
   }
   public string $virtual {
      get => 'computed';
   }
   protected string $token;
}

return new Specification(
   description: 'It should dump hooked and uninitialized properties without side effects',
   test: function () {
      $Dumper = new Dumper('plain');

      $expected = <<<'DUMP'
      Bootgly\ABI\Debugging\Data\Vars\DumpHooked {
         +backed: 'stored'
         +virtual: virtual
         #token: uninitialized
      }
      DUMP;
      $dumped = $Dumper->dump(new DumpHooked);

      yield assert(
         assertion: $dumped === $expected,
         description: 'Backed raw value, virtual note and uninitialized note — exact shape'
      );

      // @ Backed hooks are never triggered — the RAW stored value renders
      yield assert(
         assertion: str_contains($dumped, "'stored'") === true
            && str_contains($dumped, 'STORED') === false,
         description: 'Get hooks never run — the raw backing value renders'
      );
   }
);
