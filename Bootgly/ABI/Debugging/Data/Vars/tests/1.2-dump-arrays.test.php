<?php

namespace Bootgly\ABI\Debugging\Data\Vars;


use function assert;
use function str_ends_with;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should dump arrays with counted headers and 3-space nesting',
   test: function () {
      $Dumper = new Dumper('plain');

      // @ Empty array — inline
      yield assert(
         assertion: $Dumper->dump([]) === '[]',
         description: 'An empty array dumps inline as []'
      );

      // @ Associative + list keys
      $expected = <<<'DUMP'
      array:3 [
         'name' => 'R'
         0 => 1
         'tags' => []
      ]
      DUMP;
      yield assert(
         assertion: $Dumper->dump(['name' => 'R', 0 => 1, 'tags' => []]) === $expected,
         description: 'String keys quote, int keys dump bare, empty nested arrays inline'
      );

      // @ Nesting — 3 spaces per level
      $expected = <<<'DUMP'
      array:1 [
         'a' => array:1 [
            'b' => array:1 [
               0 => true
            ]
         ]
      ]
      DUMP;
      yield assert(
         assertion: $Dumper->dump(['a' => ['b' => [true]]]) === $expected,
         description: 'Nested arrays indent 3 spaces per level'
      );

      // @ No trailing newline
      yield assert(
         assertion: str_ends_with($Dumper->dump([1]), ']') === true,
         description: 'Dumps have no trailing newline'
      );
   }
);
