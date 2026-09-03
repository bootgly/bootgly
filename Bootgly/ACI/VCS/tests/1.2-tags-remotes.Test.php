<?php

namespace Bootgly\ACI\VCS;


use function array_diff;
use function array_keys;
use function assert;
use function bin2hex;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\VCS;


/**
 * `Tags` — the version-shaped tags of a tree, newest first, peeled to their
 * commits, with the annotated message as notes; `Remotes` — list, add and
 * find a remote by the repository it points at whatever the URL spelling.
 */

return new Test(
   description: 'Tags lists version tags newest first with their commits and notes; Remotes finds a remote by repository',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-vcs-tags-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($base, 0775, true);

      $G = '-c user.name=Bootgly -c user.email=tests@bootgly.local -c commit.gpgsign=false';
      $Run = static function (string $directory, string $command): string {
         $output = [];
         exec('git -C ' . escapeshellarg($directory) . " {$command} 2>/dev/null", $output);

         return $output[0] ?? '';
      };
      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      try {
         // # Four commits, tagged every which way
         $repo = "{$base}/repo";
         mkdir($repo, 0775, true);
         $Run($repo, 'init --quiet -b main');
         $commits = [];
         for ($index = 1; $index <= 4; $index++) {
            file_put_contents("{$repo}/f.txt", "c{$index}\n");
            $Run($repo, 'add f.txt');
            $Run($repo, "{$G} commit --quiet -m c{$index}");
            $commits[$index] = $Run($repo, 'rev-parse HEAD');
         }
         $Run($repo, "{$G} tag -a v0.9.0 -m 'first cut' {$commits[1]}");
         $Run($repo, "tag v1.0.0-beta.9 {$commits[2]}");
         $Run($repo, "{$G} tag -a v1.0.0-beta.10 -m 'Beta ten' -m 'Second paragraph.' {$commits[3]}");
         $Run($repo, "{$G} tag -a v1.0.0 -m 'Stable' {$commits[4]}");
         $Run($repo, "tag latest {$commits[4]}");
         $blob = $Run($repo, 'rev-parse HEAD:f.txt');
         $Run($repo, "tag v2.0.0 {$blob}");
         // ! Same precedence as `v0.9.0`, another name — the order must stay total
         $Run($repo, "tag 0.9.0 {$commits[1]}");

         $VCS = new VCS($repo);
         $tags = $VCS->Tags->list();

         yield assert(
            assertion: array_keys($tags) === ['v1.0.0', 'v1.0.0-beta.10', 'v1.0.0-beta.9', '0.9.0', 'v0.9.0'],
            description: 'list() is newest first by SemVer — beta.10 above beta.9, the stable above both, equal precedence by name — and skips `latest` and a tag on a blob'
         );
         yield assert(
            assertion: $tags['v1.0.0']['commit'] === $commits[4] && $tags['v1.0.0-beta.10']['commit'] === $commits[3]
               && $tags['v1.0.0-beta.9']['commit'] === $commits[2] && $tags['v0.9.0']['commit'] === $commits[1],
            description: 'every tag is peeled to its commit — annotated (tag object) and lightweight alike'
         );
         yield assert(
            assertion: $tags['v1.0.0']['annotated'] === true && $tags['v1.0.0-beta.9']['annotated'] === false
               && (string) $tags['v1.0.0-beta.10']['SemVer'] === '1.0.0-beta.10',
            description: 'each entry carries whether the tag is annotated and its parsed SemVer'
         );

         // # note()
         yield assert(
            assertion: $VCS->Tags->read('v1.0.0-beta.10') === "Beta ten\n\nSecond paragraph."
               && $VCS->Tags->read('v1.0.0') === 'Stable',
            description: 'read() reads an annotated tag\'s subject and body'
         );
         yield assert(
            assertion: $VCS->Tags->read('v1.0.0-beta.9') === '' && $VCS->Tags->read('v9.9.9') === '',
            description: 'read() is empty for a lightweight tag (no message of its own) and for a missing tag'
         );

         // # Remotes
         yield assert(
            assertion: $VCS->Remotes->list() === [] && $VCS->Remotes->find($repo) === null,
            description: 'a fresh repository has no remote to list or find'
         );

         $added = $VCS->Remotes->add('origin', "file://{$repo}/");
         $VCS->Remotes->add('mirror', 'git@github.com:Bootgly/bootgly.kit.git');

         yield assert(
            assertion: $added === true && $VCS->Remotes->list() === ['mirror' => 'git@github.com:Bootgly/bootgly.kit.git', 'origin' => "file://{$repo}/"],
            description: 'add() registers a remote and list() reads the names with their fetch URLs'
         );
         yield assert(
            assertion: $VCS->Remotes->find($repo) === 'origin' && $VCS->Remotes->find("{$repo}/") === 'origin',
            description: 'find() matches a local path against its `file://` spelling with a trailing slash'
         );
         yield assert(
            assertion: $VCS->Remotes->find('https://github.com/bootgly/bootgly.kit') === 'mirror'
               && $VCS->Remotes->find('ssh://git@github.com:22/bootgly/bootgly.kit/') === 'mirror'
               && $VCS->Remotes->find('https://github.com/bootgly/bootgly') === null,
            description: 'find() matches https, scp-like and ssh:// spellings of one repository, case and `.git` aside'
         );
         yield assert(
            assertion: $VCS->Remotes->add('origin', $repo) === false,
            description: 'add() reports a name already taken'
         );

         // # A bare `host/path` is a directory, not the repository it spells
         $VCS->Remotes->add('bare', 'github.com/bootgly/bootgly.kit');

         yield assert(
            assertion: Remotes::normalize('github.com/bootgly/bootgly.kit') === ''
               && $VCS->Remotes->find('https://github.com/bootgly/bootgly.kit') === 'mirror',
            description: 'a scheme-less relative string never matches the canonical repository'
         );
      }
      finally {
         $Erase($base);
      }
   }
);
