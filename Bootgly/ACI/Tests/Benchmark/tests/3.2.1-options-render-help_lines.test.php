<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs\Options;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render help lines from the schema',
   test: new Assertions(Case: function (): Generator
   {
      $Options = Options::load(__DIR__ . '/fixtures/valid.options.php');

      yield new Assertion(
         description: 'Flag shapes follow the entry type',
         fallback: 'Help lines not rendered!'
      )
         ->expect(
            $Options->render(),
            Op::Equal,
            [
               '--server-workers=N|A..B|A..B:S|N,N' => 'Number of server workers (default: auto)',
               '--profile' => 'Enable the profiler',
               '--label=VALUE' => 'Run label',
            ]
         )
         ->assert();
   })
);
