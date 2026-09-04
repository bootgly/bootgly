<?php

namespace Bootgly\API\Environment;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_VERSION;
use const BOOTGLY_WORKING_DIR;
use const PHP_BINARY;
use function assert;
use function bin2hex;
use function escapeshellarg;
use function file_exists;
use function file_put_contents;
use function getenv;
use function getmypid;
use function is_dir;
use function json_decode;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rmdir;
use function shell_exec;
use function str_repeat;
use function strlen;
use function substr;
use function trim;
use function unlink;
use function var_export;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should identify the running build by version and commit',
   test: function () {
      // @ A build without a commit degrades to the version alone
      $Unknown = new Build('1.0.0');

      yield assert(
         assertion: $Unknown->commit === null && $Unknown->source === null
            && $Unknown->abbreviation === null
            && $Unknown->identify() === 'v1.0.0',
         description: 'An unidentifiable source reports the version alone'
      );

      // @ A build with a commit abbreviates it to git's short-hash width
      $Known = new Build('1.0.0', 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'git');

      yield assert(
         assertion: $Known->abbreviation === 'a1b2c3d4'
            && strlen((string) $Known->abbreviation) === Build::ABBREVIATION
            && $Known->identify() === 'v1.0.0 (a1b2c3d4)',
         description: 'A known commit is abbreviated into the identity line'
      );

      // @ The abbreviation always mirrors the commit — it is derived, never stored
      $Other = new Build('2.0.0', 'ffffffffffffffffffffffffffffffffffffffff', 'composer');

      yield assert(
         assertion: $Other->abbreviation === substr((string) $Other->commit, 0, Build::ABBREVIATION)
            && $Other->identify() === 'v2.0.0 (ffffffff)',
         description: 'The abbreviation derives from the commit it belongs to'
      );

      // ---

      // @ Detecting the running framework
      $Build = Build::detect();
      $HEAD = trim((string) shell_exec('git -C ' . escapeshellarg(BOOTGLY_ROOT_BASE) . ' rev-parse HEAD 2>/dev/null'));

      yield assert(
         assertion: $Build->version === BOOTGLY_VERSION,
         description: 'The detected build carries the framework version'
      );

      // @ The build stamp — the third precedence step, and the only one a
      //   published image can answer with. It is unreachable from a git
      //   checkout, so a host run proves it in a child process standing in a
      //   tree with neither git metadata nor a Composer manifest.
      // ! The framework's own scratch area, not `/tmp`: `storage/tests/` is where
      //   every other suite writes, it is re-created on demand, and it keeps the
      //   probe inside the tree the runner already owns.
      $base = BOOTGLY_WORKING_DIR . 'storage/tests/build-detect-'
         . getmypid() . '-' . bin2hex(random_bytes(4));
      $made = @mkdir($base, 0775, true);

      yield assert(
         assertion: $made === true && is_dir($base) === true,
         description: "The probe's scratch directory was created — {$base}"
      );
      $probe = "{$base}/probe.php";
      $stamp = str_repeat('ab', 20);
      file_put_contents($probe, <<<'PHP'
      <?php
      define('BOOTGLY_ROOT_BASE', $argv[1]);
      define('BOOTGLY_ROOT_DIR', $argv[1] . DIRECTORY_SEPARATOR);
      define('BOOTGLY_VERSION', '9.9.9-probe');
      require $argv[2];
      $Build = Bootgly\API\Environment\Build::detect();
      echo json_encode([$Build->version, $Build->commit, $Build->source]);
      PHP);

      $command = 'BOOTGLY_FRAMEWORK_SHA=' . escapeshellarg($stamp)
         // ! The RUNNING interpreter, never `php` from PATH: `Build.php` uses
         //   property hooks, so an 8.3 on PATH would kill the child at parse
         //   time and report a defect the framework does not have. Its stderr
         //   is dropped for the same reason the sibling `git` call drops its
         //   own — a child's noise ahead of the AI_AGENT line breaks parsing.
         . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe)
         . ' ' . escapeshellarg($base)
         . ' ' . escapeshellarg(BOOTGLY_ROOT_DIR . 'Bootgly/API/Environment/Build.php')
         . ' 2>/dev/null';
      /** @var mixed $stamped */
      $stamped = json_decode((string) shell_exec($command), true);

      unlink($probe);
      rmdir($base);

      yield assert(
         assertion: $stamped === ['9.9.9-probe', $stamp, 'build'],
         description: 'With no git and no Composer manifest the commit comes from the build stamp — '
            . var_export($stamped, true)
      );

      // ? A published image is NOT a git working tree: `.git` never crosses the
      //   build, by design. The commit then comes from the build-time SHA, and
      //   asserting `source === 'git'` there would fail every `docker run …
      //   bootgly test` — a flow the Docker guide documents.
      if (file_exists(BOOTGLY_ROOT_DIR . '.git') === false) {
         $stamped = (string) getenv('BOOTGLY_FRAMEWORK_SHA');

         // ? A published image: `.git` never crosses the build by design, so the
         //   commit has to come from the stamp the build carried in — the one
         //   answer a container can give to "which code am I running".
         // ? With no stamp there are still two honest answers: a Composer
         //   install under `vendor/bootgly/bootgly` reads the installed
         //   manifest (`Build.php`'s second precedence step, and these tests
         //   ship in the package), and everything else has no commit at all.
         yield assert(
            assertion: preg_match('#^[0-9a-f]{40}$#', $stamped) === 1
               ? ($Build->source === 'build' && $Build->commit === $stamped)
               : (
                  $Build->source === 'composer'
                     ? preg_match('#^[0-9a-f]{40}$#', (string) $Build->commit) === 1
                     : ($Build->source === null && $Build->commit === null)
               ),
            description: 'Outside a git working tree the commit comes from the build stamp, '
               . 'the Composer manifest, or nowhere — '
               . var_export([$Build->source, $Build->commit, $stamped], true)
         );

         return;
      }

      // ? `git` is the independent oracle, and it can legitimately refuse to
      //   answer for this tree — a foreign owner (`detected dubious ownership`,
      //   the shape a bind-mounted checkout takes inside a container), or no
      //   `git` on PATH at all. `Build::detect()` reads `.git` itself and is
      //   unaffected, so what is missing is the comparison, not the behaviour:
      //   assert what still holds instead of failing on the oracle's absence.
      if (preg_match('#^[0-9a-f]{40}$#', $HEAD) !== 1) {
         yield assert(
            assertion: $Build->source === 'git'
               && preg_match('#^[0-9a-f]{40}$#', (string) $Build->commit) === 1
               && $Build->identify() === "v{$Build->version} ({$Build->abbreviation})",
            description: 'A git working tree still names git as the source, with `git` unable to '
               . 'confirm which commit — ' . var_export([$Build->source, $Build->commit], true)
         );

         return;
      }

      yield assert(
         assertion: $Build->commit === $HEAD
            && $Build->source === 'git',
         description: 'The detected commit matches the git working tree HEAD'
      );

      yield assert(
         assertion: $Build->identify() === "v{$Build->version} ({$Build->abbreviation})"
            && $Build->abbreviation === substr($HEAD, 0, Build::ABBREVIATION),
         description: 'The identity line pairs the version with the short commit'
      );
   }
);
