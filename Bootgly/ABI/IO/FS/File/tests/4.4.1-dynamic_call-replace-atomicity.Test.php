<?php

use function Bootgly\ABI\remove_recursively;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'replace() swaps the whole file atomically, even when the write is interrupted',
   test: function () {
      // ! A child process that runs one probe and reports its result over a socket
      $spawn = static function (Closure $probe): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('Could not create the probe socket.');
         }
         $PID = pcntl_fork();
         if ($PID < 0) {
            throw new RuntimeException('Could not fork the probe.');
         }
         if ($PID === 0) {
            fclose($Pair[0]);
            try {
               $result = (string) $probe();
            }
            catch (Throwable) {
               $result = 'error';
            }
            fwrite($Pair[1], $result);
            fclose($Pair[1]);
            // ? No destructor, shutdown function or output buffer of the parent runs here
            posix_kill(posix_getpid(), SIGKILL);
         }
         fclose($Pair[1]);

         return [$PID, $Pair[0]];
      };
      // ! The entries of a directory other than the target, hidden ones included
      $siblings = static function (string $directory, string $target): array {
         clearstatcache(true);
         $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..', $target]));
         sort($entries);

         return $entries;
      };

      $root = Temporaries::reserve('file-replace-atomicity');
      try {
         $path = "{$root}/settings.json";
         $old = str_repeat('o', 65_536);
         file_put_contents($path, $old);

         // @ Replaced — whole new contents, no temporary left behind
         $File = new File($path);
         // ! The cached metadata of the old file, read before the replace
         $stale = $File->size;
         $replaced = $File->replace('{"new":true}');
         yield assert(
            assertion: $replaced === true && file_get_contents($path) === '{"new":true}',
            description: 'Invalid File #1.1 replace(): the target does not hold the new contents!'
         );
         yield assert(
            assertion: $siblings($root, 'settings.json') === [],
            description: 'Invalid File #1.2 replace(): a temporary was left beside the target!'
         );
         yield assert(
            assertion: $stale === 65_536 && $File->size === 12 && $File->exists === true,
            description: 'Invalid File #1.3 replace(): the cached metadata still describes the old file!'
         );
         file_put_contents($path, $old);

         // @ Never observed partial — a reader racing 40 replaces sees only whole files
         $A = str_repeat('a', 1_048_576);
         $B = str_repeat('b', 1_048_576);
         $Writer = $spawn(static function () use ($path, $A, $B): string {
            $File = new File($path);
            for ($round = 0; $round < 40; $round++) {
               if ($File->replace($round % 2 === 0 ? $A : $B) === false) {
                  return 'failed';
               }
            }

            return 'done';
         });
         [$WriterPID, $WriterSocket] = $Writer;
         stream_set_blocking($WriterSocket, false);
         $reads = 0;
         $partial = 0;
         $report = '';
         do {
            $contents = @file_get_contents($path);
            if ($contents !== $old && $contents !== $A && $contents !== $B) {
               $partial++;
            }
            $reads++;

            $report .= (string) fread($WriterSocket, 16);
         } while (feof($WriterSocket) === false);
         fclose($WriterSocket);
         pcntl_waitpid($WriterPID, $status);
         yield assert(
            assertion: $report === 'done' && $reads > 1 && $partial === 0,
            description: "Invalid File #2.1 replace(): a reader saw {$partial} truncated or partial file(s) in {$reads} reads ({$report})!"
         );
         file_put_contents($path, $old);
         $inode = fileinode($path);

         // @ Killed mid-write — the file-size limit kills the writer after its first 4 KiB
         $Killed = pcntl_fork();
         if ($Killed === 0) {
            posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0);
            posix_setrlimit(POSIX_RLIMIT_FSIZE, 4096, 4096);
            new File($path)->replace($A);
            // ? Not killed: the limit never applied
            posix_kill(posix_getpid(), SIGKILL);
         }
         pcntl_waitpid($Killed, $status);
         clearstatcache(true);
         $leftovers = $siblings($root, 'settings.json');
         yield assert(
            assertion: pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGXFSZ,
            description: 'Invalid File #3.1 replace(): the writer was not killed mid-write (the probe did not run)!'
         );
         yield assert(
            assertion: file_get_contents($path) === $old && fileinode($path) === $inode,
            description: 'Invalid File #3.2 replace(): a writer killed mid-write damaged the target!'
         );
         // @ A dead process cannot remove its temporary: one hidden, partial sibling — never the target
         $hidden = count($leftovers) === 1
            && preg_match('/\A\.[0-9a-f]{16}\.tmp\z/', $leftovers[0]) === 1
            && filesize("{$root}/{$leftovers[0]}") === 4096;
         yield assert(
            assertion: $hidden,
            description: 'Invalid File #3.3 replace(): the killed write left something other than one hidden partial temporary!'
         );
         foreach ($leftovers as $leftover) {
            @unlink("{$root}/{$leftover}");
         }

         // @ Failed mid-write — the write error is caught, the temporary removed, the target untouched
         $Failed = $spawn(static function () use ($path, $A): string {
            pcntl_signal(SIGXFSZ, SIG_IGN);
            posix_setrlimit(POSIX_RLIMIT_FSIZE, 4096, 4096);

            return new File($path)->replace($A) ? 'replaced' : 'refused';
         });
         [$FailedPID, $FailedSocket] = $Failed;
         $result = (string) stream_get_contents($FailedSocket);
         fclose($FailedSocket);
         pcntl_waitpid($FailedPID, $status);
         yield assert(
            assertion: $result === 'refused',
            description: "Invalid File #4.1 replace(): a short write was reported as a replace ({$result})!"
         );
         yield assert(
            assertion: file_get_contents($path) === $old && fileinode($path) === $inode,
            description: 'Invalid File #4.2 replace(): a failed write damaged the target!'
         );
         yield assert(
            assertion: $siblings($root, 'settings.json') === [],
            description: 'Invalid File #4.3 replace(): a failed write left its temporary behind!'
         );
      }
      finally {
         remove_recursively($root);
      }
   }
);
