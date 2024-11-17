<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Expectations;
use Bootgly\ACI\Tests\Assertion\Snapshots;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should handle snapshots correctly',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // @ Using capture(...) and restore(...) [string]
      $string1 = 'value';
      yield new Assertion(
         description: 'Capture strings',
         fallback: 'Strings do not match!'
      )
         ->assert(
            actual: $string1,
            expected: $string1,
         )
         ->capture('stringSnapshot');
      // ---
      $string2 = 'value';
      yield new Assertion(
         description: 'Restoring strings',
         fallback: 'Restored string does not match!'
      )
         ->restore('stringSnapshot')
         ->assert(
            actual: $string2,
            expected: $string1,
         );


      // @ Using capture(...) and restore(...) [object]
      $object1 = new stdClass();
      $object1->property = 'value';
      yield new Assertion(
         description: 'Capture objects',
         fallback: 'Objects do not match!'
      )
         ->assert(
            actual: $object1,
            expected: $object1,
         )
         ->capture('objectSnapshot');
      // ---
      $object2 = new stdClass();
      $object2->property = 'value';
      yield new Assertion(
         description: 'Restoring objects',
         fallback: 'Restored object does not match!'
      )
         ->restore('objectSnapshot')
         ->assert(
            actual: $object2,
            expected: $object1,
         );


      // @ Using With as Snapshot [array]
      $array1 = [1, 2, 3];
      yield new Assertion(
         description: 'Capturing and restoring arrays',
         fallback: 'Arrays do not match!'
      )
         ->assert(
            actual: $array1,
            expected: $array1,
            With: new Snapshots\InMemoryDefault
         );

      // @ Using With as Snapshot [object]
      $object1 = new stdClass();
      $object1->property = 'value';
      yield new Assertion(
         description: 'Capturing and restoring objects',
         fallback: 'Objects do not match!'
      )
         ->assert(
            actual: $object1,
            expected: $object1,
            With: new Snapshots\InMemoryDefault
         );

      // @ Using With as Snapshot and Expectation [int]
      yield new Assertion(
         description: 'Between integers',
         fallback: 'Integers not matched!'
      )
         ->assert(
            actual: 2,
            expected: new Expectations\Between(1, 3),
            With: new Snapshots\InFileStorage
         );

      // @ Using With as Snapshot and Expectation [DateTime]
      // DateTime
      $DateTime = new DateTime('2023-01-02');
      yield new Assertion(
         description: 'Between DateTime objects',
         fallback: 'DateTime objects not matched!'
      )
         ->assert(
            actual: $DateTime,
            expected: new Expectations\Between(
               new DateTime('2023-01-02'),
               new DateTime('2023-01-04')
            ),
            With: new Snapshots\InFileStorage
         );
   })
];