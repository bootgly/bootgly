<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject unknown opponents and invalid load indexes',
   test: new Assertions(Case: function (): Generator
   {
      $Execute = static function (array $options): array {
         $environment = getenv();
         if (is_array($environment)) {
            unset(
               $environment['BENCHMARK_JSON_INNER'],
               $environment['BENCHMARK_RUN_ID'],
               $environment['BENCHMARK_RUN_DIR'],
               $environment['BENCHMARK_JSON_TOKEN'],
               $environment['BENCHMARK_JSON_RESULT'],
               $environment['BENCHMARK_RUNTIME_FINGERPRINT'],
            );
         }
         else {
            $environment = null;
         }

         $command = [
            PHP_BINARY,
            BOOTGLY_ROOT_DIR . 'bootgly',
            'test',
            'benchmark',
            'Progress_Bar',
            '--format=json',
            '--output=compact',
            ...$options,
         ];
         $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         $Process = proc_open(
            $command,
            $descriptors,
            $pipes,
            sys_get_temp_dir(),
            $environment,
            ['bypass_shell' => true],
         );
         if (!is_resource($Process)) {
            throw new RuntimeException('Can not start benchmark selection probe');
         }

         $STDOUT = (string) stream_get_contents($pipes[1]);
         $STDERR = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $exit = proc_close($Process);
         $Document = json_decode($STDOUT, false, 512, JSON_THROW_ON_ERROR);
         $runPath = isset($Document->run->path_base, $Document->run->directory)
            ? $Document->run->path_base . '/' . $Document->run->directory
            : null;
         $Manifest = is_string($runPath) && is_file("{$runPath}/manifest.json")
            ? json_decode((string) file_get_contents("{$runPath}/manifest.json"), true, flags: JSON_THROW_ON_ERROR)
            : null;
         $marks = is_string($runPath) ? glob("{$runPath}/marks/*_bench.marks") : false;

         $result = [
            'exit' => $exit,
            'stderr' => $STDERR,
            'lines' => substr_count($STDOUT, "\n"),
            'error' => $Document->error->code ?? null,
            'manifest-exit' => $Manifest['run']['exit_code'] ?? null,
            'manifest-complete' => $Manifest['run']['publication_complete'] ?? null,
            'selection-opponents' => $Manifest['selection']['opponents'] ?? null,
            'selection-loads' => $Manifest['selection']['loads'] ?? null,
            'marks' => is_array($marks) ? count($marks) : -1,
         ];

         if (is_string($runPath) && is_dir($runPath)) {
            $Children = new RecursiveDirectoryIterator($runPath, FilesystemIterator::SKIP_DOTS);
            $Iterator = new RecursiveIteratorIterator($Children, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($Iterator as $Child) {
               $Child->isDir() ? rmdir($Child->getPathname()) : unlink($Child->getPathname());
            }
            rmdir($runPath);
         }

         return $result;
      };

      $unknown = $Execute([
         '--opponents=definitely-missing',
         '--loads=default:*',
      ]);
      $invalid = $Execute([
         '--opponents=bootgly',
         '--loads=default:99',
      ]);

      yield new Assertion(
         description: 'Both invalid selections fail as one JSON document without marks',
         fallback: 'Benchmark selection validation accepted or persisted an empty run!'
      )
         ->expect(
            $unknown['exit'] === 1
               && $unknown['stderr'] === ''
               && $unknown['lines'] === 1
               && $unknown['error'] === 'benchmark_isolation_failed'
               && $unknown['manifest-exit'] === 1
               && $unknown['manifest-complete'] === true
               && $unknown['selection-opponents'] === ['definitely-missing']
               && $unknown['selection-loads'] === null
               && $unknown['marks'] === 0
               && $invalid['exit'] === 1
               && $invalid['stderr'] === ''
               && $invalid['lines'] === 1
               && $invalid['error'] === 'benchmark_isolation_failed'
               && $invalid['manifest-exit'] === 1
               && $invalid['manifest-complete'] === true
               && $invalid['selection-opponents'] === ['bootgly']
               && $invalid['selection-loads'] === [99]
               && $invalid['marks'] === 0,
            Op::Identical,
            true
         )
         ->assert();
   })
);
