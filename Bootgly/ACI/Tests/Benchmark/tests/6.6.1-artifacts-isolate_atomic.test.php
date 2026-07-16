<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Artifacts;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Summary;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should isolate each invocation and atomically publish artifacts',
   test: new Assertions(Case: function (): Generator
   {
      $root = sys_get_temp_dir() . '/bootgly-artifacts-' . getmypid() . '-' . bin2hex(random_bytes(8));
      $ArtifactsA = Artifacts::create('Isolation', $root);
      $ArtifactsB = Artifacts::create('Isolation', $root);

      yield new Assertion(
         description: 'Back-to-back invocations claim distinct exclusive directories',
         fallback: 'Run workspace collision detected!'
      )
         ->expect(
            $ArtifactsA->ID !== $ArtifactsB->ID
               && $ArtifactsA->directory !== $ArtifactsB->directory
               && is_dir($ArtifactsA->directory)
               && is_dir($ArtifactsB->directory),
            Op::Identical,
            true
         )
         ->assert();

      $pathA = $ArtifactsA->write('marks/result.marks', str_repeat('A', 1024 * 1024));
      $pathB = $ArtifactsB->write('marks/result.marks', 'B');
      $collectedA = $ArtifactsA->collect();
      $collectedB = $ArtifactsB->collect();

      yield new Assertion(
         description: 'Identically named files remain owned by their respective invocation',
         fallback: 'An invocation collected another run\'s artifact!'
      )
         ->expect(
            $pathA !== $pathB
               && $collectedA === [$pathA]
               && $collectedB === [$pathB]
               && file_get_contents($ArtifactsA->resolve('marks/result.marks')) === str_repeat('A', 1024 * 1024)
               && file_get_contents($ArtifactsB->resolve('marks/result.marks')) === 'B',
            Op::Identical,
            true
         )
         ->assert();

      $temporaryFound = false;
      $Iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
      );
      foreach ($Iterator as $File) {
         if ($File->isFile() && str_ends_with($File->getFilename(), '.tmp')) {
            $temporaryFound = true;
            break;
         }
      }

      yield new Assertion(
         description: 'Successful atomic commits leave no temporary publication file',
         fallback: 'Temporary artifact leaked after commit!'
      )
         ->expect($temporaryFound, Op::Identical, false)
         ->assert();

      $latencySummary = [
         'count' => 1,
         'sum_ns' => 100000,
         'sum_overflow' => false,
         'min_ns' => 100000,
         'p50_ns' => 100000,
         'p95_ns' => 100000,
         'p99_ns' => 100000,
         'p99_9_ns' => 100000,
         'max_ns' => 100000,
         'underflow' => 0,
         'overflow' => 0,
         'fidelity' => true,
      ];
      $latencyHistogram = [
         'schema' => 'bootgly.latency-hdr.v1',
         'sparse_counts' => [['index' => 1, 'count' => 1]],
      ];
      $timeSeries = [
         'schema' => 'bootgly.time-series.v1',
         'buckets' => [['second' => 0, 'responses' => 1]],
      ];
      $Result = new Result(
         time: '0.1',
         censored: 1,
         writeCensored: 2,
         censors: ['measurement_ended' => 1],
         writeCensors: ['measurement_ended' => 2],
         latencySummary: $latencySummary,
         latencyHistogram: $latencyHistogram,
         timeSeries: $timeSeries,
      );
      $marks = Summary::save(
         caseName: 'Isolation',
         results: [
            'Opponent' => [
               'default' => $Result,
               'same/name' => $Result,
               'same name' => $Result,
            ],
         ],
         config: ['connections' => 64],
         suffix: 'r01',
         Artifacts: $ArtifactsA,
      );
      $marksContents = file_get_contents($ArtifactsA->resolve('marks/r01_bench.marks'));
      $identity = hash(
         'sha256',
         json_encode(['Opponent', 'default'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
      );
      $telemetryRelative = "telemetry/r01/Opponent--default--{$identity}.json";
      $telemetryPath = $ArtifactsA->relate($telemetryRelative);
      $telemetryFile = $ArtifactsA->resolve($telemetryRelative);
      $telemetry = is_file($telemetryFile)
         ? json_decode((string) file_get_contents($telemetryFile), true)
         : null;
      $collisionIdentityA = hash(
         'sha256',
         json_encode(
            ['Opponent', 'same/name'],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
         )
      );
      $collisionIdentityB = hash(
         'sha256',
         json_encode(
            ['Opponent', 'same name'],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
         )
      );
      $collisionA = $ArtifactsA->resolve(
         "telemetry/r01/Opponent--same-name--{$collisionIdentityA}.json"
      );
      $collisionB = $ArtifactsA->resolve(
         "telemetry/r01/Opponent--same-name--{$collisionIdentityB}.json"
      );

      yield new Assertion(
         description: 'Run identity stays outside experimental Config and a load cannot replace the marks label',
         fallback: 'Marks identity, Config, or filename semantics are invalid!'
      )
         ->expect(
            str_ends_with($marks, '/marks/r01_bench.marks')
               && is_string($marksContents)
               && str_contains($marksContents, "# Run ID: {$ArtifactsA->ID}\n")
               && str_contains($marksContents, "# Run Directory: {$ArtifactsA->relativeDirectory}\n")
               && str_contains($marksContents, "# Config:\n#   connections: 64\n")
               && !str_contains($marksContents, '#   run-id:')
               && !str_contains($marksContents, '#   run-directory:'),
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Per-result telemetry is schema-versioned, collision-safe and linked from marks',
         fallback: 'Telemetry sidecar or its marks reference is invalid!'
      )
         ->expect(
            is_string($marksContents)
               && str_contains($marksContents, " telemetry={$telemetryPath}\n")
               && str_contains($telemetryRelative, $identity)
               && $collisionA !== $collisionB
               && is_file($collisionA)
               && is_file($collisionB)
               && $telemetry === [
                  'schema' => 'bootgly.benchmark-result-telemetry/v1',
                  'case' => 'Isolation',
                  'round' => 'r01',
                  'opponent' => 'Opponent',
                  'load' => 'default',
                  'censored' => 1,
                  'write_censored' => 2,
                  'censors' => ['measurement_ended' => 1],
                  'write_censors' => ['measurement_ended' => 2],
                  'latency_summary' => $latencySummary,
                  'latency_histogram' => $latencyHistogram,
                  'time_series' => $timeSeries,
               ],
            Op::Identical,
            true
         )
         ->assert();

      $rejected = false;
      try {
         $ArtifactsA->resolve('../escape.txt');
      }
      catch (RuntimeException) {
         $rejected = true;
      }

      yield new Assertion(
         description: 'Run-relative paths cannot escape their invocation directory',
         fallback: 'Artifact path traversal accepted!'
      )
         ->expect($rejected, Op::Identical, true)
         ->assert();

      $Reopened = Artifacts::open($ArtifactsA->ID, $ArtifactsA->directory);
      yield new Assertion(
         description: 'A supervised child can reopen only the supplied run identity',
         fallback: 'Run workspace could not be safely reopened!'
      )
         ->expect(
            $Reopened->ID === $ArtifactsA->ID && $Reopened->directory === $ArtifactsA->directory,
            Op::Identical,
            true
         )
         ->assert();

      $Cleanup = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($Cleanup as $Entry) {
         $Entry->isDir() ? rmdir($Entry->getPathname()) : unlink($Entry->getPathname());
      }
      rmdir($root);
   })
);
