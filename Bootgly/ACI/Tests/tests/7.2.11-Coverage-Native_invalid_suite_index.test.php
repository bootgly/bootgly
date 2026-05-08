<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — Native fails clearly for an unknown bootgly suite index',

   test: new Assertions(Case: function (): Generator {
      if (! function_exists('proc_open')) {
         yield true;
         return;
      }

      $descriptors = [
         1 => ['pipe', 'w'],
         2 => ['pipe', 'w'],
      ];

      $process = proc_open(
         [
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            BOOTGLY_ROOT_DIR . 'bootgly',
            'test',
            '999',
            '--coverage-driver=native',
            '--coverage-report=text',
         ],
         $descriptors,
         $pipes,
         BOOTGLY_ROOT_DIR
      );

      if (! is_resource($process)) {
         throw new RuntimeException('Could not run Native coverage invalid suite probe.');
      }

      /** @var array<int, resource> $pipes */
      $output = stream_get_contents($pipes[1]);
      $error = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $code = proc_close($process);

      $output = ($output !== false ? $output : '') . ($error !== false ? $error : '');

      yield (new Assertion(description: 'unknown suite exits with failure'))
         ->expect($code !== 0)
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'unknown suite explains that the index does not exist'))
         ->expect(str_contains($output, 'Test suite index 999 was not loaded or does not exist.'))
         ->to->be(true)
         ->assert();
   })
);
