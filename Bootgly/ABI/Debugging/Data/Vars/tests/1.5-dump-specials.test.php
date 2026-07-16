<?php

namespace Bootgly\ABI\Debugging\Data\Vars;


use function assert;
use function fclose;
use function fopen;
use function str_contains;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test\Specification;


enum DumpStatus: string
{
   case Active = 'active';
}

enum DumpLevel: int
{
   case High = 3;
}

enum DumpPure
{
   case One;
}

class DumpInfo
{
   private int $x = 1;   // @phpstan-ignore property.unused


   /** @return array<mixed> */
   public function __debugInfo (): array
   {
      return ['shown' => true];
   }
}

class DumpInfoEmpty
{
   /** @return array<mixed> */
   public function __debugInfo (): array
   {
      return [];
   }
}

return new Specification(
   description: 'It should dump enums, closures, resources and __debugInfo bodies',
   test: function () {
      $Dumper = new Dumper('plain');

      // @ Enums — inline, backed value rendered by its own type
      yield assert(
         assertion: $Dumper->dump(DumpStatus::Active) === 'Bootgly\ABI\Debugging\Data\Vars\DumpStatus::Active = \'active\''
            && $Dumper->dump(DumpLevel::High) === 'Bootgly\ABI\Debugging\Data\Vars\DumpLevel::High = 3'
            && $Dumper->dump(DumpPure::One) === 'Bootgly\ABI\Debugging\Data\Vars\DumpPure::One',
         description: 'Backed enums append their value, pure enums stay bare'
      );

      // @ Closures — located, never expanded
      $Closure = function () {
         return 1;
      };
      $dumped = $Dumper->dump($Closure);

      yield assert(
         assertion: str_contains($dumped, 'Closure (') === true
            && str_contains($dumped, '1.5-dump-specials.test.php:') === true,
         description: 'Userland closures dump with their file:line location'
      );

      yield assert(
         assertion: $Dumper->dump(strlen(...)) === 'Closure (internal)',
         description: 'Internal-function closures dump as (internal)'
      );

      // @ Resources — open and closed
      $handle = fopen('php://memory', 'r');

      yield assert(
         assertion: $Dumper->dump($handle) === 'resource (stream)',
         description: 'Open resources dump their resource type'
      );

      fclose($handle);

      yield assert(
         assertion: $Dumper->dump($handle) === 'resource (closed)',
         description: 'Closed resources degrade to their type name'
      );

      // @ __debugInfo — author-defined body, no sigils
      $expected = <<<'DUMP'
      Bootgly\ABI\Debugging\Data\Vars\DumpInfo {
         'shown' => true
      }
      DUMP;
      yield assert(
         assertion: $Dumper->dump(new DumpInfo) === $expected
            && $Dumper->dump(new DumpInfoEmpty) === 'Bootgly\ABI\Debugging\Data\Vars\DumpInfoEmpty {}',
         description: '__debugInfo overrides the property walk'
      );
   }
);
