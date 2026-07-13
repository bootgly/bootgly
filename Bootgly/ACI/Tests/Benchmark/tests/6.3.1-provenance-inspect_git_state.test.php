<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Provenance;
use Bootgly\ACI\Tests\Suite\Test\Specification;


$version = [];
$available = 0;
exec('git --version 2>/dev/null', $version, $available);

return new Specification(
   description: 'It should read the exact SHA and dirty state from Git',
   skip: $available !== 0,
   test: new Assertions(Case: function (): Generator
   {
      $dir = sys_get_temp_dir() . '/bootgly-provenance-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($dir, 0775, true);
      $tracked = "{$dir}/tracked.txt";
      file_put_contents($tracked, "original\n");

      $root = escapeshellarg($dir);
      exec("git -C {$root} init --quiet");
      exec("git -C {$root} add tracked.txt");
      exec("git -C {$root} -c user.name=Bootgly -c user.email=tests@bootgly.local commit --quiet -m initial");

      $SHA = trim((string) shell_exec("git -C {$root} rev-parse HEAD"));
      $clean = Provenance::inspect(
         'framework',
         $dir,
         SHAFallback: str_repeat('f', 40),
         dirtyFallback: 'dirty',
      );

      file_put_contents($tracked, "modified\n");
      $trackedDirty = Provenance::inspect('framework', $dir);

      file_put_contents($tracked, "original\n");
      $restored = Provenance::inspect('framework', $dir);

      file_put_contents("{$dir}/untracked.txt", "new\n");
      $untrackedDirty = Provenance::inspect('framework', $dir);

      // ! Clean before yielding so a failed assertion cannot strand the fixture.
      $Iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($Iterator as $Item) {
         $Item->isDir() ? rmdir($Item->getPathname()) : unlink($Item->getPathname());
      }
      rmdir($dir);

      yield new Assertion(
         description: 'Git overrides fallbacks with full HEAD and dirty=false',
         fallback: 'Clean Git provenance is incorrect!'
      )
         ->expect($clean, Op::Equal, [
            'framework-sha' => $SHA,
            'framework-dirty' => 'false',
         ])
         ->assert();

      yield new Assertion(
         description: 'Tracked modifications set dirty=true',
         fallback: 'Tracked Git change was not detected!'
      )
         ->expect($trackedDirty['framework-dirty'], Op::Identical, 'true')
         ->assert();

      yield new Assertion(
         description: 'Restoring tracked content returns to dirty=false',
         fallback: 'Restored Git worktree remained dirty!'
      )
         ->expect($restored['framework-dirty'], Op::Identical, 'false')
         ->assert();

      yield new Assertion(
         description: 'Untracked files set dirty=true',
         fallback: 'Untracked Git file was not detected!'
      )
         ->expect($untrackedDirty['framework-dirty'], Op::Identical, 'true')
         ->assert();
   })
);
