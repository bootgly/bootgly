<?php

namespace Bootgly\ABI\Debugging\Data\Vars;


use function assert;
use function str_contains;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test\Specification;


class DumpBase
{
   private string $secret = 'base';   // @phpstan-ignore property.unused
   private string $shadow = 'base';   // @phpstan-ignore property.unused
}

class DumpChild extends DumpBase
{
   public static string $skip = 'static';

   public string $name = 'R';
   public readonly int $id;
   protected string $email = 'e';
   private string $shadow = 'child';  // @phpstan-ignore property.unused


   public function __construct ()
   {
      $this->id = 7;
   }
}

return new Specification(
   description: 'It should expand objects with visibility sigils and ancestor privates',
   test: function () {
      $Dumper = new Dumper('plain');

      // @ Full expansion — sigils, readonly, parent privates with origin note
      $expected = <<<'DUMP'
      Bootgly\ABI\Debugging\Data\Vars\DumpChild {
         +name: 'R'
         readonly +id: 7
         #email: 'e'
         -shadow: 'child'
         -secret: 'base' (DumpBase)
         -shadow: 'base' (DumpBase)
      }
      DUMP;
      $dumped = $Dumper->dump(new DumpChild);

      yield assert(
         assertion: $dumped === $expected,
         description: 'Sigils, readonly prefix, shadowed ancestor privates — exact shape'
      );

      // @ Static properties are skipped
      yield assert(
         assertion: str_contains($dumped, 'skip') === false,
         description: 'Static properties never render'
      );

      // @ stdClass dynamic properties
      $Std = new stdClass;
      $Std->dyn = 1;

      $expected = <<<'DUMP'
      stdClass {
         +dyn: 1
      }
      DUMP;
      yield assert(
         assertion: $Dumper->dump($Std) === $expected,
         description: 'stdClass dynamic properties expand as public'
      );

      // @ Empty body — inline braces
      yield assert(
         assertion: $Dumper->dump(new stdClass) === 'stdClass {}',
         description: 'Objects without properties dump inline as Name {}'
      );
   }
);
