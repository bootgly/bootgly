<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should parse the global output/format/results options',
   test: new Assertions(Case: function (): Generator
   {
      // # Defaults
      $Configs = Configs::parse([]);

      yield new Assertion(
         description: 'output defaults to null (auto)',
         fallback: 'output default wrong!'
      )
         ->expect($Configs->output === null, Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: "format defaults to 'text'",
         fallback: 'format default wrong!'
      )
         ->expect($Configs->format, Op::Identical, 'text')
         ->assert();

      yield new Assertion(
         description: "results defaults to 'marks'",
         fallback: 'results default wrong!'
      )
         ->expect($Configs->results, Op::Identical, 'marks')
         ->assert();

      // # Explicit values (lowercased)
      $Configs = Configs::parse([
         'output' => 'COMPACT',
         'format' => 'JSON',
         'results' => 'Charts',
      ]);

      yield new Assertion(
         description: 'Values are lowercased',
         fallback: 'Values not lowercased!'
      )
         ->expect(
            [$Configs->output, $Configs->format, $Configs->results],
            Op::Equal,
            ['compact', 'json', 'charts']
         )
         ->assert();
   })
);
