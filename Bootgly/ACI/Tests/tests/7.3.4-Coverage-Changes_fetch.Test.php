<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Changes;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Coverage — Changes validates the range and reads changed lines from git',

   test: new Assertions(Case: function (): Generator {
      // @ Range validation — before git ever runs
      foreach (['', '--output=/tmp/owned', '-p', "HEAD\n", 'HEAD main', "HEAD\0"] as $range) {
         $thrown = false;
         try {
            new Changes($range, BOOTGLY_WORKING_DIR);
         }
         catch (InvalidArgumentException) {
            $thrown = true;
         }

         $shown = json_encode($range);
         yield (new Assertion(description: "range {$shown} is rejected"))
            ->expect($thrown)
            ->to->be(true)
            ->assert();
      }

      $Changes = new Changes('origin/main...HEAD', '/repo');
      yield (new Assertion(description: 'a revision range is kept as given'))
         ->expect([$Changes->range, $Changes->directory, $Changes->lines])
         ->to->be(['origin/main...HEAD', '/repo', []])
         ->assert();

      // ? git is a tool of the run, not a framework dependency
      $probe = @proc_open(['git', '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
      if (is_resource($probe) === false) {
         yield true;
         return;
      }
      /** @var array<int,resource> $pipes */
      stream_get_contents($pipes[1]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      if (proc_close($probe) !== 0) {
         yield true;
         return;
      }

      // ! A throwaway repository: one commit, then a working-tree edit
      $root = Temporaries::reserve('coverage-changes-fetch');
      $git = static function (array $arguments) use ($root): int {
         $process = proc_open(
            ['git', '-C', $root, '-c', 'user.name=Bootgly', '-c', 'user.email=tests@bootgly.invalid', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
         );
         if (is_resource($process) === false) {
            return 1;
         }
         /** @var array<int,resource> $pipes */
         stream_get_contents($pipes[1]);
         stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);

         return proc_close($process);
      };

      try {
         $git(['init', '--quiet']);
         mkdir("{$root}/sub");
         $base = "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n\$d = 4;\n\$e = 5;\n\$f = 6;\n";
         file_put_contents("{$root}/A.php", $base);
         file_put_contents("{$root}/sub/With space.php", $base);
         $git(['add', 'A.php', 'sub/With space.php']);
         $committed = $git(['commit', '--quiet', '--no-gpg-sign', '-m', 'base']) === 0;

         // ! User configuration and environment that would rewrite the diff
         $git(['config', 'diff.relative', 'true']);
         $git(['config', 'diff.interHunkContext', '5']);
         $options = getenv('GIT_DIFF_OPTS');
         putenv('GIT_DIFF_OPTS=--unified=3');

         $edited = "<?php\n\$a = 1;\n\$b = 20;\n\$c = 3;\n\$d = 4;\n\$e = 5;\n\$f = 60;\n\$g = 7;\n";
         file_put_contents("{$root}/A.php", $edited);
         file_put_contents("{$root}/sub/With space.php", $edited);

         try {
            // @ Read from a subdirectory: paths must still resolve from the root
            $Changes = new Changes('HEAD', "{$root}/sub");
            $lines = $committed ? $Changes->fetch() : [];
         }
         finally {
            putenv($options === false ? 'GIT_DIFF_OPTS' : "GIT_DIFF_OPTS={$options}");
         }
         $file = str_replace('\\', '/', (string) realpath("{$root}/A.php"));
         $spaced = str_replace('\\', '/', (string) realpath("{$root}/sub/With space.php"));

         yield (new Assertion(description: 'fetch() returns only the modified and added lines, whatever diff config and GIT_DIFF_OPTS say'))
            ->expect($lines[$file] ?? null)
            ->to->be([3 => true, 7 => true, 8 => true])
            ->assert();

         yield (new Assertion(description: 'a path with a space resolves from real git output'))
            ->expect($lines[$spaced] ?? null)
            ->to->be([3 => true, 7 => true, 8 => true])
            ->assert();

         // @ Content git would call binary, and blank context rows
         $git(['add', '-A']);
         $git(['commit', '--quiet', '--no-gpg-sign', '-m', 'second']);
         $git(['config', 'diff.suppressBlankEmpty', 'true']);
         $git(['config', 'diff.interHunkContext', '1']);
         file_put_contents("{$root}/.gitattributes", "Binary.php -diff\n");
         file_put_contents("{$root}/Binary.php", "<?php\n\$a = 1;\n");
         file_put_contents("{$root}/Nul.php", "<?php\n// \0\n\$a = 1;\n");
         file_put_contents("{$root}/Blank.php", "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n\$d = 4;\n\n\$e = 5;\n");
         file_put_contents("{$root}/asset.bin", "\0\1\2\n");
         file_put_contents("{$root}/notes.txt", "one\n");
         $git(['add', '-A']);
         $git(['commit', '--quiet', '--no-gpg-sign', '-m', 'third']);
         file_put_contents("{$root}/Binary.php", "<?php\n\$a = 1;\n\$b = 2;\n");
         file_put_contents("{$root}/Nul.php", "<?php\n// \0\n\$a = 1;\n\$b = 2;\n");
         file_put_contents("{$root}/Blank.php", "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n\$d = 40;\n\n\$e = 50;\n");
         file_put_contents("{$root}/asset.bin", "\0\1\2\n\3\n");
         file_put_contents("{$root}/notes.txt", "one\ntwo\n");

         // ! A literal pathspec mode would read the PHP pathspec as a file name
         $literal = getenv('GIT_LITERAL_PATHSPECS');
         putenv('GIT_LITERAL_PATHSPECS=1');
         try {
            $binary = (new Changes('HEAD', $root))->fetch();
         }
         finally {
            putenv($literal === false ? 'GIT_LITERAL_PATHSPECS' : "GIT_LITERAL_PATHSPECS={$literal}");
         }
         $path = static fn (string $name): string => str_replace('\\', '/', (string) realpath("{$root}/{$name}"));

         yield (new Assertion(description: 'a -diff attribute and a NUL byte still give their changed lines, whatever GIT_LITERAL_PATHSPECS says'))
            ->expect([$binary[$path('Binary.php')] ?? null, $binary[$path('Nul.php')] ?? null])
            ->to->be([[3 => true], [4 => true]])
            ->assert();

         yield (new Assertion(description: 'only PHP sources are diffed: a changed asset and a text file stay out'))
            ->expect(array_keys($binary))
            ->to->be([$path('Binary.php'), $path('Blank.php'), $path('Nul.php')])
            ->assert();

         yield (new Assertion(description: 'a blank context row between hunks keeps the line count'))
            ->expect($binary[$path('Blank.php')] ?? null)
            ->to->be([5 => true, 7 => true])
            ->assert();

         // @ A copy of a changed file, under a user config that detects copies
         $git(['add', '-A']);
         $git(['commit', '--quiet', '--no-gpg-sign', '-m', 'fourth']);
         $git(['config', 'diff.renames', 'copies']);
         $source = '';
         for ($n = 1; $n <= 12; $n++) {
            $source .= "\$v{$n} = {$n};\n";
         }
         file_put_contents("{$root}/Original.php", "<?php\n{$source}");
         $git(['add', '-A']);
         $git(['commit', '--quiet', '--no-gpg-sign', '-m', 'fifth']);
         file_put_contents("{$root}/Original.php", "<?php\n{$source}\$extra = 1;\n");
         file_put_contents("{$root}/Copy.php", "<?php\n" . str_replace('$v6 = 6;', '$v6 = 60;', $source));
         $git(['add', '-A']);
         $git(['commit', '--quiet', '--no-gpg-sign', '-m', 'sixth']);

         // ! An inherited GIT_DIR (a linked-worktree hook exports one) must not
         //   move the root git reads from a subdirectory
         $location = getenv('GIT_DIR');
         putenv("GIT_DIR={$root}/.git");
         try {
            $copied = (new Changes('HEAD~1..HEAD', "{$root}/sub"))->fetch();
         }
         finally {
            putenv($location === false ? 'GIT_DIR' : "GIT_DIR={$location}");
         }
         yield (new Assertion(description: 'a new file copied from a changed one counts in full, whatever diff.renames says'))
            ->expect(count($copied[$path('Copy.php')] ?? []))
            ->to->be(13)
            ->assert();

         yield (new Assertion(description: 'an inherited GIT_DIR read from a subdirectory still resolves the changed files'))
            ->expect($copied[$path('Original.php')] ?? null)
            ->to->be([14 => true])
            ->assert();

         // @ A merge range
         $git(['checkout', '--quiet', '-b', 'side']);
         file_put_contents("{$root}/Side.php", "<?php\n\$s = 1;\n");
         $git(['add', '-A']);
         $git(['commit', '--quiet', '--no-gpg-sign', '-m', 'side']);
         $git(['checkout', '--quiet', '-']);
         $merged = $git(['merge', '--quiet', '--no-ff', '--no-gpg-sign', '--no-edit', 'side']) === 0;

         $thrown = '';
         try {
            (new Changes('HEAD^!', $root))->fetch();
         }
         catch (RuntimeException $Exception) {
            $thrown = $Exception->getMessage();
         }
         yield (new Assertion(description: 'a range over a merge is refused instead of measuring a combined diff'))
            ->expect($merged && str_contains($thrown, 'spans a merge'))
            ->to->be(true)
            ->assert();

         $parent = (new Changes('HEAD^1..HEAD', $root))->fetch();
         yield (new Assertion(description: 'the first-parent range of a merge gives the merged lines'))
            ->expect($parent[$path('Side.php')] ?? null)
            ->to->be([1 => true, 2 => true])
            ->assert();

         yield (new Assertion(description: 'fetch() keeps its result in $lines'))
            ->expect($Changes->lines === $lines)
            ->to->be(true)
            ->assert();

         // @ A range git does not know
         $thrown = '';
         try {
            (new Changes('no-such-revision-bootgly', $root))->fetch();
         }
         catch (RuntimeException $Exception) {
            $thrown = $Exception->getMessage();
         }

         yield (new Assertion(description: 'an unknown revision throws with the range in the message'))
            ->expect(str_contains($thrown, "'no-such-revision-bootgly'"))
            ->to->be(true)
            ->assert();

         // @ A directory that does not exist
         $thrown = false;
         try {
            (new Changes('HEAD', "{$root}/missing"))->fetch();
         }
         catch (RuntimeException) {
            $thrown = true;
         }

         yield (new Assertion(description: 'a missing directory throws'))
            ->expect($thrown)
            ->to->be(true)
            ->assert();
      }
      finally {
         // ! Remove the throwaway repository (git objects included)
         $Files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
         );
         foreach ($Files as $File) {
            /** @var SplFileInfo $File */
            $File->isDir() && ! $File->isLink() ? @rmdir($File->getPathname()) : @unlink($File->getPathname());
         }
         @rmdir($root);
      }
   })
);
