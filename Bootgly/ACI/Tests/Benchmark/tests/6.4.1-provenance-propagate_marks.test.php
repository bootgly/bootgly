<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Summary;
use Bootgly\ACI\Tests\Suite\Test\Specification;

use const BOOTGLY_WORKING_DIR;


return new Specification(
   description: 'It should propagate source fingerprints into marks metadata',
   test: new Assertions(Case: function (): Generator
   {
      $case = 'Provenance-' . getmypid() . '-' . bin2hex(random_bytes(4));
      $dir = BOOTGLY_STORAGE_DIR . "tests/benchmarks/{$case}";
      $trackedSHA = str_repeat('a', 64);
      $untrackedSHA = str_repeat('b', 64);
      $Result = new Result(
         rps: 1000.0,
         latency: '1ms',
         transfer: '1MB/s',
         scheduled: 1002,
         sent: 1001,
         responses: 1000,
         informational: 2,
         outstanding: 0,
         failed: 1,
         writeFailed: 1,
         connectionFailed: 0,
         partialWrites: 2,
         accounting: true,
         statuses: [200 => 1000],
         failures: ['measurement_ended' => 1],
         writeFailures: ['measurement_ended' => 1],
      );

      $relative = Summary::save(
         caseName: $case,
         results: ['Bootgly' => ['Plaintext' => $Result]],
         config: [
            'source-identity-version' => 'raw-delta-manifest-v1',
            'framework-sha' => str_repeat('c', 40),
            'framework-dirty' => 'true',
            'framework-tracked-diff-sha256' => $trackedSHA,
            'framework-untracked-manifest-sha256' => $untrackedSHA,
         ],
         suffix: 'identity',
      );

      $file = BOOTGLY_WORKING_DIR . $relative;
      $marks = is_file($file) ? (string) file_get_contents($file) : '';

      is_file($file) && unlink($file);
      if (is_dir($dir)) {
         rmdir($dir);
      }

      yield new Assertion(
         description: 'Marks header contains both one-line source fingerprints',
         fallback: 'Dirty-tree identity was lost before .marks serialization!'
      )
         ->expect(
            str_contains($marks, "#   framework-tracked-diff-sha256: {$trackedSHA}\n")
               && str_contains($marks, "#   framework-untracked-manifest-sha256: {$untrackedSHA}\n")
               && str_contains($marks, "#   source-identity-version: raw-delta-manifest-v1\n")
               && substr_count($marks, $trackedSHA) === 1
               && substr_count($marks, $untrackedSHA) === 1,
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Marks result preserves exact HTTP accounting evidence',
         fallback: 'HTTP accounting was lost before .marks serialization!'
      )
         ->expect(
            str_contains(
               $marks,
               ' scheduled=1002 sent=1001 responses=1000 informational=2 outstanding=0 failed=1'
                  . ' write_failed=1 connection_failed=0 partial_writes=2 accounting=valid statuses={"200":1000}'
                  . ' failures={"measurement_ended":1}'
                  . ' write_failures={"measurement_ended":1}'
            ),
            Op::Identical,
            true
         )
         ->assert();
   })
);
