<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * A supervised `--format=json` sweep keeps its memory flat across rounds.
 *
 * Each round streams its raw telemetry — the sparse latency histogram and the
 * one-second series — to the run's telemetry sidecars. Only the per-round
 * aggregates stay in memory for the report, the charts and the JSON document.
 * Retaining every round's raw histograms (≈ 400 bytes per sparse bin) grew the
 * isolated harness by megabytes per round, and decoding a document that
 * embedded all of them exhausted the limit at the end: a 24-round TechEmpower
 * sweep died in round 17 under `memory_limit=256M`.
 *
 * The fixture case returns four results per round with 4,000-bin histograms
 * (≈ 6 MiB of PHP arrays), probes the heap as every round starts and runs the
 * real command (JSON supervisor + isolated inner harness) under 64M.
 */
return new Test(
   description: 'It should retain only per-round aggregates across a sweep',
   test: new Assertions(Case: function (): Generator
   {
      // ! Fixture — a working base whose `benchmarks/` holds one synthetic case
      $rounds = 8;
      $bins = 4_000;
      $directory = Temporaries::reserve('benchmark-retention');
      $case = "{$directory}/benchmarks/Retention_Sweep";
      $root = BOOTGLY_ROOT_DIR;
      $files = [
         "{$directory}/bootgly" => "<?php\n"
            . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
            . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
            . "(include '{$root}autoboot.php') || exit(1);\n",
         "{$case}/options.php" => "<?php\n\n"
            . "return [\n"
            . "   'server-workers' => [\n"
            . "      'type' => 'int',\n"
            . "      'default' => null,\n"
            . "      'vary' => true,\n"
            . "      'description' => 'Round marker',\n"
            . "   ],\n"
            . "];\n",
         "{$case}/autoboot.php" => <<<PHP
         <?php

         use Bootgly\ACI\Tests\Benchmark\Configs;
         use Bootgly\ACI\Tests\Benchmark\Opponent;
         use Bootgly\ACI\Tests\Benchmark\Result;
         use Bootgly\ACI\Tests\Benchmark\Runner;


         \$Runner = new class extends Runner
         {
            public protected(set) string \$name = 'retention';

            public function configure (array \$options): void {}

            public function run (Configs \$Configs): array
            {
               // # Heap held by the isolated harness as this round starts
               file_put_contents(__DIR__ . '/memory.log', memory_get_usage() . "\\n", FILE_APPEND);

               \$round = (int) \$this->opponents[0]->workers;
               \$results = [];
               foreach (\$this->opponents as \$Opponent) {
                  foreach (['Small load', 'Large load'] as \$label) {
                     \$sparse = [];
                     for (\$index = 0; \$index < {$bins}; \$index++) {
                        \$sparse[] = ['index' => \$index, 'count' => 1];
                     }

                     \$results[\$Opponent->name][\$label] = new Result(
                        rps: 1000.0 * \$round,
                        latency: number_format(1 + \$round / 10, 2) . 'ms',
                        transfer: '1.00MB/s',
                        accounting: true,
                        latencySummary: ['count' => {$bins}, 'p50_ns' => 1000000, 'fidelity' => true],
                        latencyHistogram: ['schema' => 'bootgly.latency-hdr.v1', 'sparse_counts' => \$sparse],
                        timeSeries: ['schema' => 'bootgly.time-series.v1', 'buckets' => [['second' => 0]]],
                     );
                  }
               }

               return \$results;
            }
         };
         \$Runner->add(new Opponent(name: 'Alpha', script: __FILE__));
         \$Runner->add(new Opponent(name: 'Beta', script: __FILE__));

         return \$Runner;

         PHP,
      ];
      $empty = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
      $environment = getenv();
      // ! A stale internal marker must not bypass the supervisor under test
      unset(
         $environment['BENCHMARK_JSON_INNER'],
         $environment['BENCHMARK_RUN_ID'],
         $environment['BENCHMARK_RUN_DIR'],
         $environment['BENCHMARK_JSON_TOKEN'],
         $environment['BENCHMARK_JSON_RESULT'],
         $environment['BENCHMARK_RUNTIME_FINGERPRINT'],
      );
      // ! The fixture case is no Git checkout: attribute it explicitly
      $environment['BOOTGLY_BENCHMARKS_SHA'] = str_repeat('c', 40);
      $environment['BOOTGLY_BENCHMARKS_DIRTY'] = '0';
      $environment['BOOTGLY_BENCHMARKS_TRACKED_DIFF_SHA256'] = $empty;
      $environment['BOOTGLY_BENCHMARKS_UNTRACKED_MANIFEST_SHA256'] = $empty;

      $erase = function (string $target) use (&$erase): void {
         if (is_link($target) || is_file($target)) {
            unlink($target);
            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      $exit = null;
      $STDOUT = '';
      $STDERR = '';
      $Document = [];
      $heap = [];
      $sidecars = 0;
      $complete = 0;
      try {
         mkdir($case, 0o700, true);
         foreach ($files as $file => $contents) {
            file_put_contents($file, $contents);
         }

         // @ The real command: JSON supervisor + isolated inner harness
         $pipes = [];
         $Process = proc_open(
            [
               PHP_BINARY,
               '-d',
               'memory_limit=64M',
               "{$directory}/bootgly",
               'test',
               'benchmark',
               'Retention_Sweep',
               '--opponents=alpha,beta',
               '--loads=default:*',
               '--format=json',
               '--results=charts',
               "--server-workers=1..{$rounds}",
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
            $environment,
            ['bypass_shell' => true],
         );
         if (is_resource($Process) === false) {
            throw new RuntimeException('Can not start the benchmark retention probe');
         }
         $STDOUT = (string) stream_get_contents($pipes[1]);
         $STDERR = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $exit = proc_close($Process);

         $decoded = json_decode($STDOUT, true);
         $Document = is_array($decoded) ? $decoded : [];
         $run = is_array($Document['run'] ?? null) ? $Document['run'] : [];
         $runPath = isset($run['path_base'], $run['directory'])
            ? "{$run['path_base']}/{$run['directory']}"
            : null;

         // ! Heap at each round start (one line per round)
         $lines = is_file("{$case}/memory.log")
            ? file("{$case}/memory.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            : false;
         $heap = is_array($lines) ? array_map('intval', $lines) : [];

         // ! Every telemetry sidecar must keep its complete raw distribution
         $paths = is_string($runPath) ? (glob("{$runPath}/telemetry/r*/*.json") ?: []) : [];
         $sidecars = count($paths);
         foreach ($paths as $path) {
            $telemetry = json_decode((string) file_get_contents($path), true);
            if (
               is_array($telemetry)
               && count($telemetry['latency_histogram']['sparse_counts'] ?? []) === $bins
               && ($telemetry['time_series']['schema'] ?? null) === 'bootgly.time-series.v1'
            ) {
               $complete++;
            }
         }
      }
      finally {
         $erase($directory);
      }

      // ? Measured from round 1, before anything is retained: a round whose
      //   raw results outlive it shows up as a full round of data by round 2,
      //   and every later round may add only its aggregates.
      $growth = count($heap) === $rounds ? $heap[$rounds - 1] - $heap[0] : null;

      // ! Every serialized result, tagged with its expected round throughput
      $cells = [];
      foreach (is_array($Document['rounds'] ?? null) ? $Document['rounds'] : [] as $index => $round) {
         foreach (is_array($round['results'] ?? null) ? $round['results'] : [] as $loads) {
            foreach (is_array($loads) ? $loads : [] as $cell) {
               $cells[] = ['expected' => 1000 * ((int) $index + 1), 'cell' => $cell];
            }
         }
      }
      $artifacts = is_array($Document['artifacts'] ?? null) ? $Document['artifacts'] : [];

      yield new Assertion(
         description: 'The supervised sweep completes every round under a 64M memory limit',
         fallback: "Retention sweep failed (exit {$exit}): " . substr($STDOUT . $STDERR, 0, 400)
      )
         ->expect(
            $exit === 0
               && $STDERR === ''
               && isset($Document['error']) === false
               && count($Document['rounds'] ?? []) === $rounds
               && count($cells) === $rounds * 4,
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'The isolated harness heap stays flat from round to round',
         fallback: 'Sweep rounds retained raw telemetry: the heap grew by '
            . ($growth === null ? 'an unknown amount' : number_format($growth) . ' bytes')
      )
         ->expect($growth !== null && $growth < 2 * 1024 * 1024, Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'Raw telemetry lives in the sidecars, aggregates in the JSON document',
         fallback: 'Raw telemetry was lost or serialized into the JSON document!'
      )
         ->expect(
            $sidecars === $rounds * 4
               && $complete === $rounds * 4
               && array_all(
                  $cells,
                  static fn (array $entry): bool => is_array($entry['cell'])
                     && array_key_exists('latency_histogram', $entry['cell']) === false
                     && array_key_exists('time_series', $entry['cell']) === false
                     && ($entry['cell']['latency_summary']['count'] ?? null) === $bins
                     && is_numeric($entry['cell']['rps'] ?? null)
                     && (float) $entry['cell']['rps'] === (float) $entry['expected']
               ),
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'The report and its charts are still generated from the aggregates',
         fallback: 'Sweep report or charts are missing!'
      )
         ->expect(
            array_any($artifacts, static fn (mixed $file): bool => is_string($file) && str_ends_with($file, '.svg'))
               && array_any($artifacts, static fn (mixed $file): bool => is_string($file) && str_ends_with($file, '.md')),
            Op::Identical,
            true
         )
         ->assert();
   })
);
