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
   description: 'Process\State: an unsafe state directory names the check that refused it',
   skip: function_exists('shell_exec') === false,
   test: function () {
      // ! Each case boots Bootgly in a child process with its own storage root
      //   (BOOTGLY_STORAGE_DIR is a constant, fixed once per process).
      $root = BOOTGLY_ROOT_BASE;
      $base = Temporaries::reserve('state-protect');
      $child = "{$base}/child.php";

      $construct = function (string $storage) use ($root, $child): string {
         $command = escapeshellarg(PHP_BINARY)
            . ' -d opcache.jit=0 -r ' . escapeshellarg('require $_SERVER["argv"][1];')
            . ' ' . escapeshellarg($child)
            . ' ' . escapeshellarg($storage)
            . ' ' . escapeshellarg($root)
            . ' 2>&1';

         return trim((string) shell_exec($command));
      };

      try {
         @mkdir($base, 0755, true);
         chmod($base, 0755);
         file_put_contents($child, <<<'PHP'
         <?php
         define('BOOTGLY_STORAGE_DIR', rtrim($_SERVER['argv'][2], '/') . '/');
         require $_SERVER['argv'][3] . '/autoboot.php';
         try {
            new Bootgly\ACI\Process\State('StateProtectTest', '1');
            echo 'OK';
         }
         catch (RuntimeException $Exception) {
            echo $Exception->getMessage();
         }
         PHP);

         // @ Control: a clean storage root is accepted.
         mkdir("{$base}/clean", 0755);
         chmod("{$base}/clean", 0755);
         yield assert(
            assertion: $construct("{$base}/clean") === 'OK',
            description: 'a clean storage root constructs a State'
         );

         // @ Symlinked storage root.
         mkdir("{$base}/target", 0755);
         chmod("{$base}/target", 0755);
         symlink("{$base}/target", "{$base}/link");
         $message = $construct("{$base}/link");
         yield assert(
            assertion: $message === "Unsafe process state directory at {$base}/link/pids/: symbolic link: {$base}/link",
            description: 'a symlinked storage root is refused as a symbolic link'
         );

         // @ Group-writable storage root.
         mkdir("{$base}/mode", 0755);
         chmod("{$base}/mode", 0775);
         $message = $construct("{$base}/mode");
         yield assert(
            assertion: $message === "Unsafe process state directory at {$base}/mode/pids/: group/world-writable (0775): {$base}/mode",
            description: 'a group-writable storage root is refused with its mode'
         );

         // @ The mode keeps its special bits (a sticky, world-writable root).
         mkdir("{$base}/sticky", 0755);
         chmod("{$base}/sticky", 01777);
         $message = $construct("{$base}/sticky");
         yield assert(
            assertion: $message === "Unsafe process state directory at {$base}/sticky/pids/: group/world-writable (01777): {$base}/sticky",
            description: 'a sticky world-writable storage root is reported with its full mode'
         );

         // @ `pids` is not a directory.
         mkdir("{$base}/file", 0755);
         chmod("{$base}/file", 0755);
         touch("{$base}/file/pids");
         $message = $construct("{$base}/file");
         yield assert(
            assertion: $message === "Unsafe process state directory at {$base}/file/pids/: not a directory: {$base}/file/pids",
            description: 'a pids/ that is not a directory is refused as such'
         );

         // ? root repairs the mode and owner of `pids` instead of refusing them
         if (posix_geteuid() !== 0) {
            // @ Group-writable `pids`.
            mkdir("{$base}/pidsmode/pids", 0755, true);
            chmod("{$base}/pidsmode", 0755);
            chmod("{$base}/pidsmode/pids", 0775);
            $message = $construct("{$base}/pidsmode");
            yield assert(
               assertion: $message === "Unsafe process state directory at {$base}/pidsmode/pids/: group/world-writable (0775): {$base}/pidsmode/pids",
               description: 'a group-writable pids/ is refused with its mode'
            );

            // @ `pids` group differs from the storage root's (a supplementary group).
            mkdir("{$base}/owner/pids", 0755, true);
            chmod("{$base}/owner", 0755);
            $trusted = lstat("{$base}/owner");
            $groups = array_values(array_diff(posix_getgroups() ?: [], [(int) $trusted['gid']]));
            if ($groups !== [] && chgrp("{$base}/owner/pids", $groups[0])) {
               $message = $construct("{$base}/owner");
               $owner = "{$trusted['uid']}:{$groups[0]}";
               $root = "{$trusted['uid']}:{$trusted['gid']}";
               yield assert(
                  assertion: $message === "Unsafe process state directory at {$base}/owner/pids/: owner {$owner} differs from the storage root's {$root}: {$base}/owner/pids",
                  description: 'a pids/ whose group differs from the storage root is refused with both owners'
               );
            }
         }
      }
      finally {
         @chmod("{$base}/mode", 0755);
         @chmod("{$base}/sticky", 0755);
         @chmod("{$base}/pidsmode/pids", 0755);
         foreach (["{$base}/pidsmode", "{$base}/owner", "{$base}/sticky"] as $storage) {
            @rmdir("{$storage}/pids");
            @rmdir($storage);
         }
         foreach (["{$base}/clean", "{$base}/mode", "{$base}/target"] as $storage) {
            @rmdir("{$storage}/pids");
            @rmdir($storage);
         }
         @unlink("{$base}/file/pids");
         @rmdir("{$base}/file");
         @unlink("{$base}/link");
         @unlink($child);
         @rmdir($base);
      }
   }
);
