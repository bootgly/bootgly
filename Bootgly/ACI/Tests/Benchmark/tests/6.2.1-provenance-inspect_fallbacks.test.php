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

      $fallback = Provenance::inspect(
         prefix: 'framework',
         path: $missing,
         SHAFallback: str_repeat('A', 40),
         dirtyFallback: 'clean',
      );

      $invalid = Provenance::inspect(
         prefix: 'benchmarks',
         path: $missing,
         SHAFallback: "invalid\n# injected: value",
         dirtyFallback: 'maybe',
      );

      $names = [
         'BOOTGLY_FRAMEWORK_SHA' => str_repeat('b', 40),
         'BOOTGLY_FRAMEWORK_DIRTY' => '1',
         'BOOTGLY_BENCHMARKS_SHA' => str_repeat('c', 40),
         'BOOTGLY_BENCHMARKS_DIRTY' => '0',
      ];
      $previous = [];
      foreach ($names as $name => $value) {
         $previous[$name] = getenv($name);
         putenv("{$name}={$value}");
      }
      $collected = Provenance::collect($missing, $missing);
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
         ])
         ->assert();

      yield new Assertion(
         description: 'Invalid fallbacks become explicit unknown values',
         fallback: 'Invalid provenance reached the marks metadata!'
      )
         ->expect($invalid, Op::Equal, [
            'benchmarks-sha' => 'unknown',
            'benchmarks-dirty' => 'unknown',
         ])
         ->assert();

      yield new Assertion(
         description: 'Collect reads the documented container environment',
         fallback: 'Container provenance environment was not collected!'
      )
         ->expect($collected, Op::Equal, [
            'framework-sha' => str_repeat('b', 40),
            'framework-dirty' => 'true',
            'benchmarks-sha' => str_repeat('c', 40),
            'benchmarks-dirty' => 'false',
         ])
         ->assert();
   })
);
