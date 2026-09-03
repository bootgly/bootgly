<?php

namespace Bootgly\ACI\VCS;


use function array_diff;
use function assert;
use function bin2hex;
use function count;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function getenv;
use function getmypid;
use function is_dir;
use function is_executable;
use function is_file;
use function is_link;
use function mkdir;
use function putenv;
use function random_bytes;
use function rename;
use function rmdir;
use function scandir;
use function str_starts_with;
use function sys_get_temp_dir;
use function unlink;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Git` — the engine: binary lookup, one child per command without a shell,
 * the environment scrubbed of redirections, and the tree queries every
 * consumer builds on (`check`, `resolve`, `describe`, `inspect`, `checkout`,
 * `fetch`).
 */

return new Test(
   description: 'Git runs commands in one working tree and answers the tree queries the VCS builds on',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-vcs-git-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($base, 0775, true);

      // ! Pinned identity: fixtures must not depend on the machine's git config
      $G = '-c user.name=Bootgly -c user.email=tests@bootgly.local -c commit.gpgsign=false';
      $run = static function (string $directory, string $command): string {
         $output = [];
         exec('git -C ' . escapeshellarg($directory) . " {$command} 2>/dev/null", $output);

         return $output[0] ?? '';
      };
      $erase = function (string $target) use (&$erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      try {
         // # The binary
         $binary = Git::locate();

         yield assert(
            assertion: $binary !== null && str_starts_with($binary, '/') && is_executable($binary),
            description: 'locate() finds an absolute, executable git on PATH'
         );

         // # A tree with two commits
         $repo = "{$base}/repo";
         mkdir($repo, 0775, true);
         $run($repo, 'init --quiet -b main');
         file_put_contents("{$repo}/f.txt", "one\n");
         file_put_contents("{$repo}/g.txt", "constant\n");
         $run($repo, 'add f.txt g.txt');
         $run($repo, "{$G} commit --quiet -m c1");
         $first = $run($repo, 'rev-parse HEAD');
         file_put_contents("{$repo}/f.txt", "two\n");
         $run($repo, 'add f.txt');
         $run($repo, "{$G} commit --quiet -m c2");
         $second = $run($repo, 'rev-parse HEAD');

         $Git = new Git("{$repo}/");

         yield assert(
            assertion: $Git->path === $repo && $Git->check() === true,
            description: 'check() accepts the top of a working tree (trailing separator dropped from the path)'
         );

         mkdir("{$repo}/inner", 0775, true);
         mkdir("{$base}/plain", 0775, true);

         yield assert(
            assertion: new Git("{$repo}/inner")->check() === false && new Git("{$base}/plain")->check() === false,
            description: 'check() refuses a subdirectory of a tree and a directory that is no tree'
         );
         rmdir("{$repo}/inner");

         // # resolve()
         yield assert(
            assertion: $Git->resolve('HEAD') === $second && $Git->resolve('HEAD~1') === $first
               && $Git->resolve('nope') === null && $Git->resolve('HEAD:f.txt') === null,
            description: 'resolve() names the commit a reference peels to, and null for a miss or a blob'
         );

         // # execute() / query()
         $lines = [];
         $status = $Git->execute(['log', '--format=%s', 'HEAD'], function (string $line) use (&$lines): void {
            $lines[] = $line;
         });

         yield assert(
            assertion: $status === 0 && $lines === ['c2', 'c1'] && $Git->output === "c2\nc1\n" && $Git->status === 0,
            description: 'execute() streams each line to the callback and keeps the combined output and status'
         );
         yield assert(
            assertion: $Git->execute(['rev-parse', '--verify', 'no-such-ref']) !== 0
               && $Git->query(['rev-parse', '--verify', 'no-such-ref']) === null
               && $Git->query(['rev-parse', 'HEAD']) === $second,
            description: 'query() is the trimmed output on success and null on failure'
         );

         // # The environment cannot redirect a command elsewhere
         $other = "{$base}/other";
         mkdir($other, 0775, true);
         $run($other, 'init --quiet -b main');
         $run($other, "{$G} commit --quiet --allow-empty -m elsewhere");
         putenv("GIT_DIR={$other}/.git");
         putenv("GIT_WORK_TREE={$other}");
         try {
            $resolved = $Git->resolve('HEAD');
         }
         finally {
            putenv('GIT_DIR');
            putenv('GIT_WORK_TREE');
         }

         yield assert(
            assertion: $resolved === $second,
            description: 'GIT_DIR / GIT_WORK_TREE in the environment do not redirect a command away from the tree'
         );

         // # describe()
         yield assert(
            assertion: $Git->describe() === null,
            description: 'describe() is null while no tag is reachable'
         );
         $run($repo, "{$G} tag -a v1.0.0-beta.1 -m v1.0.0-beta.1 {$first}");

         yield assert(
            assertion: $Git->describe() === ['tag' => 'v1.0.0-beta.1', 'distance' => 1]
               && $Git->describe($first) === ['tag' => 'v1.0.0-beta.1', 'distance' => 0],
            description: 'describe() splits `<tag>-<n>-g<hash>` from the right — a hyphenated tag stays whole'
         );

         // # inspect()
         yield assert(
            assertion: $Git->inspect() === [],
            description: 'inspect() is empty on a clean tree'
         );
         file_put_contents("{$repo}/g.txt", "edited\n");
         file_put_contents("{$repo}/new.txt", "mine\n");
         file_put_contents("{$repo}/staged.txt", "staged\n");
         $run($repo, 'add staged.txt');
         rename("{$repo}/f.txt", "{$repo}/moved.txt");
         $run($repo, 'add f.txt moved.txt');
         $changes = $Git->inspect();

         yield assert(
            assertion: ($changes['g.txt'] ?? null) === ' M' && ($changes['new.txt'] ?? null) === '??'
               && ($changes['staged.txt'] ?? null) === 'A ' && ($changes['moved.txt'] ?? null) === 'R '
               && isSet($changes['f.txt']) === false && count($changes) === 4,
            description: 'inspect() keys every change by path with its porcelain code — a rename by its new name only'
         );
         $run($repo, 'reset --quiet --hard HEAD');
         unlink("{$repo}/new.txt");

         // # inspect() where git cannot report
         yield assert(
            assertion: new Git("{$base}/plain")->inspect() === null,
            description: 'inspect() is null — never "clean" — where git cannot report'
         );

         // # describe(--match) — a nearer tag of another shape does not hide the release
         $run($repo, "tag nightly HEAD");

         yield assert(
            assertion: $Git->describe() === ['tag' => 'nightly', 'distance' => 0]
               && $Git->describe('HEAD', ['v[0-9]*', '[0-9]*']) === ['tag' => 'v1.0.0-beta.1', 'distance' => 1],
            description: 'describe() takes --match globs, so a `nightly` tag on HEAD does not hide the release behind it'
         );
         $run($repo, 'tag -d nightly');

         // # checkout() — the tag, never a branch of the same name
         $run($repo, "branch v1.0.0-beta.1 {$second}");
         $status = $Git->checkout('refs/tags/v1.0.0-beta.1');

         yield assert(
            assertion: $status === 0 && $Git->resolve('HEAD') === $first,
            description: 'checkout(refs/tags/<tag>) lands on the tag\'s commit although a branch shares the name'
         );

         // # fetch() — tags only, no branch of the remote
         $clone = "{$base}/clone";
         exec('git clone --quiet ' . escapeshellarg($repo) . ' ' . escapeshellarg($clone) . ' 2>/dev/null');
         $run($clone, 'tag -d v1.0.0-beta.1');
         // ! Created AFTER the clone: a tag the clone must gain, a branch it must not
         $run($repo, "{$G} tag -a v1.0.0-beta.2 -m notes {$second}");
         $run($repo, "branch side {$second}");
         $Clone = new Git($clone);
         $before = $Clone->query(['tag', '--no-column', '-l']);
         $status = $Clone->fetch('origin');
         $after = $Clone->query(['for-each-ref', '--format=%(refname)', 'refs/tags']);
         $branches = $Clone->query(['for-each-ref', '--format=%(refname)', 'refs/remotes/origin/side']);

         yield assert(
            assertion: $before === '' && $status === 0
               && $after === "refs/tags/v1.0.0-beta.1\nrefs/tags/v1.0.0-beta.2" && $branches === '',
            description: 'fetch(remote) brings every tag and no branch'
         );
         yield assert(
            assertion: $Clone->fetch("{$base}/nowhere") !== 0,
            description: 'fetch() reports an unreachable remote through its status'
         );

         // # A tag retagged upstream replaces the stale local one
         $run($repo, "{$G} tag -d v1.0.0-beta.2");
         $run($repo, "{$G} tag -a v1.0.0-beta.2 -m retagged {$first}");
         $status = $Clone->fetch('origin');

         yield assert(
            assertion: $status === 0 && $Clone->resolve('refs/tags/v1.0.0-beta.2') === $first,
            description: 'fetch() follows a tag the remote moved instead of failing on "would clobber existing tag"'
         );

         // # No binary
         $thrown = false;
         $PATH = getenv('PATH');
         putenv("PATH={$base}/plain");
         try {
            new Git($repo);
         }
         catch (RuntimeException) {
            $thrown = true;
         }
         finally {
            putenv("PATH={$PATH}");
         }

         yield assert(
            assertion: $thrown === true && Git::locate() !== null,
            description: 'a PATH without git is refused at construction — and PATH restored finds it again'
         );
      }
      finally {
         $erase($base);
      }
   }
);
