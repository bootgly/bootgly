<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Coverage — Native fails clearly for an unknown bootgly suite index',

   test: new Assertions(Case: function (): Generator {
      if (! function_exists('proc_open')) {
         yield true;
         return;
      }

      // ! The probe parses the HUMAN text coverage report, so its child must
      //   not run as an agent — an agent-driven child emits the JSON results
      //   document instead. Today this only holds because the outer wrapper
      //   already exported BOOTGLY_AGENT_STDOUT_REDIRECTED into the harness;
      //   scrub the markers so the probe states its own requirement.
      $environment = getenv();
      foreach ([
         'AI_AGENT', 'AMP_CURRENT_THREAD_ID', 'ANTIGRAVITY_AGENT',
         'AUGMENT_AGENT', 'CLAUDECODE', 'CLAUDE_CODE', 'CODEX_SANDBOX',
         'CODEX_THREAD_ID', 'COPILOT_CLI', 'CURSOR_AGENT', 'GEMINI_CLI',
         'OPENCODE', 'OPENCODE_CLIENT', 'REPL_ID',
      ] as $variable) {
         unset($environment[$variable]);
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
         BOOTGLY_ROOT_DIR,
         $environment
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
