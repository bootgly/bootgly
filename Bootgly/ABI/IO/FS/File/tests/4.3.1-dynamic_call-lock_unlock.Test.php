<?php

use function Bootgly\ABI\remove_recursively;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'lock() and unlock() take and release advisory locks between processes',
   test: function () {
      // ! A child process that runs one probe and reports its result over a socket
      //   (the probe gets the socket too, to signal the parent before it reports)
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
               $result = (string) $Probe($Pair[1]);
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
      $join = static function (array $Child): string {
         [$PID, $Socket] = $Child;
         $result = (string) stream_get_contents($Socket);
         fclose($Socket);
         pcntl_waitpid($PID, $status);

         return $result;
      };
      $probe = static function (string $path, bool $exclusive) use ($spawn, $join): string {
         return $join($spawn(static function () use ($path, $exclusive): string {
            $File = new File($path);

            return $File->lock($exclusive, wait: false) ? 'locked' : 'busy';
         }));
      };

      $root = Temporaries::reserve('file-lock');
      try {
         $path = "{$root}/resource.lock";
         file_put_contents($path, 'DATA');

         // @ Exclusive — another process can take neither an exclusive nor a shared lock
         $Holder = new File($path);
         $held = $Holder->lock();
         yield assert(
            assertion: $held === true && $Holder->locked === true,
            description: 'Invalid File #1.1 lock(): the exclusive lock was not taken!'
         );
         yield assert(
            assertion: $probe($path, true) === 'busy',
            description: 'Invalid File #1.2 lock(): a second process took an exclusive lock already held!'
         );
         yield assert(
            assertion: $probe($path, false) === 'busy',
            description: 'Invalid File #1.3 lock(): a second process took a shared lock under an exclusive one!'
         );
         // @ The lock never truncates: the handle was opened in a non-truncating mode
         yield assert(
            assertion: file_get_contents($path) === 'DATA',
            description: 'Invalid File #1.4 lock(): opening the handle truncated the file!'
         );

         // @ unlock() — the lock is free again, the handle stays open
         $released = $Holder->unlock();
         yield assert(
            assertion: $released === true && $Holder->locked === false && is_resource($Holder->handler),
            description: 'Invalid File #2.1 unlock(): the lock was not released or the handle was closed!'
         );
         yield assert(
            assertion: $probe($path, true) === 'locked',
            description: 'Invalid File #2.2 unlock(): another process still cannot take the lock!'
         );

         // @ Shared — shared locks coexist, an exclusive one is denied
         $shared = $Holder->lock(exclusive: false);
         yield assert(
            assertion: $shared === true && $probe($path, false) === 'locked',
            description: 'Invalid File #3.1 lock(): two shared locks did not coexist!'
         );
         yield assert(
            assertion: $probe($path, true) === 'busy',
            description: 'Invalid File #3.2 lock(): an exclusive lock was taken under a shared one!'
         );
         $Holder->unlock();

         // @ Waiting — a blocking lock waits for the holder's release: the waiter signals
         //   right before it calls lock(), and the hold is timed from that signal
         $Holder->lock();
         $Waiter = $spawn(static function ($Socket) use ($path): string {
            $File = new File($path);
            fwrite($Socket, 'R');
            $started = microtime(true);
            $locked = $File->lock(exclusive: true, wait: true);

            return $locked ? (string) (microtime(true) - $started) : 'failed';
         });
         $signal = fread($Waiter[1], 1);
         usleep(300_000);
         $Holder->unlock();
         $waited = $join($Waiter);
         yield assert(
            assertion: $signal === 'R' && is_numeric($waited) && (float) $waited >= 0.25,
            description: "Invalid File #4.1 lock(): a blocking lock did not wait for the release ({$signal}, {$waited})!"
         );

         // @ Same process — a second handle conflicts like a second process does
         $Holder->lock();
         $Second = new File($path);
         $busy = $Second->lock(wait: false);
         yield assert(
            assertion: $busy === false && $Second->locked === false,
            description: 'Invalid File #5.1 lock(): a busy non-blocking lock did not return false!'
         );
         // @ A handle lock() opened for a refused lock is closed again
         yield assert(
            assertion: is_resource($Second->handler) === false,
            description: 'Invalid File #5.2 lock(): the handle opened for a refused lock was kept!'
         );

         // @ close() releases the lock
         $Holder->close();
         yield assert(
            assertion: $Holder->locked === false && $probe($path, true) === 'locked',
            description: 'Invalid File #6.1 close(): the lock outlived the handle!'
         );

         // @ unlock() without a handle has nothing to release
         $Idle = new File($path);
         yield assert(
            assertion: $Idle->unlock() === false,
            description: 'Invalid File #7.1 unlock(): released a lock without a handle!'
         );

         // @ A missing lock file is created (never truncated)
         $fresh = "{$root}/fresh.lock";
         $Fresh = new File($fresh);
         $created = $Fresh->lock();
         yield assert(
            assertion: $created === true && is_file($fresh) === true && $probe($fresh, true) === 'busy',
            description: 'Invalid File #8.1 lock(): a missing lock file was not created and locked!'
         );
         $Fresh->close();

         // @ A failed conversion leaves no lock — and `locked` says so: Linux drops the
         //   shared lock before it tries the exclusive one
         $Converted = new File($path);
         $Reader = new File($path);
         $Third = new File($path);
         $Converted->lock(exclusive: false);
         $Reader->lock(exclusive: false);
         $upgraded = $Converted->lock(exclusive: true, wait: false);
         $Reader->unlock();
         $taken = $Third->lock(exclusive: true, wait: false);
         yield assert(
            assertion: $upgraded === false && $Converted->locked === false
               && is_resource($Converted->handler) === true,
            description: 'Invalid File #8.2 lock(): a failed conversion still claims the lock it lost!'
         );
         yield assert(
            assertion: $taken === true,
            description: 'Invalid File #8.3 lock(): the lock a failed conversion dropped is still held (the spec premise broke)!'
         );
         $Third->close();
         $Reader->close();
         $Converted->close();

         // @ The exact entry named — a missing name is created as named, never as the
         //   `.php` or `/index.php` a read converts to
         file_put_contents("{$root}/job.php", 'CODE');
         $Job = new File("{$root}/job");
         $job = $Job->lock();
         $sibling = $probe("{$root}/job.php", true);
         $Job->close();
         mkdir("{$root}/page");
         file_put_contents("{$root}/page/index.php", 'INDEX');
         $Page = new File("{$root}/page/");
         $Page->lock();
         $index = $probe("{$root}/page/index.php", true);
         $Page->close();
         yield assert(
            assertion: $job === true && is_file("{$root}/job") === true && $sibling === 'locked'
               && file_get_contents("{$root}/job.php") === 'CODE',
            description: "Invalid File #8.4 lock(): a missing name was locked through its `.php` sibling ({$sibling})!"
         );
         yield assert(
            assertion: $index === 'locked' && file_get_contents("{$root}/page/index.php") === 'INDEX',
            description: "Invalid File #8.5 lock(): a directory path was locked through its `index.php` ({$index})!"
         );
         // # The File describes the entry it locked — a read-side lookup made before (the
         //   `.php` sibling, its contents and size) never outlives the lock
         @unlink("{$root}/job");
         $Locked = new File("{$root}/job");
         $before = $Locked->file;
         $cached = $Locked->contents;
         $sized = $Locked->size;
         $Locked->lock();
         $adopted = [
            'file'     => $Locked->file,
            'exists'   => $Locked->exists,
            'size'     => $Locked->size,
            'contents' => $Locked->contents,
         ];
         $Locked->write("LOCKED STATE\n");
         $adopted['read'] = $Locked->read('file_get_contents');
         $deleted = $Locked->delete();
         clearstatcache(true);
         yield assert(
            assertion: $before === "{$root}/job.php" && $cached === 'CODE' && $sized === 4
               && $adopted === [
                  'file'     => "{$root}/job",
                  'exists'   => true,
                  'size'     => 0,
                  'contents' => '',
                  'read'     => "LOCKED STATE\n",
               ]
               && $deleted === true && is_file("{$root}/job") === false
               && file_get_contents("{$root}/job.php") === 'CODE',
            description: 'Invalid File #8.8 lock(): read() or delete() after a lock acted on another entry than the locked one ('
               . json_encode(['before' => $before, 'adopted' => $adopted, 'job' => is_file("{$root}/job")]) . ')!'
         );
         // # A backslash name is a byte here — locked as named, never normalized out of its directory
         mkdir("{$root}/tree");
         $Climb = new File("{$root}/tree/..\\page\\index.php");
         $climb = $Climb->lock(wait: false);
         $Climb->close();
         $Busy = new File("{$root}/page/index.php");
         yield assert(
            assertion: $climb === true && is_file("{$root}/tree/..\\page\\index.php") === true
               && $Busy->lock(wait: false) === true,
            description: 'Invalid File #8.6 lock(): a backslash name was normalized out of its directory!'
         );
         $Busy->close();
         // # A stream wrapper and a NUL byte are refused
         yield assert(
            assertion: new File('php://memory')->lock() === false && new File("{$root}/nul\0.lock")->lock() === false,
            description: 'Invalid File #8.7 lock(): locked a stream wrapper or a NUL path!'
         );

         // @ The jail — no handle and no lock file outside it
         mkdir("{$root}/jail");
         $outside = "{$root}/outside.lock";
         $Jailed = new File($outside, base: "{$root}/jail");
         yield assert(
            assertion: $Jailed->lock() === false && is_file($outside) === false,
            description: 'Invalid File #9.1 lock(): locked a file outside the base jail!'
         );
      }
      finally {
         remove_recursively($root);
      }
   }
);
