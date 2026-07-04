<?php

use Generator;
use RuntimeException;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs\Options;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject invalid sweep values',
   test: new Assertions(Case: function (): Generator
   {
      // ! Helper — true when expand() throws for the value
      $throws = static function (string $value): bool {
         try {
            Options::expand($value);
         }
         catch (RuntimeException) {
            return true;
         }

         return false;
      };

      // @@ Malformed values
      foreach (['1..', '..4', 'a..b', '1..4:x', '', '1,,2', '1;2', '-1..4'] as $value) {
         yield new Assertion(
            description: "Rejects '{$value}'",
            fallback: "Value '{$value}' not rejected!"
         )
            ->expect($throws($value), Op::Identical, true)
            ->assert();
      }

      yield new Assertion(
         description: 'Rejects a reversed range',
         fallback: 'Reversed range not rejected!'
      )
         ->expect($throws('24..1'), Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'Rejects a zero step',
         fallback: 'Zero step not rejected!'
      )
         ->expect($throws('1..4:0'), Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'Rejects a series longer than the cap',
         fallback: 'Oversized series not rejected!'
      )
         ->expect($throws('1..100000'), Op::Identical, true)
         ->assert();
   })
);
