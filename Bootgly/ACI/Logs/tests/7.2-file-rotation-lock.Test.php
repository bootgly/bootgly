<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Logs\Handlers\File\Rotation;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * Every process writing one File sink rotates it once, under the append lock.
 *
 * A rotation decided before the lock let every worker that saw the day change
 * at midnight shift the archive chain again — destroying archived days, losing
 * records, and under the framework's error handler throwing out of
 * `Logger->log()` (LOGS-1). The rotation is now decided and made under the
 * `LOCK_EX` every writer takes on the active inode: a writer that waited
 * re-checks that the name still holds the inode it locked, a wait cut short
 * by a signal is waited again (LOGS-14), and a creator keeps every writer out
 * until its temporary second name is gone (LOGS-15).
 *
 * The children are real processes (fork) that keep the ABI error handler — a
 * warning in the handler would surface as an escape.
 */
return new Test(
   description: 'File sinks shared by processes rotate once, under the append lock, and lose no record to the race',
   test: function () {
      $dir = Temporaries::reserve('logs-rotation-lock');
      $path = "$dir/app.log";
      $yesterday = strtotime('today') - 3600;
      /** @var array<int,int> $PIDs */
      $PIDs = [];

      // ! Empty a directory (and remove it when asked)
      $purge = static function (string $folder, bool $self = false) use (&$purge): void {
         foreach ((array) @scandir($folder) as $name) {
            if ($name === '.' || $name === '..' || $name === false) {
               continue;
            }
            $entry = "$folder/$name";
            if (is_dir($entry) === true && is_link($entry) === false) {
               $purge($entry, true);
            }
            else {
               @unlink($entry);
            }
         }
         if ($self === true) {
            @rmdir($folder);
         }
      };
      // ! Whether a process waits on the lock of an inode — bounded; no /proc/locks fails loudly
      $waiting = static function (int $PID, int $inode, float $limit = 3.0): bool {
         $until = microtime(true) + $limit;
         do {
            $locks = @file_get_contents('/proc/locks');
            if ($locks === false) {
               throw new RuntimeException('/proc/locks is unreadable: the lock legs cannot observe a waiter here');
            }
            if (preg_match("/->\\s+FLOCK\\s+\\S+\\s+\\S+\\s+{$PID}\\s+[0-9a-f]+:[0-9a-f]+:{$inode}\\s/", $locks) === 1) {
               return true;
            }
            usleep(10_000);
         } while (microtime(true) < $until);

         return false;
      };
      // ! Run work in a child process; its outcome comes back through a file
      $spawn = static function (string $tag, Closure $work) use ($dir, &$PIDs): int {
         $PID = pcntl_fork();
         if ($PID === 0) {
            $result = ['escape' => null, 'written' => null];
            try {
               $result['written'] = $work();
            }
            catch (Throwable $Throwable) {
               $result['escape'] = $Throwable::class . ': ' . $Throwable->getMessage();
            }
            @file_put_contents("$dir/.child-$tag.json", json_encode($result));
            // ! Hard exit — no shutdown handlers, no inherited output flush
            posix_kill(posix_getpid(), SIGKILL);
         }
         $PIDs[] = $PID;

         return $PID;
      };
      $collect = static function (int $PID, string $tag) use ($dir): array {
         $until = microtime(true) + 15;
         while (pcntl_waitpid($PID, $status, WNOHANG) === 0) {
            if (microtime(true) > $until) {
               posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status);
               break;
            }
            usleep(10_000);
         }
         $JSON = @file_get_contents("$dir/.child-$tag.json");
         @unlink("$dir/.child-$tag.json");
         $result = is_string($JSON) ? json_decode($JSON, true) : null;

         return is_array($result) ? $result : ['escape' => 'the child reported nothing', 'written' => null];
      };
      $write = static fn (string $message): bool
         => (new File($path))->handle(new Record(Levels::Info, 'lock', $message));
      $read = static fn (string $file): string => trim((string) @file_get_contents($file));

      try {
         // @@ A) A due rotation waits for the lock another writer holds: the name
         //    still holds the ended day while it waits, and the day rotates once
         file_put_contents($path, "A-yesterday\n");
         touch($path, $yesterday);
         $inode = (int) fileinode($path);
         $Held = fopen($path, 'r+');
         flock($Held, LOCK_EX);
         $PID = $spawn('A', static fn () => $write('A-child'));
         $waited = $waiting($PID, $inode);
         clearstatcache();
         $unmoved = fileinode($path) === $inode && file_exists("$path.1") === false;
         flock($Held, LOCK_UN);
         fclose($Held);
         $A = $collect($PID, 'A');
         yield assert(
            assertion: $waited && $unmoved
               && $A['escape'] === null && $A['written'] === true
               && $read("$path.1") === 'A-yesterday'
               && str_contains($read($path), 'A-child')
               && str_contains($read($path), 'A-yesterday') === false,
            description: 'a due rotation waits for the lock another writer holds, then rotates the ended day once '
               . '(waited: ' . var_export($waited, true) . ', unmoved: ' . var_export($unmoved, true)
               . ', escape: ' . var_export($A['escape'], true) . ')'
         );
         $purge($dir);

         // @@ B) A writer blocked on a file that is rotated away writes into the new one
         file_put_contents($path, "B-old\n");
         $inode = (int) fileinode($path);
         $Held = fopen($path, 'r+');
         flock($Held, LOCK_EX);
         $PID = $spawn('B', static fn () => $write('B-child'));
         $waited = $waiting($PID, $inode);
         rename($path, "$path.1");
         flock($Held, LOCK_UN);
         fclose($Held);
         $B = $collect($PID, 'B');
         yield assert(
            assertion: $waited
               && $B['escape'] === null && $B['written'] === true
               && file_get_contents("$path.1") === "B-old\n"
               && str_contains($read($path), 'B-child'),
            description: 'a writer that waited on a file rotated away meanwhile writes into the new file — the archive stays byte-identical'
         );
         $purge($dir);

         // @@ C) A writer that waited while the holder rotated does not rotate again
         for ($index = 1; $index <= 6; $index++) {
            file_put_contents("$path.$index", "a$index\n");
         }
         file_put_contents($path, "C-yesterday\n");
         touch($path, $yesterday);
         $inode = (int) fileinode($path);
         $Held = fopen($path, 'r+');
         flock($Held, LOCK_EX);
         $PID = $spawn('C', static fn () => $write('C-child'));
         $waited = $waiting($PID, $inode);
         // ! The holder rotates, as the handler does under its lock
         (new Rotation)->rotate($path);
         flock($Held, LOCK_UN);
         fclose($Held);
         $C = $collect($PID, 'C');
         $chain = [];
         for ($index = 1; $index <= 7; $index++) {
            $chain[$index] = $read("$path.$index");
         }
         yield assert(
            assertion: $waited
               && $C['escape'] === null && $C['written'] === true
               && $chain === [1 => 'C-yesterday', 2 => 'a1', 3 => 'a2', 4 => 'a3', 5 => 'a4', 6 => 'a5', 7 => 'a6']
               && file_exists("$path.8") === false
               && str_contains($read($path), 'C-child'),
            description: 'a writer that waited while the holder rotated finds the day rotated and shifts no archive again'
         );
         $purge($dir);

         // @@ D) A lock wait cut short by a signal is waited again — the record is kept
         file_put_contents($path, "D-old\n");
         $inode = (int) fileinode($path);
         $Held = fopen($path, 'r+');
         flock($Held, LOCK_EX);
         $PID = $spawn('D', static function () use ($write): bool {
            // ! As the Timer does: SIGALRM without SA_RESTART
            pcntl_signal(SIGALRM, static function (): void {}, false);
            pcntl_alarm(1);

            return $write('D-child');
         });
         $waited = $waiting($PID, $inode);
         usleep(1_600_000);
         $again = $waiting($PID, $inode, 1.0);
         flock($Held, LOCK_UN);
         fclose($Held);
         $D = $collect($PID, 'D');
         yield assert(
            assertion: $waited && $again
               && $D['escape'] === null && $D['written'] === true
               && str_contains($read($path), 'D-child'),
            description: 'a lock wait interrupted by a signal is waited again and the record is written '
               . '(waiting after the signal: ' . var_export($again, true) . ', written: ' . var_export($D['written'], true) . ')'
         );
         $purge($dir);

         // @@ E) A writer that opens while a creator still holds its temporary
         //    second name waits for the creator instead of refusing the file
         $temporary = "$dir/.app.log.creating";
         $Made = fopen($temporary, 'x');
         flock($Made, LOCK_EX);
         link($temporary, $path);
         $inode = (int) fileinode($path);
         $PID = $spawn('E', static fn () => $write('E-child'));
         // ! A privileged writer polls an inode with a second name — no blocked
         //   waiter to see: wait until it is seen sleeping between two polls
         if (posix_geteuid() === 0) {
            $waited = false;
            $until = microtime(true) + 3.0;
            while ($waited === false && microtime(true) < $until) {
               $waited = str_contains((string) @file_get_contents("/proc/$PID/wchan"), 'nanosleep');
               usleep(1_000);
            }
         }
         else {
            $waited = $waiting($PID, $inode);
         }
         unlink($temporary);
         fwrite($Made, "E-creator\n");
         flock($Made, LOCK_UN);
         fclose($Made);
         $E = $collect($PID, 'E');
         yield assert(
            assertion: $waited
               && $E['escape'] === null && $E['written'] === true
               && str_contains($read($path), 'E-creator')
               && str_contains($read($path), 'E-child'),
            description: 'a writer that opens a file still being created waits for its creator and writes — no refusal '
               . '(written: ' . var_export($E['written'], true) . ')'
         );
         $purge($dir);

         // @@ F) Eight writers across the day change: every record kept, the day rotated once
         for ($index = 1; $index <= 6; $index++) {
            file_put_contents("$path.$index", "a$index\n");
         }
         $lines = '';
         for ($line = 1; $line <= 20; $line++) {
            $lines .= "F-yesterday-$line\n";
         }
         file_put_contents($path, $lines);
         touch($path, $yesterday);
         $start = microtime(true) + 0.3;
         $writers = [];
         for ($writer = 1; $writer <= 8; $writer++) {
            $writers[$writer] = $spawn("F$writer", static function () use ($path, $start, $writer): int {
               $wait = $start - microtime(true);
               if ($wait > 0) {
                  usleep((int) ($wait * 1_000_000));
               }
               $File = new File($path);
               $written = 0;
               for ($record = 1; $record <= 20; $record++) {
                  if ($File->handle(new Record(Levels::Info, 'lock', "F-$writer-$record")) === true) {
                     $written++;
                  }
               }

               return $written;
            });
         }
         $escapes = [];
         $written = 0;
         foreach ($writers as $writer => $PID) {
            $F = $collect($PID, "F$writer");
            if ($F['escape'] !== null) {
               $escapes[] = $F['escape'];
            }
            $written += (int) $F['written'];
         }
         $active = (string) file_get_contents($path);
         $found = 0;
         for ($writer = 1; $writer <= 8; $writer++) {
            for ($record = 1; $record <= 20; $record++) {
               $found += substr_count($active, "\"F-$writer-$record\"") === 1 ? 1 : 0;
            }
         }
         $chain = [];
         for ($index = 2; $index <= 7; $index++) {
            $chain[$index] = $read("$path.$index");
         }
         yield assert(
            assertion: $escapes === [] && $written === 160 && $found === 160
               && file_get_contents("$path.1") === $lines
               && $chain === [2 => 'a1', 3 => 'a2', 4 => 'a3', 5 => 'a4', 6 => 'a5', 7 => 'a6']
               && file_exists("$path.8") === false,
            description: 'eight writers across the day change: no throwable, 160/160 records in the new day, the ended day at .1 and every archive shifted once '
               . '(escapes: ' . count($escapes) . ", written: $written, found: $found)"
         );
         $purge($dir);

         // @@ G) A cap of about one record and eight writers: rotations move the
         //    name under a waiting writer again and again — no record is lost to it
         $start = microtime(true) + 0.3;
         $writers = [];
         for ($writer = 1; $writer <= 8; $writer++) {
            $writers[$writer] = $spawn("G$writer", static function () use ($path, $start, $writer): int {
               $wait = $start - microtime(true);
               if ($wait > 0) {
                  usleep((int) ($wait * 1_000_000));
               }
               $File = new File($path, Rotation: new Rotation(size: 150, daily: false, keep: 1000));
               $written = 0;
               for ($record = 1; $record <= 20; $record++) {
                  if ($File->handle(new Record(Levels::Info, 'lock', "G-$writer-$record")) === true) {
                     $written++;
                  }
               }

               return $written;
            });
         }
         $escapes = [];
         $written = 0;
         foreach ($writers as $writer => $PID) {
            $G = $collect($PID, "G$writer");
            if ($G['escape'] !== null) {
               $escapes[] = $G['escape'];
            }
            $written += (int) $G['written'];
         }
         $all = '';
         foreach ((array) glob("$path*") as $file) {
            $all .= (string) file_get_contents((string) $file);
         }
         $found = 0;
         for ($writer = 1; $writer <= 8; $writer++) {
            for ($record = 1; $record <= 20; $record++) {
               $found += substr_count($all, "\"G-$writer-$record\"") === 1 ? 1 : 0;
            }
         }
         yield assert(
            assertion: $escapes === [] && $written === 160 && $found === 160,
            description: 'eight writers on a cap of about one record: no throwable and 160/160 records kept '
               . '(escapes: ' . count($escapes) . ", written: $written, found: $found)"
         );
      }
      finally {
         foreach ($PIDs as $PID) {
            if ($PID > 0 && pcntl_waitpid($PID, $status, WNOHANG) === 0) {
               posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status);
            }
         }
         $purge($dir, true);
      }
   }
);
