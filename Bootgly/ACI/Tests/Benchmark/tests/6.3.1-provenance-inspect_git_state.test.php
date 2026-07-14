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
   description: 'It should fingerprint stable Git-visible working-tree bytes',
   skip: $available !== 0,
   test: new Assertions(Case: function (): Generator
   {
      $dir = sys_get_temp_dir() . '/bootgly-provenance-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($dir, 0775, true);
      mkdir("{$dir}/case", 0775, true);
      $tracked = "{$dir}/tracked.txt";
      file_put_contents($tracked, "original\n");
      file_put_contents("{$dir}/filtered.txt", "one\n");
      file_put_contents("{$dir}/case/input.txt", "first\n\nlast\n");
      file_put_contents("{$dir}/.gitignore", "ignored.txt\n");
      file_put_contents("{$dir}/.gitattributes", "filtered.txt filter=strip\n");

      $root = escapeshellarg($dir);
      exec("git -C {$root} init --quiet");
      exec("git -C {$root} add tracked.txt filtered.txt case/input.txt .gitignore .gitattributes");
      exec(
         "git -C {$root} -c user.name=Bootgly -c user.email=tests@bootgly.local "
         . "-c commit.gpgsign=false commit --quiet -m initial"
      );

      $SHA = trim((string) shell_exec("git -C {$root} rev-parse HEAD"));
      $emptySHA = hash('sha256', '');
      $trackedVector = 'cd1ac161737b327903455d9e0800dc9655f7ef8c222bd10d0fb58d2828619067';
      $manifestVector = '6cd76887d29f417047ea45105705dadd7c025b934b93e38c0ae58476bb080184';
      $clean = Provenance::inspect(
         'framework',
         $dir,
         SHAFallback: str_repeat('f', 40),
         dirtyFallback: 'dirty',
         trackedFallback: str_repeat('e', 64),
         untrackedFallback: str_repeat('e', 64),
      );

      $previousGITDir = getenv('GIT_DIR');
      putenv("GIT_DIR={$dir}/foreign.git");
      $ambientGIT = Provenance::inspect('framework', $dir);
      putenv($previousGITDir === false ? 'GIT_DIR' : "GIT_DIR={$previousGITDir}");

      $previousPath = getenv('PATH');
      putenv('PATH=/nonexistent');
      $unavailableGIT = Provenance::inspect(
         'framework',
         $dir,
         SHAFallback: str_repeat('f', 40),
         dirtyFallback: 'false',
         trackedFallback: $emptySHA,
         untrackedFallback: $emptySHA,
      );
      putenv($previousPath === false ? 'PATH' : "PATH={$previousPath}");

      $programs = "{$dir}/.git/provenance-programs";
      $hooks = "{$programs}/hooks";
      $fsmonitorMarker = "{$programs}/fsmonitor-ran";
      $hookMarker = "{$programs}/hook-ran";
      mkdir($hooks, 0775, true);
      file_put_contents(
         "{$programs}/fsmonitor",
         "#!/bin/sh\ntouch " . escapeshellarg($fsmonitorMarker) . "\n"
      );
      file_put_contents(
         "{$hooks}/post-index-change",
         "#!/bin/sh\ntouch " . escapeshellarg($hookMarker) . "\n"
      );
      chmod("{$programs}/fsmonitor", 0755);
      chmod("{$hooks}/post-index-change", 0755);
      exec("git -C {$root} config core.fsmonitor " . escapeshellarg("{$programs}/fsmonitor"));
      exec("git -C {$root} config core.hooksPath " . escapeshellarg($hooks));
      touch($tracked);
      $programSafe = Provenance::inspect('framework', $dir);
      $programRan = is_file($fsmonitorMarker) || is_file($hookMarker);
      exec("git -C {$root} config --unset core.fsmonitor");
      exec("git -C {$root} config --unset core.hooksPath");
      foreach ([$fsmonitorMarker, $hookMarker] as $marker) {
         if (is_file($marker)) {
            unlink($marker);
         }
      }
      unlink("{$programs}/fsmonitor");
      unlink("{$hooks}/post-index-change");
      rmdir($hooks);
      rmdir($programs);

      $binary = escapeshellarg(PHP_BINARY);
      $producer = escapeshellarg(dirname(__DIR__) . '/provenance.php');
      $fixtureRoot = escapeshellarg($dir);
      $dockerArguments = (string) shell_exec(
         "{$binary} {$producer} {$fixtureRoot} {$fixtureRoot} --docker-build-args"
      );

      file_put_contents($tracked, "modified\n");
      $unstaged = Provenance::inspect('framework', $dir);
      exec("git -C {$root} add tracked.txt");
      $staged = Provenance::inspect('framework', $dir);

      exec("git -C {$root} config filter.strip.clean 'sed s/X//g'");
      file_put_contents("{$dir}/filtered.txt", "Xtwo\n");
      $filteredOnce = Provenance::inspect('framework', $dir);
      file_put_contents("{$dir}/filtered.txt", "XXtwo\n");
      $filteredTwice = Provenance::inspect('framework', $dir);
      file_put_contents("{$dir}/filtered.txt", "one\n");
      exec("git -C {$root} config --unset filter.strip.clean");

      $orderFile = "{$dir}.diff-order";
      file_put_contents("{$dir}/case/input.txt", "changed\n\nlast\n");
      $defaultConfig = Provenance::inspect('framework', $dir);
      file_put_contents($orderFile, "tracked.txt\ncase/input.txt\n");
      exec("git -C {$root} config diff.orderFile " . escapeshellarg($orderFile));
      exec("git -C {$root} config diff.suppressBlankEmpty true");
      $hostConfig = Provenance::inspect('framework', $dir);
      exec("git -C {$root} config --unset diff.orderFile");
      exec("git -C {$root} config --unset diff.suppressBlankEmpty");
      unlink($orderFile);
      file_put_contents("{$dir}/case/input.txt", "first\n\nlast\n");

      file_put_contents($tracked, "modified-again\0binary\n");
      $mixed = Provenance::inspect('framework', $dir);
      exec("git -C {$root} add tracked.txt");
      $restaged = Provenance::inspect('framework', $dir);

      file_put_contents($tracked, "original\n");
      exec("git -C {$root} add tracked.txt");
      $restored = Provenance::inspect('framework', $dir);

      file_put_contents("{$dir}/ignored.txt", "ignored input\n");
      $ignored = Provenance::inspect('framework', $dir);

      file_put_contents("{$dir}/z.txt", "z-content\n");
      file_put_contents("{$dir}/a file.txt", "a-content\n");
      $ordered = Provenance::inspect('framework', $dir);

      unlink("{$dir}/z.txt");
      unlink("{$dir}/a file.txt");
      file_put_contents("{$dir}/a file.txt", "a-content\n");
      file_put_contents("{$dir}/z.txt", "z-content\n");
      $reordered = Provenance::inspect('framework', $dir);

      chmod("{$dir}/z.txt", 0755);
      $executable = Provenance::inspect('framework', $dir);
      chmod("{$dir}/z.txt", 0644);
      file_put_contents("{$dir}/z.txt", "changed-content\n");
      $contentChanged = Provenance::inspect('framework', $dir);

      symlink('a file.txt', "{$dir}/link.txt");
      $linked = Provenance::inspect('framework', $dir);
      unlink("{$dir}/link.txt");
      symlink('z.txt', "{$dir}/link.txt");
      $retargeted = Provenance::inspect('framework', $dir);

      file_put_contents("{$dir}/root-only.txt", "root-visible\n");
      $fromRoot = Provenance::inspect('benchmarks', $dir);
      $fromCase = Provenance::inspect('benchmarks', "{$dir}/case");

      $outside = "{$dir}.outside";
      mkdir($outside);
      file_put_contents("{$outside}/input.txt", "outside\n");
      rename("{$dir}/case", "{$dir}/case-real");
      symlink($outside, "{$dir}/case");
      $escaped = Provenance::inspect('framework', $dir);
      unlink("{$dir}/case");
      rename("{$dir}/case-real", "{$dir}/case");
      unlink("{$outside}/input.txt");
      rmdir($outside);

      unlink("{$dir}/link.txt");
      unlink("{$dir}/root-only.txt");
      unlink("{$dir}/z.txt");
      unlink("{$dir}/a file.txt");
      unlink("{$dir}/ignored.txt");

      exec("git -C {$root} update-index --assume-unchanged tracked.txt");
      file_put_contents($tracked, "hidden-change\n");
      $hidden = Provenance::inspect('framework', $dir);
      exec("git -C {$root} update-index --no-assume-unchanged tracked.txt");
      file_put_contents($tracked, "original\n");
      mkdir("{$dir}/embedded-framework");
      $nested = Provenance::inspect('framework', "{$dir}/embedded-framework", ascend: false);
      rmdir("{$dir}/embedded-framework");
      $final = Provenance::inspect('framework', $dir);

      $unbornDir = sys_get_temp_dir() . '/bootgly-provenance-unborn-' . getmypid()
         . '-' . bin2hex(random_bytes(4));
      mkdir($unbornDir, 0775, true);
      file_put_contents("{$unbornDir}/input.txt", "uncommitted\n");
      $unbornRoot = escapeshellarg($unbornDir);
      exec("git -C {$unbornRoot} init --quiet");
      $unborn = Provenance::inspect(
         'framework',
         $unbornDir,
         SHAFallback: str_repeat('f', 40),
         dirtyFallback: 'false',
         trackedFallback: str_repeat('d', 64),
         untrackedFallback: str_repeat('e', 64),
      );

      $subSource = sys_get_temp_dir() . '/bootgly-provenance-sub-source-' . getmypid()
         . '-' . bin2hex(random_bytes(4));
      $subParent = sys_get_temp_dir() . '/bootgly-provenance-sub-parent-' . getmypid()
         . '-' . bin2hex(random_bytes(4));
      mkdir($subSource, 0775, true);
      mkdir($subParent, 0775, true);
      $subSourceRoot = escapeshellarg($subSource);
      $subParentRoot = escapeshellarg($subParent);
      exec("git -C {$subSourceRoot} init --quiet");
      file_put_contents("{$subSource}/input.txt", "submodule\n");
      exec("git -C {$subSourceRoot} add input.txt");
      exec(
         "git -C {$subSourceRoot} -c user.name=Bootgly -c user.email=tests@bootgly.local "
         . "-c commit.gpgsign=false commit --quiet -m initial"
      );
      exec("git -C {$subParentRoot} init --quiet");
      exec(
         "git -C {$subParentRoot} -c protocol.file.allow=always submodule add --quiet "
         . escapeshellarg($subSource) . ' module'
      );
      exec(
         "git -C {$subParentRoot} -c user.name=Bootgly -c user.email=tests@bootgly.local "
         . "-c commit.gpgsign=false commit --quiet -am initial"
      );
      $submodule = Provenance::inspect('framework', $subParent);

      $replaceDir = sys_get_temp_dir() . '/bootgly-provenance-replace-' . getmypid()
         . '-' . bin2hex(random_bytes(4));
      mkdir($replaceDir, 0775, true);
      $replaceRoot = escapeshellarg($replaceDir);
      exec("git -C {$replaceRoot} init --quiet");
      file_put_contents("{$replaceDir}/input.txt", "alpha\n");
      exec("git -C {$replaceRoot} add input.txt");
      exec(
         "git -C {$replaceRoot} -c user.name=Bootgly -c user.email=tests@bootgly.local "
         . "-c commit.gpgsign=false commit --quiet -m alpha"
      );
      $originalSHA = trim((string) shell_exec("git -C {$replaceRoot} rev-parse HEAD"));
      $replaceClean = Provenance::inspect('framework', $replaceDir);
      file_put_contents("{$replaceDir}/input.txt", "bravo\n");
      exec("git -C {$replaceRoot} add input.txt");
      exec(
         "git -C {$replaceRoot} -c user.name=Bootgly -c user.email=tests@bootgly.local "
         . "-c commit.gpgsign=false commit --quiet -m bravo"
      );
      $replacementSHA = trim((string) shell_exec("git -C {$replaceRoot} rev-parse HEAD"));
      exec("git -C {$replaceRoot} replace {$originalSHA} {$replacementSHA}");
      exec("git -C {$replaceRoot} reset --hard --quiet {$originalSHA}");
      $replaced = Provenance::inspect('framework', $replaceDir);

      // ! Clean before yielding so a failed assertion cannot strand the fixture.
      $Iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($Iterator as $Item) {
         $Item->isDir() ? rmdir($Item->getPathname()) : unlink($Item->getPathname());
      }
      rmdir($dir);

      $Iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($unbornDir, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($Iterator as $Item) {
         $Item->isDir() ? rmdir($Item->getPathname()) : unlink($Item->getPathname());
      }
      rmdir($unbornDir);

      foreach ([$subParent, $subSource, $replaceDir] as $fixture) {
         $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
         );
         foreach ($Iterator as $Item) {
            $Item->isDir() ? rmdir($Item->getPathname()) : unlink($Item->getPathname());
         }
         rmdir($fixture);
      }

      yield new Assertion(
         description: 'Git overrides fallbacks with full HEAD and dirty=false',
         fallback: 'Clean Git provenance is incorrect!'
      )
         ->expect($clean, Op::Equal, [
            'framework-sha' => $SHA,
            'framework-dirty' => 'false',
            'framework-tracked-diff-sha256' => $emptySHA,
            'framework-untracked-manifest-sha256' => $emptySHA,
         ])
         ->assert();

      yield new Assertion(
         description: 'Ambient Git repository overrides cannot redirect inspection',
         fallback: 'GIT_DIR changed the attributed source repository!'
      )
         ->expect($ambientGIT, Op::Equal, $clean)
         ->assert();

      yield new Assertion(
         description: 'A local Git marker suppresses fallbacks when Git is unavailable',
         fallback: 'Stale packaged metadata hid a live checkout inspection failure!'
      )
         ->expect($unavailableGIT, Op::Equal, [
            'framework-sha' => 'unknown',
            'framework-dirty' => 'unknown',
            'framework-tracked-diff-sha256' => 'unknown',
            'framework-untracked-manifest-sha256' => 'unknown',
         ])
         ->assert();

      yield new Assertion(
         description: 'Read-only inspection disables repository-configured programs',
         fallback: 'Git fsmonitor or index hooks executed during source capture!'
      )
         ->expect($programRan === false && $programSafe === $clean, Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'The canonical producer emits a complete Docker argument tuple',
         fallback: 'Local Docker provenance arguments are incomplete or unsafe!'
      )
         ->expect(
            substr_count($dockerArguments, '--build-arg=BOOTGLY_') === 8
               && str_contains($dockerArguments, '--build-arg=BOOTGLY_FRAMEWORK_SHA=')
               && str_contains($dockerArguments, '--build-arg=BOOTGLY_BENCHMARKS_SHA=')
               && str_contains($dockerArguments, 'SOURCE_IDENTITY_VERSION') === false
               && str_contains($dockerArguments, 'unknown') === false,
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Tracked final bytes have a stable hash independent of staging',
         fallback: 'Tracked diff identity depends on index staging!'
      )
         ->expect(
            $unstaged['framework-dirty'] === 'true'
               && $unstaged['framework-tracked-diff-sha256'] !== $emptySHA
               && $unstaged['framework-tracked-diff-sha256'] === $trackedVector
               && $unstaged['framework-tracked-diff-sha256'] === $staged['framework-tracked-diff-sha256'],
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Binary tracked changes are fingerprinted from final bytes',
         fallback: 'Binary or mixed staged/unstaged identity is unstable!'
      )
         ->expect(
            $mixed['framework-tracked-diff-sha256'] !== $unstaged['framework-tracked-diff-sha256']
               && $mixed['framework-tracked-diff-sha256'] === $restaged['framework-tracked-diff-sha256'],
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Raw tracked identity bypasses colliding Git clean filters',
         fallback: 'Different physical source bytes received the same tracked identity!'
      )
         ->expect(
            $filteredOnce['framework-tracked-diff-sha256'] !== 'unknown'
               && $filteredOnce['framework-tracked-diff-sha256']
                  !== $filteredTwice['framework-tracked-diff-sha256'],
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Tracked v1 identity is isolated from host diff rendering config',
         fallback: 'Inherited Git config changed the canonical tracked fingerprint!'
      )
         ->expect(
            $defaultConfig['framework-tracked-diff-sha256'] !== 'unknown'
               && $defaultConfig['framework-tracked-diff-sha256']
                  === $hostConfig['framework-tracked-diff-sha256'],
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Restoring tracked content returns to dirty=false',
         fallback: 'Restored Git worktree remained dirty!'
      )
         ->expect($restored, Op::Equal, $clean)
         ->assert();

      yield new Assertion(
         description: 'Ignored files are outside Git-visible source identity',
         fallback: 'Ignored input changed source provenance!'
      )
         ->expect($ignored, Op::Equal, $clean)
         ->assert();

      yield new Assertion(
         description: 'Untracked manifest is deterministic across creation order',
         fallback: 'Untracked manifest depends on directory enumeration order!'
      )
         ->expect(
            $ordered['framework-dirty'] === 'true'
               && $ordered['framework-tracked-diff-sha256'] === $emptySHA
               && $ordered['framework-untracked-manifest-sha256'] !== $emptySHA
               && $ordered['framework-untracked-manifest-sha256'] === $manifestVector
               && $ordered['framework-untracked-manifest-sha256']
                  === $reordered['framework-untracked-manifest-sha256'],
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Untracked mode and content changes alter the manifest',
         fallback: 'Untracked bytes or executable mode are missing from identity!'
      )
         ->expect(
            $executable['framework-untracked-manifest-sha256']
               !== $reordered['framework-untracked-manifest-sha256']
               && $contentChanged['framework-untracked-manifest-sha256']
                  !== $reordered['framework-untracked-manifest-sha256'],
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Symlink targets are hashed without following them',
         fallback: 'Untracked symlink target is absent from identity!'
      )
         ->expect(
            $linked['framework-untracked-manifest-sha256']
               !== $retargeted['framework-untracked-manifest-sha256'],
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Inspection from a case subdirectory covers the repository root',
         fallback: 'Benchmark-suite provenance omitted sibling inputs!'
      )
         ->expect(
            $fromCase['benchmarks-untracked-manifest-sha256'],
            Op::Identical,
            $fromRoot['benchmarks-untracked-manifest-sha256']
         )
         ->assert();

      yield new Assertion(
         description: 'Tracked paths with symlinked parent components fail closed',
         fallback: 'Tracked identity followed an intermediate symlink outside the repository!'
      )
         ->expect(
            $escaped['framework-tracked-diff-sha256'] === 'unknown'
               && $escaped['framework-untracked-manifest-sha256'] === 'unknown',
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Raw untracked names and content never reach metadata',
         fallback: 'Untracked manifest leaked a path or source text!'
      )
         ->expect(
            str_contains(implode('|', $reordered), 'a file.txt') === false
               && str_contains(implode('|', $reordered), 'a-content') === false,
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Assume-unchanged ambiguity fails closed',
         fallback: 'Hidden tracked bytes received a false exact identity!'
      )
         ->expect(
            $hidden['framework-tracked-diff-sha256'] === 'unknown'
               && $hidden['framework-untracked-manifest-sha256'] === 'unknown',
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'A framework directory cannot inherit an ancestor repository identity',
         fallback: 'Nested non-repository source was attributed to its consumer project!'
      )
         ->expect($nested, Op::Equal, [
            'framework-sha' => 'unknown',
            'framework-dirty' => 'unknown',
            'framework-tracked-diff-sha256' => 'unknown',
            'framework-untracked-manifest-sha256' => 'unknown',
         ])
         ->assert();

      yield new Assertion(
         description: 'A local repository without HEAD rejects packaged fallbacks',
         fallback: 'Unborn local Git state was combined with stale image metadata!'
      )
         ->expect($unborn, Op::Equal, [
            'framework-sha' => 'unknown',
            'framework-dirty' => 'unknown',
            'framework-tracked-diff-sha256' => 'unknown',
            'framework-untracked-manifest-sha256' => 'unknown',
         ])
         ->assert();

      yield new Assertion(
         description: 'Submodules fail closed until recursive raw identity is supported',
         fallback: 'A gitlink was mistaken for exact recursive worktree bytes!'
      )
         ->expect(
            $submodule['framework-dirty'] === 'unknown'
               && $submodule['framework-tracked-diff-sha256'] === 'unknown'
               && $submodule['framework-untracked-manifest-sha256'] === 'unknown',
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Repository replacement refs cannot rewrite the identity baseline',
         fallback: 'refs/replace hid different physical tracked bytes!'
      )
         ->expect(
            $replaced['framework-sha'] === $originalSHA
               && $replaced['framework-dirty'] === 'true'
               && $replaced['framework-tracked-diff-sha256'] !== $emptySHA
               && $replaced['framework-tracked-diff-sha256']
                  !== $replaceClean['framework-tracked-diff-sha256'],
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Final cleanup returns to the canonical clean identity',
         fallback: 'Fixture cleanup did not restore clean provenance!'
      )
         ->expect($final, Op::Equal, $clean)
         ->assert();
   })
);
