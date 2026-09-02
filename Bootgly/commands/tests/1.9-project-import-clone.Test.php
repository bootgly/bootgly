<?php

namespace Bootgly\commands;


use function array_diff;
use function assert;
use function chmod;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function getmypid;
use function implode;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function rmdir;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;
use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'A git URL import stays a working clone — history, origin and modes kept',
   test: function () {
      // ! The import used to `--depth 1` and strip `.git` before copying.
      //   Projects are the unit of versioning now: the clone that lands in
      //   `projects/` must be the repository the user keeps committing and
      //   pushing from.
      $Command = new ProjectsCommand;

      $registry = Projects::CONSUMER_DIR . 'Bootgly.projects.php';
      $snapshot = is_file($registry) ? file_get_contents($registry) : null;
      $Memo = new ReflectionProperty(Projects::class, 'registry');

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

      $git = static function (string $dir, string $command): string {
         $output = [];
         exec('git -C ' . escapeshellarg($dir) . " {$command} 2>/dev/null", $output);

         return implode("\n", $output);
      };

      // ! A local fixture repository with three commits and one 0755 file
      $fixture = sys_get_temp_dir() . '/bootgly-import-clone-' . getmypid();
      $erase($fixture);
      mkdir($fixture, 0755, true);
      $identity = '-c user.name=Probe -c user.email=probe@bootgly.local -c commit.gpgsign=false';
      file_put_contents("{$fixture}/Fix.Project.php", "<?php\nreturn null;\n");
      file_put_contents("{$fixture}/hook.sh", "#!/bin/sh\n");
      chmod("{$fixture}/hook.sh", 0755);
      exec('git -C ' . escapeshellarg($fixture) . ' init --quiet 2>/dev/null');
      exec('git -C ' . escapeshellarg($fixture) . ' add Fix.Project.php hook.sh 2>/dev/null');
      exec('git -C ' . escapeshellarg($fixture) . " {$identity} commit --quiet -m one 2>/dev/null");
      file_put_contents("{$fixture}/hook.sh", "#!/bin/sh\necho two\n");
      exec('git -C ' . escapeshellarg($fixture) . " {$identity} commit --quiet -am two 2>/dev/null");
      file_put_contents("{$fixture}/hook.sh", "#!/bin/sh\necho three\n");
      exec('git -C ' . escapeshellarg($fixture) . " {$identity} commit --quiet -am three 2>/dev/null");

      try {
         // @ Import under a NEW leaf — the rename is the one legitimate dirt
         // ! file:// on purpose: git DISCARDS --depth for a plain local path
         //   ("--depth is ignored in local clones"), so a path fixture could
         //   not tell a shallow clone from a full one
         $done = $Command->import(["file://{$fixture}", 'ImportClone'], ['yes' => true]);
         $target = Projects::CONSUMER_DIR . 'ImportClone';

         yield assert(
            assertion: $done === true && is_dir("{$target}/.git") === true,
            description: 'the imported project keeps its .git'
         );
         yield assert(
            assertion: $git($target, 'rev-list --count HEAD') === '3',
            description: 'the full history arrives — no --depth 1 truncation'
         );
         yield assert(
            assertion: str_contains($git($target, 'remote get-url origin'), $fixture) === true,
            description: 'the origin remote survives, so the user keeps pushing from projects/'
         );
         yield assert(
            assertion: (fileperms("{$target}/hook.sh") & 0111) !== 0,
            description: 'the execute bit survives the staged copy'
         );

         // @ The signature rename shows as reviewable dirt, never a commit
         $status = $git($target, 'status --porcelain');

         yield assert(
            assertion: str_contains($status, 'Fix.Project.php') === true
               && str_contains($status, 'ImportClone.Project.php') === true
               && $git($target, 'rev-list --count HEAD') === '3',
            description: 'the leaf rename is left uncommitted for the user to review'
         );
      }
      finally {
         // ! Leave the repository as it was found
         $erase(Projects::CONSUMER_DIR . 'ImportClone');
         $erase($fixture);
         if ($snapshot !== null && $snapshot !== false) {
            file_put_contents($registry, $snapshot);
         }
         $Memo->setValue(null, null);
      }
   }
);
