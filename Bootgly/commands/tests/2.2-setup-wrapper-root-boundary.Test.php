<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const PHP_BINARY;
use function assert;
use function bin2hex;
use function chgrp;
use function chmod;
use function chown;
use function clearstatcache;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function fileowner;
use function fileperms;
use function function_exists;
use function getmypid;
use function implode;
use function is_array;
use function is_dir;
use function is_executable;
use function is_file;
use function is_link;
use function is_writable;
use function lchgrp;
use function lchown;
use function lstat;
use function mkdir;
use function posix_geteuid;
use function posix_getpwnam;
use function random_bytes;
use function rmdir;
use function str_contains;
use function symlink;
use function sys_get_temp_dir;
use function unlink;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;


/** @var array<string,int|string>|false $User */
$User = function_exists('posix_getpwnam')
   ? posix_getpwnam('nobody')
   : false;

return new Test(
   description: 'The global wrapper never executes a user-controlled launcher as root',
   skip: function_exists('posix_geteuid') === false
      || posix_geteuid() !== 0
      || is_array($User) === false
      || is_executable('/usr/bin/setpriv') === false
      || is_writable('/root') === false,

   test: function () use ($User) {
      $suffix = getmypid() . '-' . bin2hex(random_bytes(6));
      $root = "/root/bootgly-h1-{$suffix}";
      $attacker = sys_get_temp_dir() . "/bootgly-h1-cwd-{$suffix}";
      $fallbackAttacker = sys_get_temp_dir() . "/bootgly-h1-fallback-{$suffix}";
      $ancestorAttacker = sys_get_temp_dir() . "/bootgly-h1-ancestor-{$suffix}";
      $sticky = sys_get_temp_dir() . "/bootgly-h1-sticky-{$suffix}";
      $binaryAttacker = sys_get_temp_dir() . "/bootgly-h1-binary-{$suffix}";
      $symlinkAttacker = sys_get_temp_dir() . "/bootgly-h1-symlink-{$suffix}";
      $ordinary = sys_get_temp_dir() . "/bootgly-h1-ordinary-{$suffix}";

      $paths = [
         "{$root}/control/deep",
         "{$root}/plain",
         "{$attacker}/deep",
         "{$fallbackAttacker}/plain",
         "{$ancestorAttacker}/deep",
         $sticky,
         $binaryAttacker,
         $symlinkAttacker,
         "{$ordinary}/deep",
      ];
      foreach ($paths as $path) {
         if (is_dir($path) === false) {
            mkdir($path, 0755, true);
         }
      }

      $files = [
         "{$root}/control/bootgly",
         "{$root}/fallback",
         "{$root}/wrapper-safe",
         "{$root}/wrapper-user-fallback",
         "{$root}/wrapper-ancestor-fallback",
         "{$root}/wrapper-sticky-fallback",
         "{$root}/wrapper-user-binary",
         "{$root}/wrapper-symlink-fallback",
         "{$root}/symlink-target",
         "{$attacker}/bootgly",
         "{$fallbackAttacker}/fallback",
         "{$ancestorAttacker}/bootgly",
         "{$sticky}/bootgly",
         "{$sticky}/autoboot.php",
         "{$binaryAttacker}/php",
         "{$symlinkAttacker}/fallback",
         "{$ordinary}/bootgly",
         "{$ordinary}/wrapper",
      ];
      $directories = [
         "{$root}/control/deep",
         "{$root}/control",
         "{$root}/plain",
         $root,
         "{$attacker}/deep",
         $attacker,
         "{$fallbackAttacker}/plain",
         $fallbackAttacker,
         "{$ancestorAttacker}/deep",
         $ancestorAttacker,
         $sticky,
         $binaryAttacker,
         $symlinkAttacker,
         "{$ordinary}/deep",
         $ordinary,
      ];

      $erase = static function () use ($files, $directories): void {
         foreach ($files as $file) {
            if (is_file($file) || is_link($file)) {
               @unlink($file);
            }
         }
         foreach ($directories as $directory) {
            if (is_dir($directory)) {
               @rmdir($directory);
            }
         }
      };

      try {
         if (is_array($User) === false) {
            return;
         }

         $UID = (int) $User['uid'];
         $GID = (int) $User['gid'];
         $username = (string) $User['name'];

         // ! Root-owned control launcher and fallback.
         file_put_contents(
            "{$root}/control/bootgly",
            "<?php\ndefine('BOOTGLY_WORKING_BASE', __DIR__);\necho 'CONTROL-H1:euid=' . posix_geteuid() . PHP_EOL;\n"
         );
         file_put_contents(
            "{$root}/fallback",
            "<?php\necho 'FALLBACK-H1:euid=' . posix_geteuid() . PHP_EOL;\n"
         );

         // ! User-owned attack launchers. Their marker satisfies the vulnerable
         //   wrapper's content sniff without changing the exact runtime path.
         file_put_contents(
            "{$attacker}/bootgly",
            "<?php\ndefine('BOOTGLY_WORKING_BASE', __DIR__);\necho 'ATTACK-CWD-H1:euid=' . posix_geteuid() . PHP_EOL;\n"
         );
         file_put_contents(
            "{$fallbackAttacker}/fallback",
            "<?php\necho 'ATTACK-FALLBACK-H1:euid=' . posix_geteuid() . PHP_EOL;\n"
         );
         file_put_contents(
            "{$ancestorAttacker}/bootgly",
            "<?php\ndefine('BOOTGLY_WORKING_BASE', __DIR__);\necho 'ATTACK-ANCESTOR-H1:euid=' . posix_geteuid() . PHP_EOL;\n"
         );
         file_put_contents(
            "{$sticky}/bootgly",
            "<?php\ndefine('BOOTGLY_WORKING_BASE', __DIR__);\nrequire __DIR__ . '/autoboot.php';\n"
         );
         file_put_contents(
            "{$sticky}/autoboot.php",
            "<?php\necho 'ATTACK-STICKY-H1:euid=' . posix_geteuid() . PHP_EOL;\n"
         );
         file_put_contents(
            "{$binaryAttacker}/php",
            "#!/bin/sh\nprintf 'ATTACK-BINARY-H1:euid=%s\\n' \"$(/usr/bin/id -u)\"\n"
         );
         file_put_contents(
            "{$root}/symlink-target",
            "<?php\necho 'ATTACK-SYMLINK-H1:euid=' . posix_geteuid() . PHP_EOL;\n"
         );
         symlink("{$root}/symlink-target", "{$symlinkAttacker}/fallback");
         file_put_contents(
            "{$ordinary}/bootgly",
            "<?php\ndefine('BOOTGLY_WORKING_BASE', __DIR__);\necho 'CONTROL-USER-H1:euid=' . posix_geteuid() . PHP_EOL;\n"
         );

         $ownership = true;
         foreach ([
            $attacker,
            "{$attacker}/deep",
            "{$attacker}/bootgly",
            $fallbackAttacker,
            "{$fallbackAttacker}/plain",
            "{$fallbackAttacker}/fallback",
            $ancestorAttacker,
            "{$ancestorAttacker}/deep",
            "{$sticky}/autoboot.php",
            $binaryAttacker,
            "{$binaryAttacker}/php",
            $symlinkAttacker,
            $ordinary,
            "{$ordinary}/deep",
            "{$ordinary}/bootgly",
         ] as $target) {
            $ownership = chown($target, $UID) && $ownership;
            $ownership = chgrp($target, $GID) && $ownership;
         }
         chmod("{$attacker}/bootgly", 0644);
         chmod("{$fallbackAttacker}/fallback", 0644);
         chmod("{$ancestorAttacker}/bootgly", 0644);
         chmod($sticky, 01777);
         chmod("{$sticky}/bootgly", 0644);
         chmod("{$sticky}/autoboot.php", 0644);
         chmod("{$binaryAttacker}/php", 0755);
         chmod("{$root}/symlink-target", 0644);
         chmod("{$ordinary}/bootgly", 0644);
         $ownership = lchown("{$symlinkAttacker}/fallback", $UID) && $ownership;
         $ownership = lchgrp("{$symlinkAttacker}/fallback", $GID) && $ownership;
         clearstatcache();

         $Symlink = lstat("{$symlinkAttacker}/fallback");

         yield assert(
            assertion: $ownership
               && fileowner("{$attacker}/bootgly") === $UID
               && fileowner("{$fallbackAttacker}/fallback") === $UID
               && fileowner("{$ancestorAttacker}/bootgly") === 0
               && fileowner("{$sticky}/bootgly") === 0
               && fileowner("{$sticky}/autoboot.php") === $UID
               && (fileperms($sticky) & 01777) === 01777
               && fileowner("{$binaryAttacker}/php") === $UID
               && is_link("{$symlinkAttacker}/fallback")
               && is_array($Symlink)
               && (int) $Symlink['uid'] === $UID
               && fileowner("{$ordinary}/bootgly") === $UID,
            description: 'H1 fixture: attack launchers and their ancestors must belong to the non-root sudo caller.'
         );
         if ($ownership === false
            || fileowner("{$attacker}/bootgly") !== $UID
            || fileowner("{$fallbackAttacker}/fallback") !== $UID
            || fileowner("{$ancestorAttacker}/bootgly") !== 0
            || fileowner("{$sticky}/bootgly") !== 0
            || fileowner("{$sticky}/autoboot.php") !== $UID
            || (fileperms($sticky) & 01777) !== 01777
            || fileowner("{$binaryAttacker}/php") !== $UID
            || is_link("{$symlinkAttacker}/fallback") === false
            || is_array($Symlink) === false
            || (int) $Symlink['uid'] !== $UID
            || fileowner("{$ordinary}/bootgly") !== $UID
         ) {
            return;
         }

         // ! Compose the actual generated wrapper, once with a safe fallback
         //   and once with a fallback owned by the sudo caller.
         $Compose = new ReflectionMethod(SetupCommand::class, 'compose');
         $Command = new SetupCommand;
         file_put_contents(
            "{$root}/wrapper-safe",
            $Compose->invoke($Command, PHP_BINARY, "{$root}/fallback")
         );
         file_put_contents(
            "{$root}/wrapper-user-fallback",
            $Compose->invoke($Command, PHP_BINARY, "{$fallbackAttacker}/fallback")
         );
         file_put_contents(
            "{$root}/wrapper-ancestor-fallback",
            $Compose->invoke($Command, PHP_BINARY, "{$ancestorAttacker}/bootgly")
         );
         file_put_contents(
            "{$root}/wrapper-sticky-fallback",
            $Compose->invoke($Command, PHP_BINARY, "{$sticky}/bootgly")
         );
         file_put_contents(
            "{$root}/wrapper-user-binary",
            $Compose->invoke($Command, "{$binaryAttacker}/php", "{$root}/fallback")
         );
         file_put_contents(
            "{$root}/wrapper-symlink-fallback",
            $Compose->invoke($Command, PHP_BINARY, "{$symlinkAttacker}/fallback")
         );
         file_put_contents(
            "{$ordinary}/wrapper",
            $Compose->invoke($Command, PHP_BINARY, "{$root}/fallback")
         );
         chmod("{$root}/wrapper-safe", 0755);
         chmod("{$root}/wrapper-user-fallback", 0755);
         chmod("{$root}/wrapper-ancestor-fallback", 0755);
         chmod("{$root}/wrapper-sticky-fallback", 0755);
         chmod("{$root}/wrapper-user-binary", 0755);
         chmod("{$root}/wrapper-symlink-fallback", 0755);
         chmod("{$ordinary}/wrapper", 0755);
         $ordinaryOwnership = chown("{$ordinary}/wrapper", $UID)
            && chgrp("{$ordinary}/wrapper", $GID);

         yield assert(
            assertion: $ordinaryOwnership
               && fileowner("{$ordinary}/wrapper") === $UID,
            description: 'H1 fixture: the ordinary-mode wrapper must belong to the non-root caller.'
         );
         if ($ordinaryOwnership === false
            || fileowner("{$ordinary}/wrapper") !== $UID
         ) {
            return;
         }

         $run = static function (string $wrapper, string $CWD) use ($username, $UID, $GID): array {
            $output = [];
            $status = -1;
            exec(
               'cd ' . escapeshellarg($CWD)
               . ' && SUDO_USER=' . escapeshellarg($username)
               . ' SUDO_UID=' . escapeshellarg((string) $UID)
               . ' SUDO_GID=' . escapeshellarg((string) $GID)
               . ' ' . escapeshellarg($wrapper)
               . ' 2>&1',
               $output,
               $status
            );

            return [$status, $output];
         };

         $runUser = static function (string $wrapper, string $CWD) use ($UID, $GID): array {
            $output = [];
            $status = -1;
            exec(
               'cd ' . escapeshellarg($CWD)
               . ' && /usr/bin/setpriv --reuid=' . escapeshellarg((string) $UID)
               . ' --regid=' . escapeshellarg((string) $GID)
               . ' --clear-groups ' . escapeshellarg($wrapper)
               . ' 2>&1',
               $output,
               $status
            );

            return [$status, $output];
         };

         // @ Positive control: ordinary mode still resolves a caller-owned
         //   workspace launcher and executes it under that caller's UID.
         [$controlStatus, $controlOutput] = $runUser(
            "{$ordinary}/wrapper",
            "{$ordinary}/deep"
         );

         // @ Root-mode policy control: even a fully root-owned CWD never
         //   replaces the fallback recorded by setup.
         [$rootCWDStatus, $rootCWDOutput] = $run(
            "{$root}/wrapper-safe",
            "{$root}/control/deep"
         );

         // @ Attack 1: the sudo caller owns the nearest launcher and ancestors.
         [$CWDStatus, $CWDOutput] = $run(
            "{$root}/wrapper-safe",
            "{$attacker}/deep"
         );

         // @ Attack 2: no workspace launcher exists and the recorded fallback
         //   itself belongs to the sudo caller.
         [$fallbackStatus, $fallbackOutput] = $run(
            "{$root}/wrapper-user-fallback",
            "{$fallbackAttacker}/plain"
         );

         // @ Attack 3: the file is root-owned, but the sudo caller owns an
         //   ancestor and can replace the path between classification and exec.
         [$ancestorStatus, $ancestorOutput] = $run(
            "{$root}/wrapper-ancestor-fallback",
            "{$root}/plain"
         );

         // @ Attack 4: a root-owned launcher directly inside a sticky writable
         //   directory can include a user-owned sibling bootstrap.
         [$stickyStatus, $stickyOutput] = $run(
            "{$root}/wrapper-sticky-fallback",
            "{$root}/plain"
         );

         // @ Attack 5: the wrapper's recorded interpreter is itself executable
         //   code and must face the same root trust boundary as the launcher.
         [$binaryStatus, $binaryOutput] = $run(
            "{$root}/wrapper-user-binary",
            "{$root}/control/deep"
         );

         // @ Attack 6: a lexical symlink owned by the sudo caller must not be
         //   canonicalized into a root-owned executable before classification.
         [$symlinkStatus, $symlinkOutput] = $run(
            "{$root}/wrapper-symlink-fallback",
            "{$root}/plain"
         );

         $control = implode('|', $controlOutput);
         $rootCWD = implode('|', $rootCWDOutput);
         $CWD = implode('|', $CWDOutput);
         $fallback = implode('|', $fallbackOutput);
         $ancestor = implode('|', $ancestorOutput);
         $stickyOutputText = implode('|', $stickyOutput);
         $binary = implode('|', $binaryOutput);
         $symlinkOutputText = implode('|', $symlinkOutput);
         $CWDCompromised = str_contains($CWD, 'ATTACK-CWD-H1:euid=0');
         $rootCWDCompromised = str_contains($rootCWD, 'CONTROL-H1:euid=0');
         $fallbackCompromised = str_contains($fallback, 'ATTACK-FALLBACK-H1:euid=0');
         $ancestorCompromised = str_contains($ancestor, 'ATTACK-ANCESTOR-H1:euid=0');
         $stickyCompromised = str_contains($stickyOutputText, 'ATTACK-STICKY-H1:euid=0');
         $binaryCompromised = str_contains($binary, 'ATTACK-BINARY-H1:euid=0');
         $symlinkCompromised = str_contains($symlinkOutputText, 'ATTACK-SYMLINK-H1:euid=0');

         yield assert(
            assertion: $controlStatus === 0
               && $control === "CONTROL-USER-H1:euid={$UID}",
            description: 'H1 control: ordinary mode must execute the workspace launcher as its non-root owner; '
               . "status={$controlStatus}; output={$control}"
         );

         $secure = $CWDStatus !== 0
            && $rootCWDStatus !== 0
            && $rootCWDCompromised === false
            && $CWDCompromised === false
            && $fallbackStatus !== 0
            && $fallbackCompromised === false
            && $ancestorStatus !== 0
            && $ancestorCompromised === false
            && $stickyStatus !== 0
            && $stickyCompromised === false
            && $binaryStatus !== 0
            && $binaryCompromised === false
            && $symlinkStatus !== 0
            && $symlinkCompromised === false;

         yield assert(
            assertion: $secure,
            description: $CWDCompromised
               || $rootCWDCompromised
               || $fallbackCompromised
               || $ancestorCompromised
               || $stickyCompromised
               || $binaryCompromised
               || $symlinkCompromised
               ? 'CONFIRMED H1: the global wrapper executed PHP with EUID 0; '
                  . "cwd_status={$CWDStatus}; cwd_output={$CWD}; "
                  . "root_cwd_status={$rootCWDStatus}; root_cwd_output={$rootCWD}; "
                  . "fallback_status={$fallbackStatus}; fallback_output={$fallback}; "
                  . "ancestor_status={$ancestorStatus}; ancestor_output={$ancestor}; "
                  . "sticky_status={$stickyStatus}; sticky_output={$stickyOutputText}; "
                  . "binary_status={$binaryStatus}; binary_output={$binary}; "
                  . "symlink_status={$symlinkStatus}; symlink_output={$symlinkOutputText}"
               : 'H1 secure behavior: sudo rejects every user-controlled entry/path; '
                  . "cwd_status={$CWDStatus}; cwd_output={$CWD}; "
                  . "root_cwd_status={$rootCWDStatus}; root_cwd_output={$rootCWD}; "
                  . "fallback_status={$fallbackStatus}; fallback_output={$fallback}; "
                  . "ancestor_status={$ancestorStatus}; ancestor_output={$ancestor}; "
                  . "sticky_status={$stickyStatus}; sticky_output={$stickyOutputText}; "
                  . "binary_status={$binaryStatus}; binary_output={$binary}; "
                  . "symlink_status={$symlinkStatus}; symlink_output={$symlinkOutputText}"
         );
      }
      finally {
         $erase();
      }
   }
);
