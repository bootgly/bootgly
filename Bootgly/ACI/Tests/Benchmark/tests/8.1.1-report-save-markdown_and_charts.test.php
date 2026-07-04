<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Report;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should write the Markdown report and SVG charts',
   test: new Assertions(Case: function (): Generator
   {
      // ! Swept 2-opponent run (2 x points)
      $run = [
         'case' => 'HTTP_Server_CLI',
         'loadSet' => 'techempower',
         'metric' => 'req/s',
         'command' => 'bootgly test benchmark HTTP_Server_CLI --opponents=bootgly,swoole --loads=techempower:1 --server-workers=1..2',
         'env' => ['OS' => 'Linux', 'CPU' => '24 cores'],
         'config' => ['connections' => 514, 'duration' => 10],
         'sweep' => ['server-workers' => [1, 2]],
         'loads' => ['Plaintext'],
         'opponents' => ['Bootgly', 'Swoole'],
         'data' => [
            'Plaintext' => [
               'Bootgly' => [100000.0, 200000.0],
               'Swoole' => [80000.0, 160000.0],
            ],
         ],
         'latencies' => [
            'Plaintext' => [
               'Bootgly' => [1.2, 0.8],
               'Swoole' => [1.9, 1.1],
            ],
         ],
         'marks' => ['storage/tests/benchmarks/HTTP_Server_CLI/x-r01_bench.marks'],
      ];

      $dir = sys_get_temp_dir() . '/bootgly-report-' . getmypid();
      $Report = new Report(charts: true);
      $written = $Report->save($dir, $run);

      yield new Assertion(
         description: 'Report .md plus throughput/ratio/latency SVGs are written',
         fallback: 'Artifact set wrong!'
      )
         ->expect(count($written), Op::Identical, 4)
         ->assert();

      $markdown = (string) file_get_contents("{$dir}/{$written[0]}");

      yield new Assertion(
         description: 'Markdown carries tables, peaks, marks and chart references',
         fallback: 'Markdown content missing sections!'
      )
         ->expect(
            str_contains($markdown, '## Results')
               && str_contains($markdown, '| `server-workers` | Bootgly | Swoole |')
               && str_contains($markdown, '+25.0%')                    // 100k vs 80k
               && str_contains($markdown, '## Peaks')
               && str_contains($markdown, '200,000 @ 2')
               && str_contains($markdown, 'x-r01_bench.marks')
               && str_contains($markdown, '.chart.throughput.svg'),
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Every written SVG file is a valid SVG document',
         fallback: 'SVG artifact malformed!'
      )
         ->expect(
            array_all(
               array_slice($written, 1),
               static fn (string $file): bool
                  => str_starts_with((string) file_get_contents("{$dir}/{$file}"), '<svg')
            ),
            Op::Identical,
            true
         )
         ->assert();

      // # Single run (no sweep): charts are skipped, report still written
      $single = $run;
      $single['sweep'] = [];
      $single['data'] = ['Plaintext' => ['Bootgly' => [100000.0], 'Swoole' => [80000.0]]];
      $single['latencies'] = ['Plaintext' => ['Bootgly' => [1.2], 'Swoole' => [1.9]]];

      $written = new Report(charts: true)->save($dir, $single);

      yield new Assertion(
         description: 'Single run writes the report only (charts skipped)',
         fallback: 'Single-run chart skip broken!'
      )
         ->expect(count($written) === 1 && str_ends_with($written[0], '.md'), Op::Identical, true)
         ->assert();

      // ! Cleanup
      foreach (glob("{$dir}/*") ?: [] as $file) {
         unlink($file);
      }
      rmdir($dir);
   })
);
