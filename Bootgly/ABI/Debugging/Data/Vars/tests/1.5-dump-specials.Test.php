<?php

namespace Bootgly\ABI\Debugging\Data\Vars;


use function assert;
use function fclose;
use function fopen;
use function str_contains;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test;


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

class DumpInfoNull
{
   public string $visible = 'v';


   /** @return null|array<mixed> */
   public function __debugInfo (): null|array
   {
      return null;
   }
}

class DumpInfoScalar
{
   public string $visible = 'v';


   /** @return mixed */
   public function __debugInfo ()   // @phpstan-ignore missingType.return
   {
      return 'not an array';
   }
}

return new Test(
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
            && str_contains($dumped, '1.5-dump-specials.Test.php:') === true,
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

      // @ A body that is not an array is the empty body `var_dump` shows — never the walk, never a crash
      yield assert(
         assertion: $Dumper->dump(new DumpInfoNull) === 'Bootgly\ABI\Debugging\Data\Vars\DumpInfoNull {}'
            && $Dumper->dump(new DumpInfoScalar) === 'Bootgly\ABI\Debugging\Data\Vars\DumpInfoScalar {}',
         description: 'A null or non-array __debugInfo body renders {}'
      );
   }
);
