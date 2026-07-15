<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Artifacts;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\ACI\Tests\Suite\Test\Specification;


$runnerFile = BOOTGLY_ROOT_DIR . '../bootgly_benchmarks/runners/Code.php';
if (is_file($runnerFile) === false) {
   return new Specification(
      description: 'It should enforce the Code runner timeout in JSON and text modes '
         . '(requires the optional bootgly_benchmarks sibling checkout)',
      skip: true,
      test: static function (): void {}
   );
}

return new Specification(
   description: 'It should enforce the Code runner timeout in JSON and text modes',
   test: new Assertions(Case: function () use ($runnerFile): Generator
   {
      $root = sys_get_temp_dir() . '/bootgly-code-timeout-' . bin2hex(random_bytes(12));
      mkdir($root, 0o755);
      $script = "{$root}/opponent.php";
      $scriptSource = '<?php' . "\n"
         . 'pcntl_async_signals(true);' . "\n"
         . 'pcntl_signal(SIGTERM, SIG_IGN);' . "\n"
         . '$format = getenv("BENCHMARK_FORMAT") ?: "unknown";' . "\n"
         . '$PID = pcntl_fork();' . "\n"
         . 'if ($PID === 0) {' . "\n"
         . '   usleep(3_500_000);' . "\n"
         . '   file_put_contents(' . var_export($root, true) . ' . "/late-{$format}", "late");' . "\n"
         . '   exit(0);' . "\n"
         . '}' . "\n"
         . 'file_put_contents(' . var_export($root, true) . ' . "/pid-{$format}", (string) $PID);' . "\n"
         . 'usleep(5_000_000);' . "\n";
      file_put_contents($script, $scriptSource);
      $Artifacts = Artifacts::create('CodeTimeout', "{$root}/runs");
      $Runner = include $runnerFile;
      $Runner->timeout = 1;
      $Runner->add(new Opponent('Timeout', $script));
      $Runner->bind($Artifacts);
      $previousFormat = getenv('BENCHMARK_FORMAT');
      $bufferLevel = ob_get_level();
      $invalidOptions = 0;

      foreach ([
         ['iterations' => 0],
         ['iterations' => true],
         ['iterations' => '1garbage'],
         ['timeout' => -1],
         ['timeout' => true],
         ['timeout' => '1garbage'],
         ['warmup' => -1],
         ['warmup' => true],
         ['warmup' => 'garbage'],
      ] as $invalid) {
         try {
            $Runner->configure($invalid);
         }
         catch (InvalidArgumentException) {
            $invalidOptions++;
         }
      }

      try {
         $JSONConfigs = Configs::parse([
            'opponents' => 'timeout',
            'loads' => 'default:*',
            'format' => 'json',
         ]);
         putenv('BENCHMARK_FORMAT=json');
         ob_start();
         $started = microtime(true);
         $JSONResults = $Runner->run($JSONConfigs);
         $JSONElapsed = microtime(true) - $started;
         ob_end_clean();

         $statusFiles = glob($Artifacts->directory . '/children/*/status.json');
         $status = is_array($statusFiles) && isset($statusFiles[0])
            ? json_decode((string) file_get_contents($statusFiles[0]), true, flags: JSON_THROW_ON_ERROR)
            : null;

         $TextConfigs = Configs::parse([
            'opponents' => 'timeout',
            'loads' => 'default:*',
            'format' => 'text',
         ]);
         putenv('BENCHMARK_FORMAT=text');
         ob_start();
         $started = microtime(true);
         $TextResults = $Runner->run($TextConfigs);
         $textElapsed = microtime(true) - $started;
         ob_end_clean();
         usleep(600_000);
      }
      finally {
         while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
         }
         $previousFormat === false
            ? putenv('BENCHMARK_FORMAT')
            : putenv("BENCHMARK_FORMAT={$previousFormat}");
      }

      yield new Assertion(
         description: 'Both execution sinks terminate the complete opponent process group',
         fallback: 'Code runner ignored its advertised timeout or lost JSON terminal status!'
      )
         ->expect(
            $JSONElapsed >= 0.8
               && $JSONElapsed < 4.5
               && $textElapsed >= 0.8
               && $textElapsed < 4.5
               && ($JSONResults['Timeout'] ?? null) === []
               && ($TextResults['Timeout'] ?? null) === []
               && is_array($status)
               && ($status['exit'] ?? null) === 124
               && ($status['timed-out'] ?? null) === true
               && in_array($status['state'] ?? null, ['terminated', 'killed'], true)
               && ($status['isolation'] ?? null) === 'session-process-group'
               && $invalidOptions === 9
               && !file_exists("{$root}/late-json")
               && !file_exists("{$root}/late-text")
               && (int) file_get_contents("{$root}/pid-json") > 0
               && !@posix_kill((int) file_get_contents("{$root}/pid-json"), 0)
               && (int) file_get_contents("{$root}/pid-text") > 0
               && !@posix_kill((int) file_get_contents("{$root}/pid-text"), 0),
            Op::Identical,
            true
         )
         ->assert();

      $Children = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
      $Iterator = new RecursiveIteratorIterator($Children, RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($Iterator as $Child) {
         $Child->isDir() ? rmdir($Child->getPathname()) : unlink($Child->getPathname());
      }
      rmdir($root);
   })
);
