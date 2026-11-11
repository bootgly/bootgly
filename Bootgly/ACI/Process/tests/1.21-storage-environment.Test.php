<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Storage directory from the environment: validated at boot, and Process\State still refuses an unsafe one',
   skip: function_exists('proc_open') === false || function_exists('posix_geteuid') === false,
   test: function () {
      // ! Each case boots Bootgly in a child process: the storage constants are
      //   fixed once per process. No child reaches the real storage/ — its
      //   storage is a fixture (variable, pre-defined constant or working
      //   directory) or the boot is refused.
      $root = BOOTGLY_ROOT_BASE;
      //   The fixture is named by its canonical path: a linked temporary
      //   directory would otherwise be refused as not canonical.
      $base = (string) realpath(Temporaries::reserve('storage-environment'));
      chmod($base, 0755);
      $child = "{$base}/child.php";
      $prefix = 'Bootgly: BOOTGLY_STORAGE_DIR from the environment is refused: ';
      $EUID = posix_geteuid();

      file_put_contents($child, <<<'PHP'
      <?php
      // argv: [2] framework root · [3] action · [4] argument
      $action = $_SERVER['argv'][3];
      $argument = $_SERVER['argv'][4] ?? '';
      if ($action === 'predefine-dir') {
         define('BOOTGLY_STORAGE_DIR', $argument);
      }
      if ($action === 'predefine-base') {
         define('BOOTGLY_STORAGE_BASE', $argument);
      }
      if ($action === 'work') {
         define('BOOTGLY_WORKING_BASE', $argument);
         define('BOOTGLY_WORKING_DIR', "{$argument}/");
      }
      require $_SERVER['argv'][2] . '/autoboot.php';
      if ($action !== 'state') {
         echo BOOTGLY_STORAGE_BASE, '|', BOOTGLY_STORAGE_DIR, '|', BOOTGLY_UPLOADS_DIR;
         exit(0);
      }
      // @ The directory was accepted at boot: change it under the booted process
      $storage = BOOTGLY_STORAGE_BASE;
      match ($argument) {
         'none' => null,
         'link-root' => rename($storage, "{$storage}.real") && symlink("{$storage}.real", $storage),
         'mode-root' => chmod($storage, 0775),
         'link-pids' => mkdir("{$storage}/elsewhere", 0755) && symlink("{$storage}/elsewhere", "{$storage}/pids"),
         'mode-pids' => mkdir("{$storage}/pids", 0755) && chmod("{$storage}/pids", 0775),
         default => mkdir("{$storage}/pids", 0755) && chgrp("{$storage}/pids", (int) $argument)
      };
      try {
         new Bootgly\ACI\Process\State('StorageEnvironmentTest', '1');
         echo 'OK';
      }
      catch (RuntimeException $Exception) {
         echo $Exception->getMessage();
      }
      PHP);

      /**
       * @param null|string $storage The variable's value (null: unset).
       * @param array<int,string> $arguments
       * @param array<int,string> $wrapper A command to run the child under.
       * @param array<int,string> $flags More PHP flags for the child.
       *
       * @return array{0:int,1:string,2:string} Exit code, stdout, stderr.
       */
      $boot = function (
         null|string $storage, array $arguments, array $wrapper = [], array $flags = []
      ) use ($root, $child): array {
         $environment = getenv();
         unset($environment['BOOTGLY_STORAGE_DIR']);
         if ($storage !== null) {
            $environment['BOOTGLY_STORAGE_DIR'] = $storage;
         }
         // ? proc_open() drops a variable whose value is empty: `env` sets it
         if ($storage === '') {
            $wrapper = [...$wrapper, 'env', 'BOOTGLY_STORAGE_DIR='];
         }

         $Process = proc_open(
            [
               ...$wrapper,
               PHP_BINARY,
               '-d', 'zend.assertions=1', '-d', 'bootgly.assertions=1', '-d', 'opcache.jit=0',
               ...$flags,
               '-r', 'require $_SERVER["argv"][1];',
               $child, $root, ...$arguments
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
         );
         if (is_resource($Process) === false) {
            return [-1, '', ''];
         }
         $output = (string) stream_get_contents($pipes[1]);
         $errors = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);

         return [proc_close($Process), $output, trim($errors)];
      };
      // ! A directory nobody else can write, whatever the umask
      $make = function (string $path, int $mode = 0755): string {
         mkdir($path, 0755, true);
         chmod($path, $mode);

         return $path;
      };

      try {
         // # Accepted
         // @ A valid directory moves the three constants.
         $valid = $make("{$base}/valid");
         [$code, $output, $errors] = $boot($valid, ['constants']);
         yield assert(
            assertion: $code === 0 && $errors === ''
               && $output === "{$valid}|{$valid}/|{$valid}/temp/files/downloaded/",
            description: "a valid directory becomes the storage base, dir and uploads dir: [{$code}] {$output} {$errors}"
         );

         // @ One trailing separator is accepted.
         [$code, $output] = $boot("{$valid}/", ['constants']);
         yield assert(
            assertion: $code === 0 && $output === "{$valid}|{$valid}/|{$valid}/temp/files/downloaded/",
            description: "one trailing separator names the same directory: [{$code}] {$output}"
         );

         // @ A sticky world-writable ancestor does not refuse (only an entry's owner renames it).
         $sticky = $make("{$base}/sticky", 01777);
         $inside = $make("{$sticky}/inside");
         [$code, $output] = $boot($inside, ['constants']);
         yield assert(
            assertion: $code === 0 && $output === "{$inside}|{$inside}/|{$inside}/temp/files/downloaded/",
            description: "a directory under a sticky world-writable ancestor is accepted: [{$code}] {$output}"
         );

         // # Defaults and precedence
         // @ Unset: the default derives from the working directory, as before.
         $work = $make("{$base}/work");
         [$code, $output, $errors] = $boot(null, ['work', $work]);
         yield assert(
            assertion: $code === 0 && $errors === ''
               && $output === "{$work}/storage|{$work}/storage/|{$work}/storage/temp/files/downloaded/",
            description: "unset keeps <working>/storage: [{$code}] {$output} {$errors}"
         );

         // @ A pre-defined BOOTGLY_STORAGE_DIR wins — the variable is not even validated.
         [$code, $output, $errors] = $boot('relative/and/invalid', ['predefine-dir', "{$valid}/"]);
         yield assert(
            assertion: $code === 0 && $errors === ''
               && $output === "{$root}/storage|{$valid}/|{$valid}/temp/files/downloaded/",
            description: "a pre-defined BOOTGLY_STORAGE_DIR wins over the variable: [{$code}] {$output} {$errors}"
         );

         // @ A pre-defined BOOTGLY_STORAGE_BASE wins too.
         [$code, $output, $errors] = $boot('relative/and/invalid', ['predefine-base', $valid]);
         yield assert(
            assertion: $code === 0 && $errors === ''
               && $output === "{$valid}|{$valid}/|{$valid}/temp/files/downloaded/",
            description: "a pre-defined BOOTGLY_STORAGE_BASE wins over the variable: [{$code}] {$output} {$errors}"
         );

         // # Refused — one line on stderr naming the check and the path, exit 1, nothing on stdout
         $make("{$base}/target");
         symlink("{$base}/target", "{$base}/link");
         touch("{$base}/file");
         $make("{$base}/group", 0775);
         $make("{$base}/world", 0757);
         $open = $make("{$base}/open", 0777);
         $make("{$open}/inside/deeper");

         $refusals = [
            'an empty value' => ['', 'empty value'],
            'a relative path' => ['storage', 'not an absolute path: storage'],
            'the filesystem root' => ['/', 'the filesystem root: /'],
            'a `..` segment' => ["{$valid}/../valid", "not canonical (an empty, `.` or `..` segment): {$valid}/../valid"],
            'a `.` segment' => ["{$base}/./valid", "not canonical (an empty, `.` or `..` segment): {$base}/./valid"],
            'a doubled separator' => ["{$base}//valid", "not canonical (an empty, `.` or `..` segment): {$base}//valid"],
            'two trailing separators' => ["{$valid}//", "not canonical (an empty, `.` or `..` segment): {$valid}//"],
            'a missing directory' => ["{$base}/missing", "does not exist or cannot be inspected: {$base}/missing"],
            'a symbolic link' => ["{$base}/link", "symbolic link: {$base}/link"],
            'a file' => ["{$base}/file", "not a directory: {$base}/file"],
            'a group-writable directory' => ["{$base}/group", "group/world-writable (0775): {$base}/group"],
            'a world-writable directory' => ["{$base}/world", "group/world-writable (0757): {$base}/world"],
            'a world-writable parent' => ["{$open}/inside", "ancestor group/world-writable without the sticky bit (0777): {$open}"],
            'a world-writable ancestor further up' => ["{$open}/inside/deeper", "ancestor group/world-writable without the sticky bit (0777): {$open}"],
         ];
         foreach ($refusals as $case => [$value, $check]) {
            [$code, $output, $errors] = $boot($value, ['constants']);
            yield assert(
               assertion: $code === 1 && $output === '' && $errors === "{$prefix}{$check}",
               description: "{$case} is refused by name: [{$code}] {$output} {$errors}"
            );
         }

         // @ A directory behind a linked ancestor is not canonical.
         $make("{$base}/target/inside");
         [$code, $output, $errors] = $boot("{$base}/link/inside", ['constants']);
         yield assert(
            assertion: $code === 1 && $output === ''
               && $errors === "{$prefix}not canonical (resolves to {$base}/target/inside): {$base}/link/inside",
            description: "a directory reached through a linked ancestor is refused: [{$code}] {$errors}"
         );

         // @ The value is quoted as given, its control bytes escaped — never written raw.
         [$code, $output, $errors] = $boot("storage\nBootgly: fine\e[2K\x01\x7F", ['constants']);
         yield assert(
            assertion: $code === 1 && $output === ''
               && $errors === $prefix . 'not an absolute path: storage\nBootgly: fine\033[2K\001\177',
            description: "control bytes in the value are escaped in the refusal: [{$code}] {$errors}"
         );

         // @ Without the posix extension no owner can be verified: refused, never assumed.
         [$code, $output, $errors] = $boot($valid, ['constants'], flags: ['-d', 'disable_functions=posix_geteuid']);
         yield assert(
            assertion: $code === 1 && $output === ''
               && $errors === "{$prefix}its owner cannot be verified (the posix extension is missing): {$valid}",
            description: "a boot that cannot verify the owner is refused: [{$code}] {$errors}"
         );

         // @ A directory root owns alone is accepted for a launch that is not root's
         //   (for root's own launch that is the first leg of this case).
         //   (What another user owns is refused: the `storage-owners` case.)
         $system = @lstat('/usr');
         if (
            $EUID !== 0
            && is_array($system) && $system['uid'] === 0
            && ((int) $system['mode'] & 0170022) === 0040000
         ) {
            [$code, $output] = $boot('/usr', ['constants']);
            yield assert(
               assertion: $code === 0 && $output === '/usr|/usr/|/usr/temp/files/downloaded/',
               description: "a directory root owns is accepted for a non-root launch: [{$code}] {$output}"
            );
         }

         // # Process\State is not relaxed: what changes after the boot is still refused
         $states = [
            'none' => 'OK',
            'link-root' => 'symbolic link: %s',
            'mode-root' => 'group/world-writable (0775): %s',
            'link-pids' => 'symbolic link: %s/pids',
         ];
         // ? root repairs the mode and owner of `pids` instead of refusing them
         if ($EUID !== 0) {
            $states['mode-pids'] = 'group/world-writable (0775): %s/pids';

            $own = lstat($base);
            $groups = array_values(array_diff(posix_getgroups() ?: [], [(int) $own['gid']]));
            if ($groups !== []) {
               $states[(string) $groups[0]] = "owner {$own['uid']}:{$groups[0]} differs from the storage root's {$own['uid']}:{$own['gid']}: %s/pids";
            }
         }
         foreach ($states as $change => $check) {
            $storage = $make("{$base}/state-{$change}");
            [$code, $output, $errors] = $boot($storage, ['state', (string) $change]);
            $expected = $check === 'OK'
               ? 'OK'
               : "Unsafe process state directory at {$storage}/pids/: " . sprintf($check, $storage);
            yield assert(
               assertion: $code === 0 && $errors === '' && $output === $expected,
               description: "State over a directory from the environment ({$change}): [{$code}] {$output} {$errors}"
            );
         }
      }
      finally {
         // @ Remove the fixture tree — links are removed, never followed
         $remove = function (string $path) use (&$remove): void {
            if (is_link($path) || is_dir($path) === false) {
               @unlink($path);

               return;
            }
            @chmod($path, 0700);
            foreach (scandir($path) ?: [] as $entry) {
               if ($entry !== '.' && $entry !== '..') {
                  $remove("{$path}/{$entry}");
               }
            }
            @rmdir($path);
         };
         $remove($base);
      }
   }
);
