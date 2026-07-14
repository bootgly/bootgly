<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Provenance;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should sanitize packaged-source provenance fallbacks',
   test: new Assertions(Case: function (): Generator
   {
      $missing = sys_get_temp_dir() . '/bootgly-provenance-missing-' . getmypid();
      $emptySHA = hash('sha256', '');

      $fallback = Provenance::inspect(
         prefix: 'framework',
         path: $missing,
         SHAFallback: str_repeat('A', 40),
         dirtyFallback: 'clean',
         trackedFallback: $emptySHA,
         untrackedFallback: $emptySHA,
      );

      $invalid = Provenance::inspect(
         prefix: 'benchmarks',
         path: $missing,
         SHAFallback: "invalid\n# injected: value",
         dirtyFallback: 'maybe',
         trackedFallback: "invalid\n# injected: tracked",
         untrackedFallback: 'not-a-sha256',
      );

      $partial = Provenance::inspect(
         'framework',
         $missing,
         SHAFallback: str_repeat('a', 40),
         dirtyFallback: 'true',
         trackedFallback: str_repeat('d', 64),
         untrackedFallback: 'invalid',
      );
      $orphaned = Provenance::inspect(
         'framework',
         $missing,
         SHAFallback: 'unknown',
         dirtyFallback: 'true',
         trackedFallback: str_repeat('d', 64),
         untrackedFallback: str_repeat('e', 64),
      );

      $names = [
         'BOOTGLY_FRAMEWORK_SHA' => str_repeat('b', 40),
         'BOOTGLY_FRAMEWORK_DIRTY' => '1',
         'BOOTGLY_FRAMEWORK_TRACKED_DIFF_SHA256' => str_repeat('d', 64),
         'BOOTGLY_FRAMEWORK_UNTRACKED_MANIFEST_SHA256' => str_repeat('e', 64),
         'BOOTGLY_BENCHMARKS_SHA' => str_repeat('c', 40),
         'BOOTGLY_BENCHMARKS_DIRTY' => '0',
         'BOOTGLY_BENCHMARKS_TRACKED_DIFF_SHA256' => $emptySHA,
         'BOOTGLY_BENCHMARKS_UNTRACKED_MANIFEST_SHA256' => $emptySHA,
      ];
      $previous = [];
      foreach ($names as $name => $value) {
         $previous[$name] = getenv($name);
         putenv("{$name}={$value}");
      }
      $collected = Provenance::collect($missing, $missing);
      $incomplete = $collected;
      $incomplete['framework-tracked-diff-sha256'] = 'unknown';
      $contradictory = $collected;
      $contradictory['framework-dirty'] = 'false';
      $indexOnly = $collected;
      $indexOnly['framework-tracked-diff-sha256'] = $emptySHA;
      $indexOnly['framework-untracked-manifest-sha256'] = $emptySHA;
      $changed = $collected;
      $changed['framework-tracked-diff-sha256'] = str_repeat('9', 64);
      foreach ($previous as $name => $value) {
         putenv($value === false ? $name : "{$name}={$value}");
      }

      yield new Assertion(
         description: 'Valid fallbacks are normalized for packaged sources',
         fallback: 'Packaged provenance fallback was not normalized!'
      )
         ->expect($fallback, Op::Equal, [
            'framework-sha' => str_repeat('a', 40),
            'framework-dirty' => 'false',
            'framework-tracked-diff-sha256' => $emptySHA,
            'framework-untracked-manifest-sha256' => $emptySHA,
         ])
         ->assert();

      yield new Assertion(
         description: 'Fallback identity tuples fail closed when incomplete',
         fallback: 'Partial or orphaned packaged identity was accepted!'
      )
         ->expect(
            $partial['framework-tracked-diff-sha256'] === 'unknown'
               && $partial['framework-untracked-manifest-sha256'] === 'unknown'
               && $orphaned['framework-tracked-diff-sha256'] === 'unknown'
               && $orphaned['framework-untracked-manifest-sha256'] === 'unknown',
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Invalid fallbacks become explicit unknown values',
         fallback: 'Invalid provenance reached the marks metadata!'
      )
         ->expect($invalid, Op::Equal, [
            'benchmarks-sha' => 'unknown',
            'benchmarks-dirty' => 'unknown',
            'benchmarks-tracked-diff-sha256' => 'unknown',
            'benchmarks-untracked-manifest-sha256' => 'unknown',
         ])
         ->assert();

      yield new Assertion(
         description: 'Collect reads the documented container environment',
         fallback: 'Container provenance environment was not collected!'
      )
         ->expect($collected, Op::Equal, [
            'source-identity-version' => 'raw-delta-manifest-v1',
            'framework-sha' => str_repeat('b', 40),
            'framework-dirty' => 'true',
            'framework-tracked-diff-sha256' => str_repeat('d', 64),
            'framework-untracked-manifest-sha256' => str_repeat('e', 64),
            'benchmarks-sha' => str_repeat('c', 40),
            'benchmarks-dirty' => 'false',
            'benchmarks-tracked-diff-sha256' => $emptySHA,
            'benchmarks-untracked-manifest-sha256' => $emptySHA,
         ])
         ->assert();

      yield new Assertion(
         description: 'Only complete two-repository identities validate',
         fallback: 'Benchmark provenance completeness gate is incorrect!'
      )
         ->expect(
            Provenance::validate($collected)
               && Provenance::validate($incomplete) === false
               && Provenance::validate($contradictory) === false
               && Provenance::validate($indexOnly)
               && Provenance::confirm($collected, $collected)
               && Provenance::confirm($collected, $changed) === false
               && Provenance::confirm($collected, $incomplete) === false,
            Op::Identical,
            true
         )
         ->assert();
   })
);
