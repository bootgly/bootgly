<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — Native reports the Pipe suite through the bootgly CLI',

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
            '8',
            '--coverage-driver=native',
            '--coverage-report=text',
         ],
         $descriptors,
         $pipes,
         BOOTGLY_ROOT_DIR
      );

      if (! is_resource($process)) {
         throw new RuntimeException('Could not run Native coverage Pipe suite probe.');
      }

      /** @var array<int, resource> $pipes */
      $output = stream_get_contents($pipes[1]);
      $error = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $code = proc_close($process);

      $output = ($output !== false ? $output : '') . ($error !== false ? $error : '');
      $matched = preg_match('/TOTAL\s+\d+\/(\d+)\s+[0-9.]+%/', $output, $matches) === 1;
      $denominator = $matched ? (int) $matches[1] : 0;

      yield (new Assertion(description: 'nested bootgly Pipe coverage command exits cleanly'))
         ->expect($code)
         ->to->be(0)
         ->assert();

      yield (new Assertion(description: 'Pipe suite report includes the Pipe SUT'))
         ->expect(str_contains($output, 'Bootgly/ABI/IO/IPC/Pipe.php'))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'Pipe suite native coverage has executable denominator'))
         ->expect($denominator > 0)
         ->to->be(true)
         ->assert();
   })
);
