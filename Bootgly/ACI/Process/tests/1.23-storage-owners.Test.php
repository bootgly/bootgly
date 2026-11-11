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


// ! A foreign owner needs a second user. Root has one: it gives a directory
//   away. So does the root of a user namespace that maps a range of uids. A
//   namespace of one uid only sees what root owns outside it as the overflow
//   uid — enough for the directory itself, not for an ancestor of a fixture.
//   What decides is the operation itself, tried on a directory of its own: a
//   namespace that starts is not one that may `chown` (AppArmor's
//   `unprivileged_userns` gives it no capability, a container may drop
//   CAP_CHOWN from root). Where nothing can run the case is skipped whole and
//   says why on stderr, so the missing proof shows in the report.
// ! A probe has nothing to wait on but its own process, and a deadline: where
//   unshare(2) is denied, `unshare --map-auto` leaves a helper behind that
//   keeps an inherited pipe open for good.
$probe = function (array $command): bool {
   if (function_exists('proc_open') === false) {
      return false;
   }
   $Process = @proc_open(
      $command,
      [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
      $pipes
   );
   if (is_resource($Process) === false) {
      return false;
   }
   $deadline = microtime(true) + 10;
   do {
      $status = proc_get_status($Process);
      if ($status['running'] === false) {
         proc_close($Process);

         return $status['exitcode'] === 0;
      }
      usleep(10000);
   }
   while (microtime(true) < $deadline);
   proc_terminate($Process, 9);
   proc_close($Process);

   return false;
};
$namespace = null;
$range = false;
$reason = 'proc_open() or posix_geteuid() is not available';
if (function_exists('proc_open') && function_exists('posix_geteuid')) {
   $given = Temporaries::reserve('storage-owners-probe');
   $giving = ['sh', '-c', 'chown 1 "$0" && chown 0 "$0"', $given];

   if (posix_geteuid() === 0) {
      if ($probe($giving)) {
         $namespace = [];
         $range = true;
      }
      else {
         $reason = 'root cannot give a directory to another user here (no CAP_CHOWN)';
      }
   }
   else if ($probe(['unshare', '--user', 'true']) === false) {
      $reason = 'not root, and a user namespace cannot be created here';
   }
   else if ($probe(['unshare', '--user', '--map-root-user', '--map-auto', ...$giving])) {
      $namespace = ['unshare', '--user', '--map-root-user', '--map-auto'];
      $range = true;
   }
   else if ($probe(['unshare', '--user', '--map-root-user', 'sh', '-c', '[ "$(id -u)" = 0 ]'])) {
      $namespace = ['unshare', '--user', '--map-root-user'];
      $reason = 'the user namespace maps no second uid, or may not chown';
   }
   else {
      $reason = 'not root, and the user namespace gives neither uid 0 nor the right to chown';
   }
   @rmdir($given);
}
if ($namespace === null) {
   fwrite(STDERR, "1.23-storage-owners: skipped — {$reason}.\n");
}
else if ($range === false) {
   fwrite(STDERR, "1.23-storage-owners: the legs that give a directory to a second user do not run — {$reason}.\n");
}

return new Test(
   description: 'Storage directory from the environment: a directory or an ancestor another user owns is refused',
   skip: $namespace === null,
   test: function () use ($namespace, $range) {
      // ! Each case boots Bootgly in a child process, as root (itself, or of a
      //   user namespace). No child reaches the real storage/: its storage is a
      //   fixture, or the boot is refused.
      $root = BOOTGLY_ROOT_BASE;
      $base = (string) realpath(Temporaries::reserve('storage-owners'));
      chmod($base, 0755);
      $child = "{$base}/child.php";
      $prefix = 'Bootgly: BOOTGLY_STORAGE_DIR from the environment is refused: ';
      $superuser = posix_geteuid() === 0;
      $overflow = (int) trim((string) @file_get_contents('/proc/sys/kernel/overflowuid')) ?: 65534;

      file_put_contents($child, <<<'PHP'
      <?php
      require $_SERVER['argv'][2] . '/autoboot.php';
      echo BOOTGLY_STORAGE_BASE, '|', BOOTGLY_STORAGE_DIR, '|', BOOTGLY_UPLOADS_DIR;
      PHP);

      /**
       * @param string $storage The variable's value.
       * @param null|string $given A directory given to uid 1 while the child boots.
       *
       * @return array{0:int,1:string,2:string} Exit code, stdout, stderr.
       */
      $boot = function (string $storage, null|string $given = null) use ($root, $child, $namespace): array {
         $environment = getenv();
         $environment['BOOTGLY_STORAGE_DIR'] = $storage;
         // ! The directory goes back to root whatever the boot answers
         $giving = $given === null
            ? []
            : ['sh', '-c', 'chown 1 "$0" || exit 99; "$@"; status=$?; chown 0 "$0"; exit $status', $given];

         $Process = proc_open(
            [
               ...$namespace,
               ...$giving,
               PHP_BINARY,
               '-d', 'zend.assertions=1', '-d', 'bootgly.assertions=1', '-d', 'opcache.jit=0',
               '-r', 'require $_SERVER["argv"][1];',
               $child, $root
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
      $make = function (string $path): string {
         mkdir($path, 0755, true);
         chmod($path, 0755);

         return $path;
      };

      $foreign = $make("{$base}/foreign");
      $inside = $make("{$foreign}/inside");
      $valid = $make("{$base}/valid");
      // ! The first ancestor of the fixture that is not this user's: in a user
      //   namespace it belongs to the overflow uid — as `/` does, and as every
      //   account the namespace does not map: root is uid 0 alone
      $above = $base;
      do {
         $above = dirname($above);
         $state = lstat($above);
      }
      while ($above !== '/' && is_array($state) && $state['uid'] === posix_geteuid());
      $unmapped = "{$prefix}ancestor owner {$overflow} is neither the effective user (0) nor root: {$above}";

      try {
         // # A second user: the directory, then an ancestor, given to uid 1
         if ($range) {
            [$code, $output, $errors] = $boot($foreign, given: $foreign);
            yield assert(
               assertion: $code === 1 && $output === ''
                  && $errors === "{$prefix}owner 1 is neither the effective user (0) nor root: {$foreign}",
               description: "a directory another user owns is refused: [{$code}] {$output} {$errors}"
            );

            [$code, $output, $errors] = $boot($inside, given: $foreign);
            yield assert(
               assertion: $code === 1 && $output === ''
                  && $errors === "{$prefix}ancestor owner 1 is neither the effective user (0) nor root: {$foreign}",
               description: "a directory under an ancestor another user owns is refused: [{$code}] {$output} {$errors}"
            );

            // @ Control: back with root, that ancestor is not what refuses any more —
            //   the directory is accepted, or (in a namespace) refused further up.
            [$code, $output, $errors] = $boot($inside);
            yield assert(
               assertion: $superuser
                  ? $code === 0 && $errors === '' && $output === "{$inside}|{$inside}/|{$inside}/temp/files/downloaded/"
                  : $code === 1 && $output === '' && $errors === $unmapped,
               description: "the same directory is not refused for that ancestor once it is root's again: [{$code}] {$output} {$errors}"
            );
         }

         // # A user namespace: what root owns outside it belongs to the overflow uid inside
         if ($superuser === false) {
            $system = @lstat('/usr');
            if (
               is_array($system) && $system['uid'] === 0
               && ((int) $system['mode'] & 0170022) === 0040000
            ) {
               [$code, $output, $errors] = $boot('/usr');
               yield assert(
                  assertion: $code === 1 && $output === ''
                     && $errors === "{$prefix}owner {$overflow} is neither the effective user (0) nor root: /usr",
                  description: "a directory an unmapped user owns is refused (user namespace): [{$code}] {$output} {$errors}"
               );
            }

            // @ …and so does the first ancestor of the fixture that is not this user's.
            [$code, $output, $errors] = $boot($valid);
            yield assert(
               assertion: $code === 1 && $output === '' && $errors === $unmapped,
               description: "a directory under an ancestor an unmapped user owns is refused (user namespace): [{$code}] {$output} {$errors}"
            );
         }
      }
      finally {
         // @ Remove the fixture tree — links are removed, never followed. A boot
         //   that was killed may have left the directory given away: taken back
         if ($range) {
            $Process = proc_open([...$namespace, 'chown', '0', $foreign], [], $pipes);
            if (is_resource($Process)) {
               proc_close($Process);
            }
         }
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
