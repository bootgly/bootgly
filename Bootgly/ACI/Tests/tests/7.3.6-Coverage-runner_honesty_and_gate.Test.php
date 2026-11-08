<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;


return new Test(
   description: 'Coverage — the runner refuses to report or gate a run that measures nothing, and keeps coverage -d flags across its re-exec',

   test: new Assertions(Case: function (): Generator {
      if (! function_exists('proc_open')) {
         yield true;
         return;
      }

      // ! Human children: the probes read the text report and STDERR
      $environment = getenv();
      unset($environment['AI_AGENT'], $environment['BOOTGLY_AGENT_STDOUT_REDIRECTED']);
      foreach (Agent::MARKERS as $variables) {
         foreach ($variables as $variable) {
            unset($environment[$variable]);
         }
      }

      /**
       * @param array<int,string> $flags
       * @param array<int,string> $options
       * @return array{0:int,1:string}
       */
      $run = static function (array $flags, array $options, array $extra = []) use ($environment): array {
         $process = proc_open(
            [PHP_BINARY, ...$flags, BOOTGLY_ROOT_DIR . 'bootgly', 'test', '5', ...$options],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BOOTGLY_ROOT_DIR,
            [...$environment, ...$extra]
         );
         if (! is_resource($process)) {
            throw new RuntimeException('Could not run the coverage runner probe.');
         }
         /** @var array<int,resource> $pipes */
         $output = (string) stream_get_contents($pipes[1]);
         $error = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);

         return [proc_close($process), "{$output}{$error}"];
      };

      $directory = Temporaries::reserve('coverage-runner');
      $clover = "{$directory}/clover.xml";
      $asserted = ['-d', 'zend.assertions=1', '-d', 'bootgly.assertions=1'];

      try {
         // @ 1. An explicit nothing driver under a gate
         [$code, $output] = $run($asserted, [
            '--coverage-driver=nothing', '--coverage-floor=1', "--coverage-report=clover:{$clover}",
         ]);
         yield (new Assertion(description: 'a gate on the nothing driver fails the run'))
            ->expect($code !== 0 && str_contains($output, 'the nothing driver measures nothing'))
            ->to->be(true)
            ->assert();

         yield (new Assertion(description: 'the refused gate writes no clover report'))
            ->expect(is_file($clover))
            ->to->be(false)
            ->assert();

         // @ 2. Invalid gate inputs fail before any suite runs
         [$code, $output] = $run($asserted, ['--coverage', '--coverage-floor=eighty']);
         yield (new Assertion(description: 'a non-numeric floor is refused'))
            ->expect($code !== 0 && str_contains($output, '--coverage-floor takes a percentage'))
            ->to->be(true)
            ->assert();

         [$code, $output] = $run($asserted, ['--coverage', "--coverage-diff=--output={$directory}/owned"]);
         yield (new Assertion(description: 'a diff range that reads as a git option is refused'))
            ->expect($code !== 0 && str_contains($output, 'must be a git revision range'))
            ->to->be(true)
            ->assert();

         // @ 3. Auto-detection without a usable driver (environment-dependent)
         $xdebug = function_exists('xdebug_start_code_coverage') || extension_loaded('xdebug');
         $pcov = extension_loaded('pcov');
         $expected = match (true) {
            ! $xdebug && ! $pcov => 'no coverage driver is loaded',
            ! $xdebug && $pcov && (bool) ini_get('pcov.enabled') === false => 'PCOV is loaded but disabled',
            default => null,
         };

         if ($expected !== null) {
            [$code, $output] = $run($asserted, ['--coverage', "--coverage-report=clover:{$clover}"]);
            yield (new Assertion(description: "auto-detection says so: {$expected}"))
               ->expect($code !== 0 && str_contains($output, $expected))
               ->to->be(true)
               ->assert();

            yield (new Assertion(description: 'no zero-statement clover is written'))
               ->expect(is_file($clover))
               ->to->be(false)
               ->assert();
         }

         // ? Native measures nothing in a checkout under one of its excluded
         //   fragments (/tmp/, /vendor/, /storage/): the measured probes
         //   below need a checkout it instruments
         if (Native::allow(BOOTGLY_ROOT_DIR . 'Bootgly/ABI/') === false) {
            yield true;
            return;
         }

         // @ 4. A measured gate that fails fails the run — exit, verdict, JSON
         $native = ['-d', 'zend.assertions=1', '-d', 'bootgly.assertions=1', '-d', 'opcache.enable_cli=0'];
         $gate = ['--coverage-driver=native', '--coverage-path=Bootgly/ABI/', '--coverage-floor=100', '--coverage-report=clover:' . $clover];
         [$code, $output] = $run($native, $gate);
         yield (new Assertion(description: 'a floor the run misses exits non-zero with the reason'))
            ->expect($code !== 0 && preg_match('/Coverage gate: [0-9.]+% of [1-9]\d* statements covered \(\d+\), below the floor of 100%/', $output) === 1)
            ->to->be(true)
            ->assert();

         yield (new Assertion(description: 'the human verdict of a failed gate is FAILED'))
            ->expect(str_contains($output, '[test] FAILED') && str_contains($output, 'coverage failed'))
            ->to->be(true)
            ->assert();

         yield (new Assertion(description: 'the report is still written when the gate fails'))
            ->expect(is_file($clover))
            ->to->be(true)
            ->assert();
         @unlink($clover);

         [$code, $output] = $run($native, $gate, ['AI_AGENT' => '1']);
         $document = null;
         foreach (array_reverse(explode("\n", $output)) as $row) {
            if (str_starts_with($row, '{"result"')) {
               $document = json_decode($row, true);
               break;
            }
         }
         yield (new Assertion(description: 'the agent document of a failed gate says failed and carries the reasons'))
            ->expect(
               $code !== 0
               && is_array($document)
               && $document['result'] === 'failed'
               && ($document['coverage']['gate'] ?? null) === 'failed'
               && str_contains($document['coverage']['failures'][0] ?? '', 'below the floor of 100%')
            )
            ->to->be(true)
            ->assert();

         // @ 5. A range that changed nothing measured skips the gate
         [$code, $output] = $run($native, ['--coverage-driver=native', '--coverage-diff=HEAD..HEAD', '--coverage-floor=50'], ['AI_AGENT' => '1']);
         yield (new Assertion(description: 'an empty range passes with a skipped gate, said on STDERR and in the document'))
            ->expect(
               $code === 0
               && str_contains($output, 'changed no statement in the measured scope')
               && str_contains($output, '"coverage":{"gate":"skipped","failures":[]}')
            )
            ->to->be(true)
            ->assert();

         // @ 5b. A range that changed a source the native driver never
         //   instruments (its own Coverage/ engine) fails the gate instead
         //   of skipping it — a recent commit on Coverage/ whose PHP files
         //   the work tree still holds, when the clone has it and its parent
         $git = static function (array $arguments): string {
            $process = proc_open(
               ['git', '-C', BOOTGLY_ROOT_DIR, ...$arguments],
               [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
               $pipes
            );
            if (is_resource($process) === false) {
               return '';
            }
            /** @var array<int,resource> $pipes */
            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return proc_close($process) === 0 ? trim($output) : '';
         };
         $range = null;
         foreach (explode("\n", $git(['log', '-30', '--no-merges', '--format=%H', '--', 'Bootgly/ACI/Tests/Coverage/'])) as $commit) {
            if (preg_match('/\A[0-9a-f]{40}\z/', $commit) !== 1 || $git(['rev-parse', '--verify', '--quiet', "{$commit}^1"]) === '') {
               continue;
            }
            $files = explode("\n", $git(['diff', '--name-only', '--diff-filter=d', "{$commit}^1", $commit, '--', ':(top,glob)**/*.php']));
            $present = true;
            foreach ($files as $name) {
               if ($name !== '' && is_file(BOOTGLY_ROOT_DIR . $name) === false) {
                  $present = false;
                  break;
               }
            }
            if ($present && preg_grep('#^Bootgly/ACI/Tests/Coverage/#', $files) !== []) {
               $range = "{$commit}^1..{$commit}";
               break;
            }
         }
         if ($range !== null) {
            [$code, $output] = $run($native, ['--coverage-driver=native', "--coverage-diff={$range}", '--coverage-path=Bootgly/ACI/Tests/', '--coverage-floor=1']);
            yield (new Assertion(description: 'a changed source the native driver cannot measure fails the gate'))
               ->expect(
                  $code !== 0
                  && str_contains($output, 'that the native driver cannot measure')
                  && str_contains($output, 'Bootgly/ACI/Tests/Coverage/')
               )
               ->to->be(true)
               ->assert();
         }

         // @ 6. A measured path the run cannot read fails the run, not a trace
         $locked = "{$directory}/locked";
         mkdir($locked);
         chmod($locked, 0);
         if (is_readable($locked) === false) {
            [$code, $output] = $run($native, ['--coverage-driver=native', "--coverage-path={$locked}"]);
            yield (new Assertion(description: 'an unreadable measured path fails with a Coverage line'))
               ->expect(
                  $code !== 0
                  && str_contains($output, '[test] Coverage: Coverage path is not a readable directory')
                  && str_contains($output, 'Exception') === false
               )
               ->to->be(true)
               ->assert();
         }
         chmod($locked, 0o755);
         rmdir($locked);

         // @ 7. The assertions re-exec keeps the coverage -d flags
         [$code, $output] = $run(
            ['-d', 'zend.assertions=-1', '-d', 'opcache.enable_cli=0'],
            ['--coverage-driver=native', '--coverage-report=text']
         );
         yield (new Assertion(description: 'native coverage survives the re-exec with opcache.enable_cli=0'))
            ->expect($code === 0 && preg_match('/TOTAL\s+\d+\/[1-9]\d*\s/', $output) === 1)
            ->to->be(true)
            ->assert();
      }
      finally {
         @unlink($clover);
         @rmdir($directory);
      }
   })
);
