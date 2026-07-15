<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Artifacts;
use Bootgly\ACI\Tests\Benchmark\Manifest;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should redact untrusted argv values while retaining validated config',
   test: new Assertions(Case: function (): Generator
   {
      $root = sys_get_temp_dir() . '/bootgly-manifest-argv-' . bin2hex(random_bytes(12));
      $apiMarker = 'api-key-marker-' . bin2hex(random_bytes(8));
      $accessMarker = 'access-key-marker-' . bin2hex(random_bytes(8));
      $Artifacts = Artifacts::create('ManifestArgv', $root);
      $Manifest = new Manifest(
         $Artifacts,
         'ManifestArgv',
         [
            'bootgly',
            'test',
            'benchmark',
            'ManifestArgv',
            "--api-key={$apiMarker}",
            '--access-key',
            $accessMarker,
            '--connections=514',
            '--dsn=redis://user:password@127.0.0.1/0',
         ],
         getcwd() ?: $root,
      );
      $Manifest->select([
         'case' => 'ManifestArgv',
         'config' => ['connections' => 514],
      ]);
      $Manifest->finish(0);
      $JSON = (string) file_get_contents($Artifacts->resolve('manifest.json'));
      $data = json_decode($JSON, true, flags: JSON_THROW_ON_ERROR);

      yield new Assertion(
         description: 'Unknown option values and credential URIs do not survive in argv',
         fallback: 'Manifest argv leaked an untrusted option value or discarded validated config!'
      )
         ->expect(
            !str_contains($JSON, $apiMarker)
               && !str_contains($JSON, $accessMarker)
               && !str_contains($JSON, 'user:password')
               && in_array('--api-key=<redacted>', $data['command']['argv'] ?? [], true)
               && in_array('--access-key', $data['command']['argv'] ?? [], true)
               && in_array('--connections=<redacted>', $data['command']['argv'] ?? [], true)
               && ($data['selection']['config']['connections'] ?? null) === 514,
            Op::Identical,
            true
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
