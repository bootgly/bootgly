<?php

namespace Bootgly\commands;


use function array_diff;
use function assert;
use function escapeshellarg;
use function exec;
use function explode;
use function file_put_contents;
use function getenv;
use function getmypid;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function posix_geteuid;
use function posix_getpwuid;
use function putenv;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function trim;
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
         // ! An identity the shell hands down would author the commits below
         'EMAIL' => getenv('EMAIL'),
         'GIT_AUTHOR_NAME' => getenv('GIT_AUTHOR_NAME'),
         'GIT_AUTHOR_EMAIL' => getenv('GIT_AUTHOR_EMAIL'),
         'GIT_COMMITTER_NAME' => getenv('GIT_COMMITTER_NAME'),
         'GIT_COMMITTER_EMAIL' => getenv('GIT_COMMITTER_EMAIL'),
      ];
      foreach (['EMAIL', 'GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL'] as $handed) {
         putenv($handed);
      }
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

         // @ An identity handed down by the environment — the kit image ships
         //   one — is the user's word as much as a config file: it authors
         putenv('GIT_AUTHOR_NAME=Ada Lovelace');
         putenv('GIT_AUTHOR_EMAIL=ada@example.com');
         putenv('GIT_COMMITTER_NAME=Ada Lovelace');
         putenv('GIT_COMMITTER_EMAIL=ada@example.com');
         $mint("{$base}Env");
         $Track->invoke($Command, $base, 'Env', []);
         yield assert(
            assertion: $git("{$base}Env", 'rev-list --count HEAD') === '1'
               && $git("{$base}Env", "log -1 --format='%an <%ae>'") === 'Ada Lovelace <ada@example.com>',
            description: 'an identity from GIT_AUTHOR_*/GIT_COMMITTER_* authors the initial commit'
         );
         foreach (['GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL'] as $handed) {
            putenv($handed);
         }
         // @ What git would fill in by itself is not the user's word — each
         //   half of the identity is refused on its own, in a shape git COULD
         //   complete: an email from EMAIL on any host, a name from the OS
         //   account where it has one (gecos). A gate that let a half through
         //   would commit here, not fail inside git
         $clear = static function (): void {
            foreach (['EMAIL', 'GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL'] as $handed) {
               putenv($handed);
            }
         };

         // # Author email missing — EMAIL would fill it
         putenv('GIT_CONFIG_GLOBAL=/dev/null');
         putenv('GIT_AUTHOR_NAME=Ada Lovelace');
         putenv('GIT_COMMITTER_NAME=Ada Lovelace');
         putenv('GIT_COMMITTER_EMAIL=ada@example.com');
         putenv('EMAIL=guess@example.com');
         $mint("{$base}Guess");
         $Track->invoke($Command, $base, 'Guess', []);
         yield assert(
            assertion: is_dir("{$base}Guess/.git") === true && $git("{$base}Guess", 'rev-list --count HEAD') === '',
            description: 'an EMAIL the shell exports does not fill the author email — auto-detected halves never author'
         );
         $clear();

         // # Committer email missing — EMAIL would fill it, the name is in the config
         $named = "{$root}/gitconfig-named";
         file_put_contents($named, "[user]\n\tname = Auto Detect\n");
         putenv("GIT_CONFIG_GLOBAL={$named}");
         putenv('GIT_AUTHOR_NAME=Ada Lovelace');
         putenv('GIT_AUTHOR_EMAIL=ada@example.com');
         putenv('EMAIL=guess@example.com');
         $mint("{$base}Author");
         $Track->invoke($Command, $base, 'Author', []);
         yield assert(
            assertion: is_dir("{$base}Author/.git") === true && $git("{$base}Author", 'rev-list --count HEAD') === '',
            description: 'GIT_AUTHOR_* without GIT_COMMITTER_EMAIL does not author — the committer email would be auto-detected'
         );
         $clear();

         // # The name halves — only observable where the account has a gecos
         //   name for git to take; elsewhere git refuses by itself and the
         //   gate is not what is being measured
         $gecos = trim(explode(',', (string) (posix_getpwuid(posix_geteuid())['gecos'] ?? ''))[0]);
         if ($gecos !== '') {
            $half = "{$root}/gitconfig-half";
            file_put_contents($half, "[user]\n\temail = only@example.com\n");
            putenv("GIT_CONFIG_GLOBAL={$half}");
            putenv('GIT_COMMITTER_NAME=Ada Lovelace');
            $mint("{$base}Half");
            $Track->invoke($Command, $base, 'Half', []);
            yield assert(
               assertion: is_dir("{$base}Half/.git") === true && $git("{$base}Half", 'rev-list --count HEAD') === '',
               description: 'a configured user.email without user.name does not author — the author name would be auto-detected'
            );
            $clear();

            putenv("GIT_CONFIG_GLOBAL={$half}");
            putenv('GIT_AUTHOR_NAME=Ada Lovelace');
            $mint("{$base}Committer");
            $Track->invoke($Command, $base, 'Committer', []);
            yield assert(
               assertion: is_dir("{$base}Committer/.git") === true && $git("{$base}Committer", 'rev-list --count HEAD') === '',
               description: 'GIT_AUTHOR_NAME without GIT_COMMITTER_NAME does not author — the committer name would be auto-detected'
            );
            $clear();
         }
         else {
            yield assert(assertion: true, description: 'Skipped: the name halves cannot be observed — this account has no gecos name for git to take');
         }
      }
      finally {
         foreach ($environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
         }
         $erase($root);
      }
   }
);
