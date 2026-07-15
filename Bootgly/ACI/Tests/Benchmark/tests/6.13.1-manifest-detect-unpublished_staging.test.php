<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Artifacts;
use Bootgly\ACI\Tests\Benchmark\Manifest;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should mark file and empty-directory staging as unpublished',
   test: new Assertions(Case: function (): Generator
   {
      $root = sys_get_temp_dir() . '/bootgly-manifest-staging-' . bin2hex(random_bytes(12));
      $Artifacts = Artifacts::create('manifest-staging', $root);
      $Manifest = new Manifest($Artifacts, 'manifest-staging', ['bootgly', 'test'], getcwd());
      $fileCapture = $Artifacts->resolve('logs/harness.stdout.log.capture');
      file_put_contents($fileCapture, 'partial');
      $emptyCapture = $Artifacts->directory . '/children/process.capture';
      mkdir($emptyCapture, 0o755, true);
      $Manifest->finish(1);

      $manifest = json_decode(
         (string) file_get_contents($Artifacts->directory . '/manifest.json'),
         true,
         flags: JSON_THROW_ON_ERROR,
      );
      $unpublished = $manifest['run']['unpublished_staging'] ?? [];
      $filePath = $Artifacts->relate('logs/harness.stdout.log.capture');
      $directoryPath = $Artifacts->relate('children/process.capture');

      yield new Assertion(
         description: 'Manifest publication is incomplete while any staging entry remains',
         fallback: 'Manifest ignored an unpublished staging file or empty directory!'
      )
         ->expect(
            ($manifest['run']['publication_complete'] ?? null) === false
               && in_array($filePath, $unpublished, true)
               && in_array($directoryPath, $unpublished, true),
            Op::Identical,
            true
         )
         ->assert();

      $malformedRoundsRejected = false;
      $MalformedRoundsArtifacts = Artifacts::create('manifest-malformed-rounds', $root);
      $MalformedRoundsManifest = new Manifest(
         $MalformedRoundsArtifacts,
         'manifest-malformed-rounds',
         ['bootgly', 'test'],
         getcwd(),
      );
      $MalformedRoundsDocument = new stdClass;
      $MalformedRoundsDocument->rounds = 'not-an-array';
      try {
         $MalformedRoundsManifest->finish(1, $MalformedRoundsDocument);
      }
      catch (RuntimeException) {
         $malformedRoundsRejected = true;
      }

      $malformedResultsRejected = false;
      $MalformedResultsArtifacts = Artifacts::create('manifest-malformed-results', $root);
      $MalformedResultsManifest = new Manifest(
         $MalformedResultsArtifacts,
         'manifest-malformed-results',
         ['bootgly', 'test'],
         getcwd(),
      );
      $MalformedResultsDocument = new stdClass;
      $MalformedResultsDocument->rounds = [
         (object) ['results' => 'not-an-object'],
      ];
      try {
         $MalformedResultsManifest->finish(1, $MalformedResultsDocument);
      }
      catch (RuntimeException) {
         $malformedResultsRejected = true;
      }

      yield new Assertion(
         description: 'Manifest rejects malformed round and result containers',
         fallback: 'Manifest silently normalized malformed benchmark result structure!'
      )
         ->expect(
            $malformedRoundsRejected && $malformedResultsRejected,
            Op::Identical,
            true,
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
