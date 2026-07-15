<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Artifacts;
use Bootgly\ACI\Tests\Benchmark\Child;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should capture child stdout and stderr as separate run artifacts',
   test: new Assertions(Case: function (): Generator
   {
      $root = sys_get_temp_dir() . '/bootgly-child-' . getmypid() . '-' . bin2hex(random_bytes(8));
      $Artifacts = Artifacts::create('Child', $root);
      $Child = new Child($Artifacts);
      $result = $Child->run([
         PHP_BINARY,
         '-r',
         'fwrite(STDOUT, str_repeat("O", 1048576));'
            . 'fwrite(STDERR, str_repeat("E", 1048576));'
            . 'exit(17);',
      ], 'large-output');

      $stdout = (string) file_get_contents($Artifacts->directory . '/' . substr($result['stdout'], strlen($Artifacts->relativeDirectory) + 1));
      $stderr = (string) file_get_contents($Artifacts->directory . '/' . substr($result['stderr'], strlen($Artifacts->relativeDirectory) + 1));
      $status = json_decode(
         (string) file_get_contents($Artifacts->directory . '/' . substr($result['status'], strlen($Artifacts->relativeDirectory) + 1)),
         true,
         flags: JSON_THROW_ON_ERROR
      );
      $descendantResult = $Child->run([
         PHP_BINARY,
         '-r',
         '$PID = pcntl_fork();'
            . 'if ($PID === 0) { usleep(250000); fwrite(STDOUT, "natural-late"); exit(0); }'
            . 'exit(0);',
      ], 'natural-descendant');
      $descendantOutput = $Artifacts->directory . '/'
         . substr($descendantResult['stdout'], strlen($Artifacts->relativeDirectory) + 1);
      $descendantBytes = filesize($descendantOutput);
      usleep(300_000);
      clearstatcache(true, $descendantOutput);
      $timeoutResult = $Child->run([
         PHP_BINARY,
         '-r',
         'pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN);'
            . '$PID = pcntl_fork();'
            . 'if ($PID === 0) { usleep(800000); fwrite(STDOUT, "forbidden-late"); exit(0); }'
            . 'fwrite(STDOUT, "timeout-proof"); fwrite(STDERR, "descendant={$PID}\n");'
            . 'usleep(5000000);',
      ], 'timeout-output', timeout: 0.1, grace: 0.1);
      $timeoutStatus = json_decode(
         (string) file_get_contents(
            $Artifacts->directory . '/'
            . substr($timeoutResult['status'], strlen($Artifacts->relativeDirectory) + 1)
         ),
         true,
         flags: JSON_THROW_ON_ERROR
      );
      $timeoutOutput = $Artifacts->directory . '/'
         . substr($timeoutResult['stdout'], strlen($Artifacts->relativeDirectory) + 1);
      $timeoutError = $Artifacts->directory . '/'
         . substr($timeoutResult['stderr'], strlen($Artifacts->relativeDirectory) + 1);
      preg_match('/descendant=(\d+)/', (string) file_get_contents($timeoutError), $matches);
      $descendantPID = isset($matches[1]) ? (int) $matches[1] : 0;
      $timeoutBytes = filesize($timeoutOutput);
      usleep(900_000);
      clearstatcache(true, $timeoutOutput);

      $childrenBeforeInvalid = count(glob($Artifacts->directory . '/children/*') ?: []);
      $invalidRejected = false;
      try {
         $Child->run([PHP_BINARY, '-r', 'exit(0);'], 'invalid-timeout', timeout: -1.0);
      }
      catch (InvalidArgumentException) {
         $invalidRejected = true;
      }
      $childrenAfterInvalid = count(glob($Artifacts->directory . '/children/*') ?: []);

      yield new Assertion(
         description: 'Large channels complete without pipe deadlock and retain the child exit code',
         fallback: 'Child process capture did not complete exactly!'
      )
         ->expect(
            $result['exit'] === 17
               && strlen($stdout) === 1048576
               && strlen($stderr) === 1048576
               && $stdout === str_repeat('O', 1048576)
               && $stderr === str_repeat('E', 1048576),
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Natural descendant output is joined before publication',
         fallback: 'Child output was published while a descendant could still mutate it!'
      )
         ->expect(
            $descendantResult['exit'] === 0
               && file_get_contents($descendantOutput) === 'natural-late'
               && filesize($descendantOutput) === $descendantBytes,
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Timeout escalation reaps the child and retains partial output',
         fallback: 'Child timeout was not enforced or lost its output evidence!'
      )
         ->expect(
            $timeoutResult['exit'] === 124
               && $timeoutStatus['state'] === 'killed'
               && $timeoutStatus['timed-out'] === true
               && ($timeoutStatus['isolation'] ?? null) === 'session-process-group'
               && $descendantPID > 0
               && !@posix_kill($descendantPID, 0)
               && file_get_contents($timeoutOutput) === 'timeout-proof'
               && filesize($timeoutOutput) === $timeoutBytes,
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Status manifest points to distinct stdout and stderr files',
         fallback: 'Child output channels were merged or status was lost!'
      )
         ->expect(
            $result['stdout'] !== $result['stderr']
               && $status['exit'] === 17
               && $status['state'] === 'completed'
               && $status['stdout'] === $result['stdout']
               && $status['stderr'] === $result['stderr']
               && $status['stdout-bytes'] === 1048576
               && $status['stderr-bytes'] === 1048576
               && $invalidRejected
               && $childrenAfterInvalid === $childrenBeforeInvalid,
            Op::Identical,
            true
         )
         ->assert();

      $Cleanup = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($Cleanup as $Entry) {
         $Entry->isDir() ? rmdir($Entry->getPathname()) : unlink($Entry->getPathname());
      }
      rmdir($root);
   })
);
