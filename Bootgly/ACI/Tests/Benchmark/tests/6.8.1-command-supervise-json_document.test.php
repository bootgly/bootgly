<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should isolate a JSON benchmark invocation behind a process supervisor',
   test: new Assertions(Case: function (): Generator
   {
      $descriptors = [
         1 => ['pipe', 'w'],
         2 => ['pipe', 'w'],
      ];
      $pipes = [];
      $environment = getenv();
      if (!is_array($environment)) {
         $environment = null;
      }
      else {
         // ! A caller-controlled/stale internal marker must not bypass the
         //   outer process that owns the public one-document contract.
         $environment['BENCHMARK_JSON_INNER'] = '1';
         unset(
            $environment['BENCHMARK_RUN_ID'],
            $environment['BENCHMARK_RUN_DIR'],
            $environment['BENCHMARK_JSON_TOKEN'],
            $environment['BENCHMARK_JSON_RESULT'],
            $environment['BENCHMARK_RUNTIME_FINGERPRINT'],
         );
      }
      $Process = proc_open(
         [
            PHP_BINARY,
            '-d',
            'memory_limit=123M',
            BOOTGLY_ROOT_DIR . 'bootgly',
            'test',
            'benchmark',
            'Missing_JSON_Isolation_Case',
            '--format=json',
         ],
         $descriptors,
         $pipes,
         sys_get_temp_dir(),
         $environment,
         ['bypass_shell' => true]
      );
      if (!is_resource($Process)) {
         throw new RuntimeException('Can not start the benchmark JSON supervisor probe');
      }

      $STDOUT = stream_get_contents($pipes[1]);
      $STDERR = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $exit = proc_close($Process);
      $STDOUT = $STDOUT !== false ? $STDOUT : '';
      $STDERR = $STDERR !== false ? $STDERR : '';

      $Document = null;
      try {
         $Document = json_decode($STDOUT, false, 512, JSON_THROW_ON_ERROR);
      }
      catch (Throwable) {
         // Assertions below report malformed or contaminated stdout.
      }

      $Run = $Document instanceof stdClass && isset($Document->run) ? $Document->run : null;
      $resultFile = $Run instanceof stdClass ? ($Run->result ?? null) : null;
      $STDOUTFile = $Run instanceof stdClass ? ($Run->stdout ?? null) : null;
      $STDERRFile = $Run instanceof stdClass ? ($Run->stderr ?? null) : null;
      $pathBase = $Run instanceof stdClass ? ($Run->path_base ?? null) : null;
      $Resolve = static function (mixed $path) use ($pathBase): null|string {
         if (!is_string($path) || $path === '') {
            return null;
         }

         return $path[0] === '/'
            ? $path
            : (is_string($pathBase) ? "{$pathBase}/{$path}" : null);
      };
      $resultPath = $Resolve($resultFile);
      $STDOUTPath = $Resolve($STDOUTFile);
      $STDERRPath = $Resolve($STDERRFile);
      $runPath = $Run instanceof stdClass ? $Resolve($Run->directory ?? null) : null;
      $result = is_string($resultPath) && is_file($resultPath)
         ? file_get_contents($resultPath)
         : false;
      $manifestPath = is_string($runPath) ? "{$runPath}/manifest.json" : null;
      $Manifest = is_string($manifestPath) && is_file($manifestPath)
         ? json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR)
         : null;
      $manifestResult = null;
      foreach (is_array($Manifest) ? ($Manifest['artifacts'] ?? []) : [] as $artifact) {
         if (is_array($artifact) && ($artifact['path'] ?? null) === $resultFile) {
            $manifestResult = $artifact;
            break;
         }
      }

      yield new Assertion(
         description: 'Outer stdout is exactly one JSON document even when the inner command exits',
         fallback: 'JSON supervisor leaked human output or lost its failure document!'
      )
         ->expect(
            $exit === 1
               && $STDERR === ''
               && $Document instanceof stdClass
               && is_string($pathBase)
               && str_starts_with($pathBase, '/')
               && isset($Document->error->code)
               && $Document->error->code === 'benchmark_isolation_failed'
               && substr_count($STDOUT, "\n") === 1
               && str_ends_with($STDOUT, "\n"),
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Inner stdout and stderr are separate atomically-published run artifacts',
         fallback: 'JSON supervisor did not isolate or publish the inner channels!'
      )
         ->expect(
            is_string($STDOUTPath)
               && is_file($STDOUTPath)
               && is_string($STDERRPath)
               && is_file($STDERRPath)
               && $STDOUTPath !== $STDERRPath
               && str_contains((string) file_get_contents($STDOUTPath), 'Benchmark case not found:')
               && file_get_contents($STDERRPath) === ''
               && !is_file($STDOUTPath . '.capture')
               && !is_file($STDERRPath . '.capture'),
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Failure result and versioned integrity manifest are retained atomically',
         fallback: 'JSON result or run manifest differs from the supervised evidence!'
      )
         ->expect(
            $result === $STDOUT
               && is_array($Manifest)
               && ($Manifest['schema'] ?? null) === 'bootgly.benchmark-run/v1'
               && ($Manifest['run']['working_directory'] ?? null) === sys_get_temp_dir()
               && ($Manifest['run']['exit_code'] ?? null) === 1
               && ($Manifest['run']['publication_complete'] ?? null) === true
               && is_array($manifestResult)
               && ($manifestResult['sha256'] ?? null) === hash('sha256', $STDOUT),
            Op::Identical,
            true
         )
         ->assert();

      // # The probe owns this unique run directory; remove it after preserving
      //   every value used by the assertions above.
      if (is_string($runPath) && is_dir($runPath)) {
         $Cleanup = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($runPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
         );
         foreach ($Cleanup as $Entry) {
            $Entry->isDir() ? rmdir($Entry->getPathname()) : unlink($Entry->getPathname());
         }
         rmdir($runPath);
      }
   })
);
