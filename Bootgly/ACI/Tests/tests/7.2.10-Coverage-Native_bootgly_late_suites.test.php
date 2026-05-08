<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — Native reports executable denominators for late bootgly suites',

   test: new Assertions(Case: function (): Generator {
      if (! function_exists('proc_open')) {
         yield true;
         return;
      }

      foreach ([14, 15, 16, 17, 18, 19, 20, 21] as $suite) {
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
               (string) $suite,
               '--coverage-driver=native',
               '--coverage-report=text',
            ],
            $descriptors,
            $pipes,
            BOOTGLY_ROOT_DIR
         );

         if (! is_resource($process)) {
            throw new RuntimeException("Could not run Native coverage suite {$suite} probe.");
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

         yield (new Assertion(description: "suite {$suite} Native coverage exits cleanly"))
            ->expect($code)
            ->to->be(0)
            ->assert();

         yield (new Assertion(description: "suite {$suite} Native coverage has executable denominator"))
            ->expect($denominator > 0)
            ->to->be(true)
            ->assert();
      }
   })
);
