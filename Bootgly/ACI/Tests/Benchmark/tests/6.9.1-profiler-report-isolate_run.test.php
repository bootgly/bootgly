<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should aggregate profiles only from the selected run, round and scope',
   test: new Assertions(Case: function (): Generator
   {
      $root = sys_get_temp_dir() . '/bootgly-profile-report-' . getmypid() . '-' . bin2hex(random_bytes(8));
      $selected = "{$root}/run-selected/profiles/server/round-r01/scope-bootgly";
      $wrongScope = "{$root}/run-selected/profiles/server/round-r01/scope-poison";
      $sibling = "{$root}/run-sibling/profiles/server/round-r01/scope-bootgly";
      foreach ([$selected, $wrongScope, $sibling] as $directory) {
         if (!mkdir($directory, 0775, true)) {
            throw new RuntimeException("Can not create profiler proof directory: {$directory}");
         }
      }

      file_put_contents("{$selected}/worker-101.collapsed", "selected_function 7\n");
      file_put_contents("{$wrongScope}/worker-102.collapsed", "poison_scope 1000\n");
      file_put_contents("{$sibling}/worker-103.collapsed", "poison_sibling 1000\n");

      $pipes = [];
      $Process = proc_open(
         [
            PHP_BINARY,
            BOOTGLY_ROOT_DIR . 'scripts/profile-report.php',
            '--run-dir=' . "{$root}/run-selected",
            '--round=r01',
            '--profile-scope=bootgly',
            '--full',
         ],
         [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ],
         $pipes,
         BOOTGLY_ROOT_DIR,
         null,
         ['bypass_shell' => true],
      );
      if (!is_resource($Process)) {
         throw new RuntimeException('Can not start the run-scoped profiler report proof');
      }

      $STDOUT = stream_get_contents($pipes[1]);
      $STDERR = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $exit = proc_close($Process);
      $STDOUT = is_string($STDOUT) ? $STDOUT : '';
      $STDERR = is_string($STDERR) ? $STDERR : '';

      yield new Assertion(
         description: 'Sibling runs and non-selected profile scopes cannot enter aggregation',
         fallback: 'Profiler report consumed unrelated or ambiguously selected output!'
      )
         ->expect(
            $exit === 0
               && $STDERR === ''
               && str_contains($STDOUT, 'selected_function')
               && str_contains($STDOUT, 'Total samples    : 7')
               && !str_contains($STDOUT, 'poison_scope')
               && !str_contains($STDOUT, 'poison_sibling'),
            Op::Identical,
            true,
         )
         ->assert();

      $Cleanup = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($Cleanup as $Entry) {
         $Entry->isDir() ? rmdir($Entry->getPathname()) : unlink($Entry->getPathname());
      }
      rmdir($root);
   })
);
