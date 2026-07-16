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
      // ! `from` is a core INI_ALL directive that can carry credentials (FTP
      //   anonymous identity) and stays settable in CLI regardless of output
      //   state — session.* directives reject ini_set() after headers are sent.
      $marker = 'bootgly-runtime-secret-' . bin2hex(random_bytes(12));
      $previous = ini_get('from');
      $changed = ini_set('from', $marker);

      try {
         $raw = Runtime::inspect();
         $export = Runtime::export();
         $replay = Runtime::replay();
         $JSON = json_encode($export, JSON_THROW_ON_ERROR);
      }
      finally {
         if (is_string($previous)) {
            ini_set('from', $previous);
         }
      }

      yield new Assertion(
         description: 'Raw runtime comparison sees the marker but manifest export omits it',
         fallback: 'Runtime manifest export persisted a non-allowlisted directive!'
      )
         ->expect(
            $changed !== false
               && ($raw['directives']['from']['local_value'] ?? null) === $marker
               && !str_contains($JSON, $marker)
               && !array_key_exists('from', $export['directives'])
               && array_key_exists('memory_limit', $export['directives'])
               && ($export['directives_policy']['schema'] ?? null) === 'performance-allowlist/v1'
               && ($export['directives_policy']['omitted'] ?? 0) > 0,
            Op::Identical,
            true
         )
         ->assert();

      $ini = php_ini_loaded_file();
      $scanned = php_ini_scanned_files();
      $hasScanned = is_string($scanned) && trim($scanned) !== '';
      $noINI = in_array('-n', $replay, true);
      $position = array_search('-c', $replay, true);

      yield new Assertion(
         description: 'Runtime replay preserves scanned INI fragments when no main file is loaded',
         fallback: 'Runtime replay disabled active scanned configuration or lost the main INI file!'
      )
         ->expect(
            $ini === false
               ? ($noINI === !$hasScanned)
               : (
                  $noINI === false
                  && is_int($position)
                  && ($replay[$position + 1] ?? null) === $ini
               ),
            Op::Identical,
            true
         )
         ->assert();
   })
);
