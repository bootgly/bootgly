<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Summary;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should present latency percentiles and locate telemetry sidecars',
   test: new Assertions(Case: function (): \Generator
   {
      $Result = new Result(
         rps: 1000.0,
         latency: '1ms',
         transfer: '1MB/s',
         latencySummary: [
            'p50_ns' => 999,
            'p95_ns' => 1500,
            'p99_ns' => 2000000,
            'p99_9_ns' => 3000000000,
            'max_ns' => null,
            'fidelity' => false,
         ],
      );

      ob_start();
      Summary::report(
         [
            'Bootgly' => ['Plaintext' => $Result],
            'Swoole' => [
               'Plaintext' => new Result(
                  rps: 900.0,
                  latency: '1.2ms',
                  transfer: '900KB/s',
               ),
            ],
         ],
         compact: true,
      );
      $output = ob_get_clean();
      $plain = is_string($output)
         ? preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output
         : '';

      yield new Assertion(
         description: 'Terminal output shows all requested percentiles with adaptive units and fidelity',
         fallback: 'Latency percentile presentation is missing or ambiguous!'
      )
         ->expect(
            str_contains(
               $plain,
               'Percentiles  p50 999ns · p95 1.50µs · p99 2.00ms'
                  . ' · p99.9 3.00s · max N/A · fidelity incomplete'
            )
               && substr_count($plain, 'Percentiles') === 1,
            Op::Identical,
            true,
         )
         ->assert();

      $telemetry = 'storage/tests/benchmarks/HTTP_Server_CLI/runs/x/'
         . 'telemetry/r01/Bootgly--Plaintext.json';
      ob_start();
      Summary::locate(
         ['storage/tests/benchmarks/HTTP_Server_CLI/runs/x/marks/r01_bench.marks'],
         [
            $telemetry,
            'storage/tests/benchmarks/HTTP_Server_CLI/runs/x/reports/results.md',
            'storage/tests/benchmarks/HTTP_Server_CLI/runs/x/manifest.json',
         ],
         'storage/tests/benchmarks/HTTP_Server_CLI/runs/x',
         '/opt/bootgly',
      );
      $output = ob_get_clean();
      $plain = is_string($output)
         ? preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output
         : '';

      yield new Assertion(
         description: 'Artifact footer labels the telemetry sidecar separately from reports',
         fallback: 'One-second series sidecar is not discoverable in the footer!'
      )
         ->expect(
            str_contains($plain, "Telemetry {$telemetry}")
               && str_contains($plain, 'Report   storage/tests/benchmarks/HTTP_Server_CLI/runs/x/reports/results.md')
               && str_contains($plain, 'Manifest storage/tests/benchmarks/HTTP_Server_CLI/runs/x/manifest.json'),
            Op::Identical,
            true,
         )
         ->assert();

      $parent = 'storage/tests/benchmarks/HTTP_Server_CLI/runs/x/telemetry/result';
      ob_start();
      Summary::locate(
         ['storage/tests/benchmarks/HTTP_Server_CLI/runs/x/marks/r01_bench.marks'],
         [
            "{$parent}/Bootgly--Plaintext.json",
            "{$parent}/Bootgly--JSON.json",
            "{$parent}/Swoole--Plaintext.json",
            'storage/tests/benchmarks/HTTP_Server_CLI/runs/x/manifest.json',
         ],
         'storage/tests/benchmarks/HTTP_Server_CLI/runs/x',
         '/opt/bootgly',
      );
      $output = ob_get_clean();
      $plain = is_string($output)
         ? preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output
         : '';

      yield new Assertion(
         description: 'Artifact footer groups multiple telemetry sidecars per directory with a count',
         fallback: 'Multi-load telemetry sidecars are not grouped in the footer!'
      )
         ->expect(
            str_contains($plain, "Telemetry {$parent}/ (3 files)")
               && substr_count($plain, 'Telemetry') === 1
               && str_contains($plain, 'Manifest storage/tests/benchmarks/HTTP_Server_CLI/runs/x/manifest.json'),
            Op::Identical,
            true,
         )
         ->assert();
   })
);
