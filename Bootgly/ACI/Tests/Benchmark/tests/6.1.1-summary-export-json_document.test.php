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
      $Result = new Result(
         rps: 104532.0,
         latency: '1.2ms',
         transfer: '24.1MB/s',
         scheduled: 104536,
         sent: 104535,
         responses: 104532,
         informational: 7,
         outstanding: 0,
         failed: 3,
         writeFailed: 1,
         connectionFailed: 0,
         partialWrites: 2,
         accounting: true,
         statuses: [200 => 104530, 503 => 2],
         failures: ['measurement_ended' => 3],
         writeFailures: ['measurement_ended' => 1],
      );
      $trackedSHA = str_repeat('a', 64);
      $untrackedSHA = str_repeat('b', 64);

      $json = Summary::export(
         caseName: 'HTTP_Server_CLI',
         metric: 'req/s',
         config: [
            'source-identity-version' => 'raw-delta-manifest-v1',
            'runner' => 'tcp_client',
            'connections' => 514,
            'framework-tracked-diff-sha256' => $trackedSHA,
            'framework-untracked-manifest-sha256' => $untrackedSHA,
         ],
         sweeps: ['server-workers' => [1, 5]],
         rounds: [
            [
               'options' => ['server-workers' => 1],
               'results' => ['Bootgly' => ['Plaintext' => $Result]],
               'marks' => 'storage/tests/benchmarks/HTTP_Server_CLI/x-r01_bench.marks',
            ],
         ],
         artifacts: ['results/RESULTS-benchmark-x.md'],
         ID: '20260714T120000.000000Z-p123-aabbccdd',
         directory: 'storage/tests/benchmarks/HTTP_Server_CLI/runs/20260714T120000.000000Z-p123-aabbccdd',
         pathBase: '/opt/bootgly',
      );

      $document = json_decode($json, true);

      yield new Assertion(
         description: 'Document decodes with the expected top-level keys',
         fallback: 'JSON document malformed!'
      )
         ->expect(
            array_keys($document),
            Op::Equal,
            ['run', 'case', 'date', 'metric', 'config', 'sweep', 'rounds', 'artifacts']
         )
         ->assert();

      yield new Assertion(
         description: 'JSON carries the collision-resistant invocation identity',
         fallback: 'Run identity missing from JSON!'
      )
         ->expect(
            $document['run'] === [
               'id' => '20260714T120000.000000Z-p123-aabbccdd',
               'directory' => 'storage/tests/benchmarks/HTTP_Server_CLI/runs/20260714T120000.000000Z-p123-aabbccdd',
               'path_base' => '/opt/bootgly',
            ],
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'JSON config preserves dirty-tree fingerprints',
         fallback: 'JSON export dropped source identity!'
      )
         ->expect(
            $document['config']['framework-tracked-diff-sha256'] === $trackedSHA
               && $document['config']['framework-untracked-manifest-sha256'] === $untrackedSHA
               && $document['config']['source-identity-version'] === 'raw-delta-manifest-v1',
            Op::Identical,
            true
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
                        'scheduled' => 104536,
                        'sent' => 104535,
                        'responses' => 104532,
                        'informational' => 7,
                        'outstanding' => 0,
                        'failed' => 3,
                        'write_failed' => 1,
                        'connection_failed' => 0,
                        'partial_writes' => 2,
                        'accounting' => true,
                        'statuses' => [200 => 104530, 503 => 2],
                        'failures' => ['measurement_ended' => 3],
                        'write_failures' => ['measurement_ended' => 1],
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
