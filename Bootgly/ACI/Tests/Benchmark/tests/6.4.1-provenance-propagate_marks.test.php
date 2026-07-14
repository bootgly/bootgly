<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Summary;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should propagate source fingerprints into marks metadata',
   test: new Assertions(Case: function (): Generator
   {
      $case = 'Provenance-' . getmypid() . '-' . bin2hex(random_bytes(4));
      $dir = BOOTGLY_STORAGE_DIR . "tests/benchmarks/{$case}";
      $trackedSHA = str_repeat('a', 64);
      $untrackedSHA = str_repeat('b', 64);
      $Result = new Result(rps: 1000.0, latency: '1ms', transfer: '1MB/s');

      Summary::save(
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

      $files = glob("{$dir}/*_bench.marks") ?: [];
      $marks = isset($files[0]) ? (string) file_get_contents($files[0]) : '';

      foreach ($files as $file) {
         unlink($file);
      }
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
   })
);
