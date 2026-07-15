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

      $Children = new RecursiveDirectoryIterator($Artifacts->directory, FilesystemIterator::SKIP_DOTS);
      $Iterator = new RecursiveIteratorIterator($Children, RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($Iterator as $Child) {
         $Child->isDir() ? rmdir($Child->getPathname()) : unlink($Child->getPathname());
      }
      rmdir($Artifacts->directory);
      rmdir($root);
   })
);
