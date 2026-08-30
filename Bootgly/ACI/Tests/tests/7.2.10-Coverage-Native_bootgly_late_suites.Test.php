<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment\Agent;


return new Test(
   description: 'Coverage — Native reports executable denominators for late bootgly suites',

   test: new Assertions(Case: function (): Generator {
      if (! function_exists('proc_open')) {
         yield true;
         return;
      }

      // ! The probe parses the HUMAN text coverage report, so its child must
      //   not run as an agent — an agent-driven child emits the JSON results
      //   document instead. Scrub the agent markers and the stdout-redirect
      //   flag an agent wrapper exports, so the probe states its own
      //   requirement instead of borrowing the harness environment.
      $environment = getenv();
      unset($environment['AI_AGENT'], $environment['BOOTGLY_AGENT_STDOUT_REDIRECTED']);
      foreach (Agent::MARKERS as $variables) {
         foreach ($variables as $variable) {
            unset($environment[$variable]);
         }
      }

      foreach ([15, 16, 17, 18, 19, 20, 21, 22] as $suite) {
         $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];

         $command = [
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            // ! The child measures executable code: its assertions must be
            //   compiled in even under a production INI. The runner would
            //   re-exec itself to the same effect; the explicit flag states
            //   the contract and saves a re-exec per suite.
            '-d',
            'zend.assertions=1',
            BOOTGLY_ROOT_DIR . 'bootgly',
            'test',
            (string) $suite,
            '--coverage-driver=native',
            '--coverage-report=text',
         ];

         $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            BOOTGLY_ROOT_DIR,
            $environment
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

         // ! Evidence — a bare `actual: 1, expected: 0` says nothing about a
         //   child that only misbehaves inside the harness. Carry the exact
         //   command and the tail of what it wrote into the failure message.
         $tail = trim(preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $output) ?? $output);
         if (strlen($tail) > 1200) {
            $tail = '…' . substr($tail, -1200);
         }
         $evidence = PHP_EOL
            . '   command: ' . implode(' ', $command) . PHP_EOL
            . '   exit: ' . $code . PHP_EOL
            . '   output: ' . ($tail === '' ? '(nothing)' : PHP_EOL . $tail);

         yield (new Assertion(description: "suite {$suite} Native coverage exits cleanly{$evidence}"))
            ->expect($code)
            ->to->be(0)
            ->assert();

         yield (new Assertion(description: "suite {$suite} Native coverage has executable denominator{$evidence}"))
            ->expect($denominator > 0)
            ->to->be(true)
            ->assert();
      }
   })
);
