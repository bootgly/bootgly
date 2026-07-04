<?php

use Generator;
use RuntimeException;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs\Options;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should expand sweep values',
   test: new Assertions(Case: function (): Generator
   {
      yield new Assertion(
         description: 'Single value',
         fallback: 'Single value not expanded!'
      )
         ->expect(Options::expand('8'), Op::Equal, [8])
         ->assert();

      yield new Assertion(
         description: 'Range',
         fallback: 'Range not expanded!'
      )
         ->expect(Options::expand('1..4'), Op::Equal, [1, 2, 3, 4])
         ->assert();

      yield new Assertion(
         description: 'Range with step',
         fallback: 'Stepped range not expanded!'
      )
         ->expect(Options::expand('1..24:4'), Op::Equal, [1, 5, 9, 13, 17, 21])
         ->assert();

      yield new Assertion(
         description: 'List',
         fallback: 'List not expanded!'
      )
         ->expect(Options::expand('1,2,4,8'), Op::Equal, [1, 2, 4, 8])
         ->assert();

      yield new Assertion(
         description: 'List deduplicates preserving order',
         fallback: 'List not deduplicated!'
      )
         ->expect(Options::expand('4,1,4,2,1'), Op::Equal, [4, 1, 2])
         ->assert();

      yield new Assertion(
         description: 'Single-point range',
         fallback: 'Single-point range not expanded!'
      )
         ->expect(Options::expand('8..8'), Op::Equal, [8])
         ->assert();

      yield new Assertion(
         description: 'Step greater than span keeps the start',
         fallback: 'Step > span not handled!'
      )
         ->expect(Options::expand('3..5:10'), Op::Equal, [3])
         ->assert();
   })
);
