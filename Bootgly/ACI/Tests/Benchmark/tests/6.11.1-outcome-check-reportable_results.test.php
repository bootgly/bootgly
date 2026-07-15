<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Outcome;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject missing or empty benchmark outcomes',
   test: new Assertions(Case: function (): Generator
   {
      $missing = Outcome::check([], ['bootgly']);
      $empty = Outcome::check(['Bootgly' => []], ['bootgly']);
      $partial = Outcome::check([
         'Optional' => [],
         'Bootgly' => ['default' => new Result(time: '0.001')],
      ], ['optional', 'bootgly']);

      yield new Assertion(
         description: 'Only an attributable round with at least one measurement is accepted',
         fallback: 'Outcome validation accepted an empty round or rejected a valid partial matrix!'
      )
         ->expect(
            is_string($missing)
               && str_contains($missing, 'bootgly')
               && is_string($empty)
               && str_contains($empty, 'no reportable measurement')
               && $partial === null,
            Op::Identical,
            true
         )
         ->assert();
   })
);
