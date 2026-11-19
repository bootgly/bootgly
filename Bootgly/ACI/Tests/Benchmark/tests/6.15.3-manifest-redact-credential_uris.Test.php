<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Artifacts;
use Bootgly\ACI\Tests\Benchmark\Manifest;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * A credential URL in argv never reaches `manifest.json` — whatever its form:
 * a token as the user, an empty user, a third slash, two `@`, a URL inside
 * a text. Only an option or KEY name before its `://` survives, never the
 * text of a query that follows it. An scp-like `user:password@host:path`
 * carries no `://` and is a documented residual.
 */

return new Test(
   description: 'It should redact every credential URL form from the manifest argv',
   test: new Assertions(Case: function (): Generator
   {
      $root = sys_get_temp_dir() . '/bootgly-manifest-uris-' . bin2hex(random_bytes(12));
      $Artifacts = Artifacts::create('ManifestURIs', $root);
      $arguments = [
         'https://SYNTHTOK1@host.invalid/o/r.git',
         'HTTPS://SYNTHTOK2@host.invalid/x',
         'https:///SYNTHTOK3@host.invalid/x',
         'redis://:SYNTHPW4@127.0.0.1:6379/0',
         'see https://SYNTHTOK5@host.invalid/x',
         'https://u@SYNTHTOK6:pw@host.invalid/x',
         'https://user:SYNTHPW7@host.invalid/x?a=b',
         'target=https://SYNTHTOK8@host.invalid/',
         '--dsn=redis://user:SYNTHPW9@127.0.0.1/0',
         'https://host.invalid/x?a=b',
         // ! The scp-like form carries no `://` — a documented residual, pinned so a change is noticed
         'user:SYNTHSCP@host.invalid:o/r.git',
      ];
      $Manifest = new Manifest(
         $Artifacts,
         'ManifestURIs',
         ['bootgly', 'test', 'benchmark', 'ManifestURIs', ...$arguments],
         getcwd() ?: $root,
      );
      $Manifest->finish(0);
      $JSON = (string) file_get_contents($Artifacts->resolve('manifest.json'));
      $data = json_decode($JSON, true, flags: JSON_THROW_ON_ERROR);
      $argv = $data['command']['argv'] ?? [];

      yield new Assertion(
         description: 'No credential URL form survives in argv; option and KEY names do, plain URLs stay (the scp-like residual too)',
         fallback: 'Manifest argv leaked a credential URL or lost a name!'
      )
         ->expect(
            preg_match('/SYNTH(?:TOK|PW)\d/', $JSON) === 0
               && array_slice($argv, 4) === [
                  '<redacted-uri>',
                  '<redacted-uri>',
                  '<redacted-uri>',
                  '<redacted-uri>',
                  '<redacted-uri>',
                  '<redacted-uri>',
                  '<redacted-uri>',
                  'target=<redacted-uri>',
                  '--dsn=<redacted-uri>',
                  'https://host.invalid/x?a=b',
                  'user:SYNTHSCP@host.invalid:o/r.git',
               ],
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
