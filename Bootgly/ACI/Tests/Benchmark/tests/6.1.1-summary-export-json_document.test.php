<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Summary;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should export a run as a JSON document',
   test: new Assertions(Case: function (): Generator
   {
      // ! One swept round with one result
      $Result = new Result(rps: 104532.0, latency: '1.2ms', transfer: '24.1MB/s');

      $json = Summary::export(
         caseName: 'HTTP_Server_CLI',
         metric: 'req/s',
         config: ['runner' => 'tcp_client', 'connections' => 514],
         sweeps: ['server-workers' => [1, 5]],
         rounds: [
            [
               'options' => ['server-workers' => 1],
               'results' => ['Bootgly' => ['Plaintext' => $Result]],
               'marks' => 'storage/tests/benchmarks/HTTP_Server_CLI/x-r01_bench.marks',
            ],
         ],
         artifacts: ['results/RESULTS-benchmark-x.md'],
      );

      $document = json_decode($json, true);

      yield new Assertion(
         description: 'Document decodes with the expected top-level keys',
         fallback: 'JSON document malformed!'
      )
         ->expect(
            array_keys($document),
            Op::Equal,
            ['case', 'date', 'metric', 'config', 'sweep', 'rounds', 'artifacts']
         )
         ->assert();

      yield new Assertion(
         description: 'Round carries options, serialized results and the marks path',
         fallback: 'Round serialization wrong!'
      )
         ->expect(
            $document['rounds'][0],
            Op::Equal,
            [
               'options' => ['server-workers' => 1],
               'results' => [
                  'Bootgly' => [
                     'Plaintext' => [
                        'rps' => 104532.0,
                        'latency' => '1.2ms',
                        'transfer' => '24.1MB/s',
                        'time' => null,
                        'memory' => null,
                     ],
                  ],
               ],
               'marks' => 'storage/tests/benchmarks/HTTP_Server_CLI/x-r01_bench.marks',
            ]
         )
         ->assert();

      yield new Assertion(
         description: 'Document is a single line ending in a newline',
         fallback: 'Document not single-line!'
      )
         ->expect(substr_count($json, "\n") === 1 && str_ends_with($json, "\n"), Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'Empty sweep serializes as a JSON object',
         fallback: 'Empty sweep not an object!'
      )
         ->expect(
            str_contains(Summary::export('X', 'req/s', [], [], []), '"sweep":{}'),
            Op::Identical,
            true
         )
         ->assert();
   })
);
