<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


$PHP = PHP_BINARY;

return new Test(
   description: 'Waiters: a waited callable that dies fails the assertion and leaves no second process',
   skip: DIRECTORY_SEPARATOR === '\\'
      || function_exists('shell_exec') === false
      || function_exists('pcntl_fork') === false
      || function_exists('posix_kill') === false,
   test: function () use ($PHP) {
      // ! The waiter forks. Reproducing the defect means letting the runaway
      //   child exist, so the whole probe runs in its own process — confined
      //   there it can only duplicate that script, never this test run.
      $directory = Temporaries::reserve('waiter-runaway');
      $script = __DIR__ . '/waiter-runaway.php';

      $output = @shell_exec(
         escapeshellarg($PHP)
            . ' -r ' . escapeshellarg('require $_SERVER["argv"][1] ?? "";')
            . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($directory)
            . ' 2>/dev/null'
      );

      @unlink("{$directory}/witness");
      @rmdir($directory);

      $lines = array_values(array_filter(explode("\n", trim((string) $output))));
      $observed = json_decode((string) end($lines), true);

      yield assert(
         assertion: is_array($observed)
            && isset($observed['verdicts'], $observed['witnesses']),
         description: 'The waiter probe produced no readable result: '
            . var_export($output, true)
      );

      if (is_array($observed) === false || isset($observed['verdicts']) === false) {
         return;
      }

      $verdicts = $observed['verdicts'];

      // @ A Throwable from the waited callable used to be reported as PASSED:
      //   the child converted it to an AssertionError and threw it out of
      //   assert(), which never reaches the parent (TEXPF-1)
      yield assert(
         assertion: $verdicts['throwing']['verdict'] === 'failed',
         description: 'A waited callable that throws must fail the assertion: '
            . var_export($verdicts['throwing'], true)
      );
      yield assert(
         assertion: str_contains($verdicts['throwing']['reason'], 'RuntimeException')
            && str_contains($verdicts['throwing']['reason'], 'boom'),
         description: 'The failure must name what the callable actually threw, not a '
            . 'timeout the run never reached: ' . var_export($verdicts['throwing']['reason'], true)
      );

      // @ The same escape through the other catch: too few arguments
      yield assert(
         assertion: $verdicts['argcount']['verdict'] === 'failed',
         description: 'A waited callable called with too few arguments must fail: '
            . var_export($verdicts['argcount'], true)
      );
      yield assert(
         assertion: str_contains($verdicts['argcount']['reason'], 'ArgumentCountError'),
         description: 'The failure must name the ArgumentCountError: '
            . var_export($verdicts['argcount']['reason'], true)
      );

      // @ The forked child must die with its verdict, never survive to run the
      //   rest of the process a second time
      yield assert(
         assertion: count($observed['witnesses']) === 1,
         description: 'A failed waiter left ' . (count($observed['witnesses']) - 1)
            . ' runaway process(es) still executing: '
            . var_export($observed['witnesses'], true)
      );
      yield assert(
         assertion: ($observed['witnesses'][0] ?? null) === (string) $observed['origin'],
         description: 'The only process left must be the one that started: '
            . var_export($observed, true)
      );

      // @ Ordinary waiting is untouched
      yield assert(
         assertion: $verdicts['control']['verdict'] === 'passed',
         description: 'A callable returning inside its budget must still pass: '
            . var_export($verdicts['control'], true)
      );
      yield assert(
         assertion: $verdicts['arguments']['verdict'] === 'passed',
         description: 'Arguments configured through call() must still reach the callable: '
            . var_export($verdicts['arguments'], true)
      );
   }
);
