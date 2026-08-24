<?php

namespace Bootgly\commands;


use function array_diff;
use function assert;
use function exec;
use function escapeshellarg;
use function file_put_contents;
use function getenv;
use function getmypid;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function putenv;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Every project birth lands in a git repository of its own — or degrades, never fails',
   test: function () {
      // ! track() is what puts a freshly minted project under version control:
      //   init + stage + one conventional commit. The kit repository ABOVE the
      //   projects base must never suppress it; a project repository BELOW it
      //   always must (a nested project belongs to its parent's repo).
      $Track = new ReflectionMethod(ProjectCommand::class, 'track');
      $Command = new ProjectCommand;

      $root = sys_get_temp_dir() . '/bootgly-track-' . getmypid();
      $base = "{$root}/projects/";

      $erase = function (string $target) use (&$erase): void {
         if (is_dir($target) === false) {
            if (is_file($target) === true) {
               unlink($target);
            }
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };
      $erase($root);

      // ! Deterministic git environment — the suite must not depend on (or
      //   commit as) whoever runs it
      $environment = [
         'GIT_CONFIG_GLOBAL' => getenv('GIT_CONFIG_GLOBAL'),
         'GIT_CONFIG_SYSTEM' => getenv('GIT_CONFIG_SYSTEM'),
         'GIT_CONFIG_NOSYSTEM' => getenv('GIT_CONFIG_NOSYSTEM'),
      ];
      $identified = "{$root}/gitconfig";

      $git = static function (string $dir, string $command): string {
         $output = [];
         exec('git -C ' . escapeshellarg($dir) . " {$command} 2>/dev/null", $output);

         return implode("\n", $output);
      };

      $mint = static function (string $target): void {
         mkdir($target, 0755, true);
         file_put_contents("{$target}/scaffold.php", "<?php\nreturn true;\n");
      };

      try {
         mkdir($base, 0755, true);
         file_put_contents($identified, "[user]\n\tname = Bootgly Test\n\temail = test@bootgly.local\n");
         putenv("GIT_CONFIG_GLOBAL={$identified}");
         putenv('GIT_CONFIG_SYSTEM=/dev/null');
         putenv('GIT_CONFIG_NOSYSTEM=1');

         // # A kit-shaped ancestor: the root ABOVE the projects base is a repo
         exec('git -C ' . escapeshellarg($root) . ' init --quiet 2>/dev/null');

         // @ Birth — one conventional commit, clean status
         $mint("{$base}App");
         $Track->invoke($Command, $base, 'App', []);

         yield assert(
            assertion: is_dir("{$base}App/.git") === true
               && $git("{$base}App", 'log -1 --format=%s') === 'chore: create App project scaffold'
               && $git("{$base}App", 'rev-list --count HEAD') === '1'
               && $git("{$base}App", 'status --porcelain') === '',
            description: 'a fresh project gets its own repository with the scaffold as one clean conventional commit'
         );

         // @ The kit repository above the base never suppresses the birth —
         //   proven by the case above running INSIDE a repo-carrying root

         // @ --no-git opts out
         $mint("{$base}Plain");
         $Track->invoke($Command, $base, 'Plain', ['no-git' => true]);

         yield assert(
            assertion: is_dir("{$base}Plain/.git") === false,
            description: '--no-git skips the repository entirely'
         );

         // @ A nested project joins its parent repository
         $mint("{$base}App/API");
         $Track->invoke($Command, $base, 'App/API', []);

         yield assert(
            assertion: is_dir("{$base}App/API/.git") === false
               && $git("{$base}App", 'status --porcelain') !== '',
            description: 'a nested project is governed by the parent project repository, never nested'
         );

         // @ Identity unset: initialized and staged, but nothing committed and
         //   nothing fabricated
         putenv('GIT_CONFIG_GLOBAL=/dev/null');
         $mint("{$base}Anon");
         $Track->invoke($Command, $base, 'Anon', []);

         yield assert(
            assertion: is_dir("{$base}Anon/.git") === true
               && $git("{$base}Anon", 'rev-list --count HEAD') === ''
               && $git("{$base}Anon", 'diff --cached --name-only') === 'scaffold.php',
            description: 'an identity-less machine keeps the repo initialized with the scaffold staged — no commit is fabricated'
         );
      }
      finally {
         foreach ($environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
         }
         $erase($root);
      }
   }
);
