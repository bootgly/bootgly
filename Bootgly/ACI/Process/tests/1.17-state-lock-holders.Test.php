<?php


use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Process\State: $held proves a same-process holder of an instance lock — a live exclusive descriptor at the lock path, claimed on lock and adopt, never by a contender, a shared lock, a dropped or replaced holder, or a forked child',
   skip: function_exists('pcntl_fork') === false,
   test: function () {
      // ! A same-process holder is proven by the process's own claim, not
      //   by procfs (Darwin has none): Input adopts the launcher's lock
      //   through it. Every qualifier is this run's own, so two checkouts
      //   never collide.
      $qualifier = (string) getmypid();
      $id = 'StateHoldersTest';
      $Holder = new State($id, $qualifier);
      $file = $Holder->pidLockFile;
      // ! A forked child answers over a socket pair, then kills itself
      //   (no shutdown handler of the runner may run twice)
      $Ask = static function (Closure $Question): string {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            return 'no pair';
         }
         $child = pcntl_fork();
         if ($child === -1) {
            fclose($Pair[0]);
            fclose($Pair[1]);

            return 'no fork';
         }
         if ($child === 0) {
            fclose($Pair[0]);
            fwrite($Pair[1], (string) $Question());
            fclose($Pair[1]);
            posix_kill(posix_getpid(), SIGKILL);
         }
         fclose($Pair[1]);
         $answer = (string) stream_get_contents($Pair[0]);
         fclose($Pair[0]);
         pcntl_waitpid($child, $status);

         // :
         return $answer;
      };

      try {
         $Contender = new State($id, $qualifier);

         yield assert(
            assertion: $Holder->held === false && $Contender->held === false,
            description: 'Nothing is held before the lock'
         );

         $locked = $Holder->lock(LOCK_EX | LOCK_NB);

         yield assert(
            assertion: $locked && $Holder->held,
            description: 'An exclusive lock is held by this process'
         );

         // @ A contender in the same process fails the lock, but reads the
         //   holder's claim (what Input adopts)
         $contended = $Contender->lock(LOCK_EX | LOCK_NB);

         yield assert(
            assertion: $contended === false && $Contender->held,
            description: 'A same-process contender fails the lock and finds it held by this process'
         );

         // @ A contender's detach() leaves the holder's claim alone
         $Contender->detach();

         yield assert(
            assertion: $Holder->held,
            description: "A contender's detach() keeps the holder's claim"
         );

         // @ Another qualifier is another lock file; a shared lock claims nothing
         $Shared = new State($id, "{$qualifier}-shared");
         $shared = $Shared->lock(LOCK_SH | LOCK_NB);

         yield assert(
            assertion: $shared && $Shared->held === false,
            description: 'A shared lock is never held as the instance lock'
         );
         $Shared->lock(LOCK_UN);
         @unlink($Shared->pidLockFile);

         // @ A forked child inherits the claim map, but never holds the claim
         $answer = $Ask(static fn (): string => $Contender->held ? 'held' : 'not held');

         yield assert(
            assertion: $answer === 'not held',
            description: "A forked child never holds its parent's claim ({$answer})"
         );

         // @ Unlock releases the claim
         $Holder->lock(LOCK_UN);

         yield assert(
            assertion: $Holder->held === false && $Contender->held === false,
            description: 'LOCK_UN releases the claim'
         );

         // @ LOCK_UN | LOCK_NB unlocks as well — flock() reads LOCK_UN's two
         //   bits — and must never leave a claim on the lock it released
         $Released = new State($id, $qualifier);
         $released = $Released->lock(LOCK_EX | LOCK_NB) && $Released->lock(LOCK_UN | LOCK_NB);
         $claimed = $Released->held;
         $exported = $Released->export() !== null;
         $Successor = new State($id, $qualifier);
         $succeeded = $Successor->lock(LOCK_EX | LOCK_NB);
         $Successor->lock(LOCK_UN);

         yield assert(
            assertion: $released && $claimed === false && $exported === false && $succeeded,
            description: 'LOCK_UN | LOCK_NB releases as LOCK_UN does: no claim, no hand-off, the lock free for the next holder'
         );

         // @ A holder dropped without an unlock takes its descriptor — and
         //   its kernel lock — with it: its claim proves nothing anymore
         $Dropped = new State($id, $qualifier);
         $dropped = $Dropped->lock(LOCK_EX | LOCK_NB);
         unset($Dropped);

         yield assert(
            assertion: $dropped && $Contender->held === false,
            description: 'A holder dropped without unlock is no longer held'
         );

         // @ A lock path replaced under the holder is another inode: the
         //   claim covers the inode it locked, not the pathname
         $Replaced = new State($id, $qualifier);
         $replaced = $Replaced->lock(LOCK_EX | LOCK_NB);
         @unlink($file);
         touch($file);

         yield assert(
            assertion: $replaced && $Replaced->held === false && $Contender->held === false,
            description: 'A replaced lock path is no longer held'
         );
         $Replaced->detach();
         @unlink($file);

         // @ The holder's detach() releases the claim (its descriptor is gone)
         $Detached = new State($id, $qualifier);
         $detached = $Detached->lock(LOCK_EX | LOCK_NB);
         $Detached->detach();

         yield assert(
            assertion: $detached && $Detached->held === false,
            description: "The holder's detach() releases the claim"
         );

         // @ An adopted handoff descriptor is held by the adopting process: a
         //   forked child inherits the exporter's descriptor and its claim
         //   map, so only adopt() can make the child hold it
         $Exporter = new State($id, $qualifier);
         $exported = $Exporter->lock(LOCK_EX | LOCK_NB);
         $Handle = $Exporter->export();
         $answer = $exported && is_resource($Handle)
            ? $Ask(static function () use ($id, $qualifier, $Handle): string {
               $Adopter = new State($id, $qualifier);
               $before = $Adopter->held;
               $adopted = $Adopter->adopt($Handle);

               return ! $before && $adopted && $Adopter->held ? 'held' : 'not held';
            })
            : 'not exported';
         $Exporter->lock(LOCK_UN);

         yield assert(
            assertion: $answer === 'held',
            description: "adopt() holds the lock for the adopting process ({$answer})"
         );
      }
      finally {
         // ! Cleanup
         $Holder->lock(LOCK_UN);
         @unlink($file);
      }
   }
);
