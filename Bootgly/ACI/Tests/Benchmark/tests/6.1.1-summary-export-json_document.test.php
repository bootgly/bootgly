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
         scheduled: 104538,
         sent: 104535,
         responses: 104532,
         informational: 7,
         outstanding: 0,
         failed: 1,
         writeFailed: 1,
         connectionFailed: 0,
         partialWrites: 2,
         accounting: true,
         statuses: [200 => 104530, 503 => 2],
         failures: ['connection_closed' => 1],
         writeFailures: ['measurement_ended' => 1],
         censored: 2,
         writeCensored: 2,
         censors: ['measurement_ended' => 2],
         writeCensors: ['measurement_ended' => 2],
         latencySummary: [
            'count' => 104532,
            'sum_ns' => 125438400000,
            'sum_overflow' => false,
            'min_ns' => 8500,
            'p50_ns' => 12000,
            'p95_ns' => 18000,
            'p99_ns' => 24000,
            'p99_9_ns' => 31000,
            'max_ns' => 47000,
            'underflow' => 0,
            'overflow' => 0,
            'fidelity' => true,
         ],
         latencyHistogram: [
            'schema' => 'bootgly.latency-hdr.v1',
            'sparse_counts' => [['index' => 12, 'count' => 104532]],
         ],
         timeSeries: [
            'schema' => 'bootgly.time-series.v1',
            'buckets' => [['second' => 0, 'responses' => 104532]],
         ],
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
         artifacts: [
            'results/RESULTS-benchmark-x.md',
            'storage/tests/benchmarks/HTTP_Server_CLI/runs/x/telemetry/r01/Bootgly--Plaintext.json',
         ],
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
                        'scheduled' => 104538,
                        'sent' => 104535,
                        'responses' => 104532,
                        'informational' => 7,
                        'outstanding' => 0,
                        'failed' => 1,
                        'write_failed' => 1,
                        'connection_failed' => 0,
                        'partial_writes' => 2,
                        'accounting' => true,
                        'statuses' => [200 => 104530, 503 => 2],
                        'failures' => ['connection_closed' => 1],
                        'write_failures' => ['measurement_ended' => 1],
                        'censored' => 2,
                        'write_censored' => 2,
                        'censors' => ['measurement_ended' => 2],
                        'write_censors' => ['measurement_ended' => 2],
                        'latency_summary' => [
                           'count' => 104532,
                           'sum_ns' => 125438400000,
                           'sum_overflow' => false,
                           'min_ns' => 8500,
                           'p50_ns' => 12000,
                           'p95_ns' => 18000,
                           'p99_ns' => 24000,
                           'p99_9_ns' => 31000,
                           'max_ns' => 47000,
                           'underflow' => 0,
                           'overflow' => 0,
                           'fidelity' => true,
                        ],
                        'latency_histogram' => [
                           'schema' => 'bootgly.latency-hdr.v1',
                           'sparse_counts' => [['index' => 12, 'count' => 104532]],
                        ],
                        'time_series' => [
                           'schema' => 'bootgly.time-series.v1',
                           'buckets' => [['second' => 0, 'responses' => 104532]],
                        ],
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
         description: 'JSON explicitly advertises the detailed telemetry sidecar',
         fallback: 'Telemetry artifact is not discoverable in JSON!'
      )
         ->expect(
            $document['artifacts'],
            Op::Equal,
            [
               'results/RESULTS-benchmark-x.md',
               'storage/tests/benchmarks/HTTP_Server_CLI/runs/x/telemetry/r01/Bootgly--Plaintext.json',
            ]
         )
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
