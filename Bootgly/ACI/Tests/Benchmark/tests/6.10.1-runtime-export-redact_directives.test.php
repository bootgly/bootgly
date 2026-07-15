<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Runtime;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should persist only allowlisted runtime directives',
   test: new Assertions(Case: function (): Generator
   {
      $marker = 'bootgly-runtime-secret-' . bin2hex(random_bytes(12));
      $previous = ini_get('session.save_path');
      $changed = ini_set('session.save_path', $marker);

      try {
         $raw = Runtime::inspect();
         $export = Runtime::export();
         $JSON = json_encode($export, JSON_THROW_ON_ERROR);
      }
      finally {
         if (is_string($previous)) {
            ini_set('session.save_path', $previous);
         }
      }

      yield new Assertion(
         description: 'Raw runtime comparison sees the marker but manifest export omits it',
         fallback: 'Runtime manifest export persisted a non-allowlisted directive!'
      )
         ->expect(
            $changed !== false
               && ($raw['directives']['session.save_path']['local_value'] ?? null) === $marker
               && !str_contains($JSON, $marker)
               && !array_key_exists('session.save_path', $export['directives'])
               && array_key_exists('memory_limit', $export['directives'])
               && ($export['directives_policy']['schema'] ?? null) === 'performance-allowlist/v1'
               && ($export['directives_policy']['omitted'] ?? 0) > 0,
            Op::Identical,
            true
         )
         ->assert();
   })
);
