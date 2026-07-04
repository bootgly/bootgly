<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs\Options;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should load and normalize a valid schema',
   test: new Assertions(Case: function (): Generator
   {
      $Options = Options::load(__DIR__ . '/fixtures/valid.options.php');

      yield new Assertion(
         description: 'Schema has the declared options',
         fallback: 'Schema keys not loaded!'
      )
         ->expect(['server-workers', 'profile', 'label'], Op::Equal, array_keys($Options->schema))
         ->assert();

      yield new Assertion(
         description: 'Normalization fills vary/default/description',
         fallback: 'Schema entry not normalized!'
      )
         ->expect(
            [
               'type' => 'bool',
               'default' => null,
               'vary' => false,
               'description' => 'Enable the profiler',
            ],
            Op::Equal,
            $Options->schema['profile']
         )
         ->assert();

      yield new Assertion(
         description: 'Missing schema file yields an empty schema',
         fallback: 'Missing file did not yield empty schema!'
      )
         ->expect(Options::load(__DIR__ . '/fixtures/absent.options.php')->schema, Op::Equal, [])
         ->assert();
   })
);
