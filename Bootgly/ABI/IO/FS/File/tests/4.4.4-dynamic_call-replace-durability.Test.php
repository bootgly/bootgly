<?php

use function Bootgly\ABI\remove_recursively;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'replace() without durability skips only the syncs: the swap stays atomic',
   test: function () {
      // ! A child process that runs one probe and reports its result over a socket
      $spawn = static function (Closure $Probe): array {
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
               $result = (string) $Probe();
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

      // @ Durable unless told otherwise
      $Durable = new ReflectionParameter([File::class, 'replace'], 'durable');
      yield assert(
         assertion: $Durable->isDefaultValueAvailable() && $Durable->getDefaultValue() === true,
         description: 'Invalid File #1.1 replace(): the default is not durable!'
      );

      $root = Temporaries::reserve('file-replace-durability');
      $umask = umask();
      try {
         $path = "{$root}/records.cache";
         $old = str_repeat('o', 65_536);
         file_put_contents($path, $old);

         // @ Replaced — whole new contents, the given mode, no temporary left behind
         umask(0);
         $File = new File($path);
         $stale = $File->size;
         $replaced = $File->replace('{"new":true}', 0640, durable: false);
         umask($umask);
         clearstatcache(true);
         yield assert(
            assertion: $replaced === true && file_get_contents($path) === '{"new":true}',
            description: 'Invalid File #2.1 replace(): the target does not hold the new contents!'
         );
         yield assert(
            assertion: ((int) fileperms($path) & 0777) === 0640,
            description: 'Invalid File #2.2 replace(): 0640 was not applied under umask 0!'
         );
         yield assert(
            assertion: $siblings($root, 'records.cache') === [],
            description: 'Invalid File #2.3 replace(): a temporary was left beside the target!'
         );
         yield assert(
            assertion: $stale === 65_536 && $File->size === 12 && $File->exists === true,
            description: 'Invalid File #2.4 replace(): the cached metadata still describes the old file!'
         );
         // @ A directory that does not exist is still refused
         yield assert(
            assertion: new File("{$root}/missing/records.cache")->replace('x', 0600, durable: false) === false,
            description: 'Invalid File #2.5 replace(): a missing directory was not refused!'
         );
         file_put_contents($path, $old);

         // @ Never observed partial — a reader racing 40 replaces sees only whole files
         $A = str_repeat('a', 1_048_576);
         $B = str_repeat('b', 1_048_576);
         $Writer = $spawn(static function () use ($path, $A, $B): string {
            $File = new File($path);
            for ($round = 0; $round < 40; $round++) {
               if ($File->replace($round % 2 === 0 ? $A : $B, 0600, durable: false) === false) {
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
            description: "Invalid File #3.1 replace(): a reader saw {$partial} truncated or partial file(s) in {$reads} reads ({$report})!"
         );
         file_put_contents($path, $old);
         $inode = fileinode($path);

         // @ Killed mid-write — the file-size limit kills the writer after its first 4 KiB
         $Killed = pcntl_fork();
         if ($Killed === 0) {
            posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0);
            posix_setrlimit(POSIX_RLIMIT_FSIZE, 4096, 4096);
            new File($path)->replace($A, 0600, durable: false);
            // ? Not killed: the limit never applied
            posix_kill(posix_getpid(), SIGKILL);
         }
         pcntl_waitpid($Killed, $status);
         clearstatcache(true);
         $leftovers = $siblings($root, 'records.cache');
         yield assert(
            assertion: pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGXFSZ,
            description: 'Invalid File #4.1 replace(): the writer was not killed mid-write (the probe did not run)!'
         );
         yield assert(
            assertion: file_get_contents($path) === $old && fileinode($path) === $inode,
            description: 'Invalid File #4.2 replace(): a writer killed mid-write damaged the target!'
         );
         foreach ($leftovers as $leftover) {
            @unlink("{$root}/{$leftover}");
         }

         // @ Failed mid-write — the short write is still refused, the temporary removed
         $Failed = $spawn(static function () use ($path, $A): string {
            pcntl_signal(SIGXFSZ, SIG_IGN);
            posix_setrlimit(POSIX_RLIMIT_FSIZE, 4096, 4096);

            return new File($path)->replace($A, 0600, durable: false) ? 'replaced' : 'refused';
         });
         [$FailedPID, $FailedSocket] = $Failed;
         $result = (string) stream_get_contents($FailedSocket);
         fclose($FailedSocket);
         pcntl_waitpid($FailedPID, $status);
         yield assert(
            assertion: $result === 'refused',
            description: "Invalid File #5.1 replace(): a short write was reported as a replace ({$result})!"
         );
         yield assert(
            assertion: file_get_contents($path) === $old && fileinode($path) === $inode,
            description: 'Invalid File #5.2 replace(): a failed write damaged the target!'
         );
         yield assert(
            assertion: $siblings($root, 'records.cache') === [],
            description: 'Invalid File #5.3 replace(): a failed write left its temporary behind!'
         );
      }
      finally {
         umask($umask);
         remove_recursively($root);
      }
   }
);
