<?php


use Bootgly\ACI\Process\Census;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Process\State: the stamped proof (Darwin, forced here) binds the stamp of the lock holder to the process table — passive, refusing recycled PIDs, impostors, free locks, zombies, foreign UIDs and swapped inodes; a holder that cannot stamp fails closed',
   // ? The proof needs fork and the system `ps` with its keywords (BusyBox
   //   lacks them) — never skipped on Darwin, whose proof it is: there an
   //   unreadable process table must fail
   skip: function_exists('pcntl_fork') === false
      || (PHP_OS_FAMILY !== 'Darwin' && Census::read((int) getmypid()) === []),
   test: function () {
      // ! The Darwin proof runs on Linux by forcing the switch. It is
      //   restored in finally: no later case may inherit Darwin's mode.
      $default = State::$stamped;
      State::$stamped = true;

      $self = (int) getmypid();
      $root = posix_geteuid() === 0;
      $Holder = new State('StateStampProofTest', (string) $self);
      $Other = new State('StateStampProofTest', "{$self}-other");
      $Native = new State('StateStampProofTest', "{$self}-native");
      $Shared = new State('StateStampProofTest', "{$self}-shared");
      $Limited = new State('StateStampProofTest', "{$self}-limited");
      $file = $Holder->pidLockFile;
      $swap = "{$file}.swap";
      /** @var array<int,int> $children PIDs reaped in finally */
      $children = [];
      // ! A forked child runs its body, then kills itself: no shutdown
      //   handler of the runner may run twice
      $spawn = static function (Closure $Body) use (&$children): int {
         $PID = pcntl_fork();
         if ($PID === 0) {
            $Body();
            posix_kill(posix_getpid(), SIGKILL);
         }
         if ($PID > 0) {
            $children[] = $PID;
         }

         return $PID;
      };
      $reap = static function (int $PID): void {
         if ($PID > 0 && pcntl_waitpid($PID, $status, WNOHANG) === 0) {
            posix_kill($PID, SIGKILL);
            pcntl_waitpid($PID, $status);
         }
      };
      // ! A forked child answers over a socket pair, then kills itself
      $Ask = static function (Closure $Question) use ($reap): string {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            return 'no pair';
         }
         $child = pcntl_fork();
         if ($child === 0) {
            fclose($Pair[0]);
            fwrite($Pair[1], (string) $Question());
            fclose($Pair[1]);
            posix_kill(posix_getpid(), SIGKILL);
         }
         fclose($Pair[1]);
         $answer = $child > 0 ? (string) stream_get_contents($Pair[0]) : 'no fork';
         fclose($Pair[0]);
         $reap($child);

         // :
         return $answer;
      };
      // ! Rewrite a stamp through a second handle — a lock stays held
      $stamp = static function (string $content, null|string $path = null) use ($file): void {
         $path ??= $file;
         $Handle = fopen($path, 'r+b');
         if ($Handle !== false) {
            ftruncate($Handle, 0);
            fwrite($Handle, $content);
            fclose($Handle);
         }
         clearstatcache(true, $path);
      };

      try {
         // # The holder stamps itself under the lock
         $start = time();
         $locked = $Holder->lock(LOCK_EX | LOCK_NB);
         clearstatcache(true, $file);
         $content = (string) @file_get_contents($file);
         $mtime = (int) @filemtime($file);

         yield assert(
            assertion: $locked && $content === "{$self}\n" && $mtime >= $start,
            description: 'lock() stamps the PID of the holder through the locked descriptor, with a fresh mtime — got ' . json_encode($content)
         );
         yield assert(
            assertion: $Holder->authenticate($self),
            description: 'the stamped holder attests as the master'
         );
         yield assert(
            assertion: $Holder->authenticate(0) === false
               && $Holder->authenticate(-1) === false
               && $Holder->authenticate($self, 0) === false,
            description: 'non-positive PIDs and parents fail closed'
         );

         // # A held lock locked again — as a reload's fresh image locks the
         //   one it adopted — keeps the stamp that names this PID
         clearstatcache(true, $file);
         $past = (int) filemtime($file) - 10;
         touch($file, $past);
         $again = $Holder->lock(LOCK_EX | LOCK_NB);
         clearstatcache(true, $file);
         $kept = $again && @file_get_contents($file) === "{$self}\n" && (int) filemtime($file) === $past;

         yield assert(
            assertion: $kept,
            description: 'lock() again on a held lock leaves a stamp naming this PID untouched: the same bytes, the same mtime'
         );

         // @ ... and stamps again a held lock whose stamp names another PID
         $stamp("4194304123\n");
         $again = $Holder->lock(LOCK_EX | LOCK_NB);
         clearstatcache(true, $file);
         $content = (string) @file_get_contents($file);

         yield assert(
            assertion: $again && $content === "{$self}\n" && $Holder->authenticate($self),
            description: 'lock() again on a held lock whose stamp names another PID stamps this PID, replacing the longer stamp whole — got ' . json_encode($content)
         );

         $otherCreated = $Other->lock(LOCK_EX | LOCK_NB) && $Other->lock(LOCK_UN);

         yield assert(
            assertion: $otherCreated && $Other->authenticate($self) === false,
            description: 'the lock of another qualified instance never attests this holder'
         );

         // # A shared lock() never stamps
         $sharedFile = $Shared->pidLockFile;
         $Shared->lock(LOCK_EX | LOCK_NB);
         $Shared->lock(LOCK_UN);
         $stamp("1\n", $sharedFile);
         touch($sharedFile, time() - 10);
         clearstatcache(true, $sharedFile);
         $mtime = (int) filemtime($sharedFile);
         $sharedLocked = $Shared->lock(LOCK_SH | LOCK_NB);
         clearstatcache(true, $sharedFile);
         $untouched = @file_get_contents($sharedFile) === "1\n" && (int) filemtime($sharedFile) === $mtime;
         $Shared->lock(LOCK_UN);

         yield assert(
            assertion: $sharedLocked && $untouched,
            description: 'a shared lock() never stamps: the bytes and the mtime stay as they were'
         );

         // # A holder that cannot write its stamp fails closed
         $answer = $Ask(static function () use ($Limited): string {
            // ! Not one byte may be written — SIGXFSZ ignored, so the write
            //   fails (EFBIG) instead of killing the child
            pcntl_signal(SIGXFSZ, SIG_IGN);
            if (posix_setrlimit(POSIX_RLIMIT_FSIZE, 0, 0) === false) {
               return 'unlimited';
            }
            $locked = $Limited->lock(LOCK_EX | LOCK_NB);

            return ($locked ? 'locked' : 'refused') . ($Limited->held ? ' held' : ' free');
         });

         yield assert(
            assertion: $answer === 'refused free',
            description: "a holder whose stamp cannot be written fails closed: lock() refuses and holds nothing ({$answer})"
         );

         // @ The next holder takes that lock, its stamp replacing a longer one
         $stamp("4194304123\n", $Limited->pidLockFile);
         $retaken = $Limited->lock(LOCK_EX | LOCK_NB);
         clearstatcache(true, $Limited->pidLockFile);
         $content = (string) @file_get_contents($Limited->pidLockFile);
         $proven = $Limited->authenticate($self);
         $Limited->lock(LOCK_UN);

         yield assert(
            assertion: $retaken && $content === "{$self}\n" && $proven,
            description: 'the next holder takes that lock and attests, its stamp replacing a longer one whole — got ' . json_encode($content)
         );

         // # A direct child holding the inherited descriptor
         $worker = $spawn(static fn () => usleep(10000000));

         yield assert(
            assertion: $worker > 0 && $Holder->authenticate($worker, $self),
            description: 'a direct child keeping the inherited descriptor attests as a worker of the stamped master'
         );
         yield assert(
            assertion: $Holder->authenticate($worker) === false,
            description: 'the stamp names the master: a worker never attests as the master'
         );
         yield assert(
            assertion: $Holder->authenticate($worker, $self + 1) === false,
            description: 'a worker never attests against a parent the stamp does not name'
         );
         yield assert(
            assertion: $Holder->authenticate(posix_getppid(), $self) === false,
            description: 'a live process that is no child of the master never attests as its worker'
         );

         // # A child that became a session leader, still holding the descriptor
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         $leader = $Pair === false ? -1 : $spawn(static function () use ($Pair): void {
            fclose($Pair[0]);
            fwrite($Pair[1], posix_setsid() !== -1 ? 'ready' : 'fails');
            usleep(10000000);
         });
         $ready = '';
         if ($Pair !== false) {
            fclose($Pair[1]);
            $ready = (string) fread($Pair[0], 5);
            fclose($Pair[0]);
         }

         yield assert(
            assertion: $ready === 'ready' && $Holder->authenticate($leader) === false,
            description: 'an inherited holder never attests as the master by becoming a session leader'
         );

         // # A group/world-readable lock inode
         chmod($file, 0644);
         $unsafe = $Holder->authenticate($self);
         chmod($file, 0600);

         yield assert(
            assertion: $unsafe === false,
            description: 'a group/world-accessible lock inode never attests'
         );

         // # The stamp must name the master, exactly
         $forged = [];
         foreach (
            ["1\n", '', "0\n", "0{$self}\n", " {$self}\n", "+{$self}\n", "{$self}\r\n", "{$self}\n\n", "{$self}\nX", (string) $self]
            as $body
         ) {
            $stamp($body);
            if ($Holder->authenticate($self)) {
               $forged[] = json_encode($body);
            }
         }
         $stamp("{$self}\n");

         yield assert(
            assertion: $forged === [] && $Holder->authenticate($self),
            description: 'a stamp naming another PID, or not exactly "<PID>\n", never attests — accepted: ' . implode(', ', $forged)
         );

         // @ A stamp naming the worker binds no master: the worker never
         //   attests, not even beside its real parent
         $stamp("{$worker}\n");
         $selfStamped = $Holder->authenticate($worker, $self);
         $stamp("{$self}\n");

         yield assert(
            assertion: $selfStamped === false,
            description: 'a stamp naming a worker never attests that worker, even as a child of the real master'
         );

         // # PID reuse: a holder born after the stamp is a recycled PID
         $recycled = $spawn(static fn () => usleep(10000000));
         $stamp("{$recycled}\n");
         touch($file, time() - 30);
         $reused = $Holder->authenticate($recycled);
         touch($file, time());
         $current = $Holder->authenticate($recycled);
         $stamp("{$self}\n");

         yield assert(
            assertion: $recycled > 0 && $reused === false,
            description: 'a process born after the stamp it is named by never attests (a recycled PID)'
         );
         yield assert(
            assertion: $current,
            description: 'the same process attests once the stamp postdates its birth (the control)'
         );

         // # The reuse window is SKEW wide, in both directions: a holder born
         //   up to SKEW seconds after its stamp's mtime attests (procps can
         //   read a birth a second late), one born later never does. One
         //   second of margin each side absorbs that rounding; the value is
         //   pinned on its own.
         $skew = (new ReflectionClassConstant(State::class, 'SKEW'))->getValue();
         $birth = Census::read($recycled)[$recycled]['birth'] ?? 0;
         $stamp("{$recycled}\n");
         touch($file, $birth - $skew - 2);
         $outside = $Holder->authenticate($recycled);
         touch($file, $birth - $skew + 1);
         $inside = $Holder->authenticate($recycled);
         $stamp("{$self}\n");

         yield assert(
            assertion: $skew === 2 && $birth > 0 && $outside === false && $inside,
            description: "the reuse window is SKEW = 2 s: a holder born SKEW + 2 s after its stamp never attests, one born SKEW - 1 s after does (SKEW {$skew}, birth {$birth})"
         );

         // # A zombie — exited, not reaped — as the master and as a worker
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         $zombie = $Pair === false ? -1 : $spawn(static function () use ($Pair): void {
            fclose($Pair[0]);
            fread($Pair[1], 1);
         });
         $living = false;
         $livingWorker = false;
         $state = '';
         if ($Pair !== false && $zombie > 0) {
            fclose($Pair[1]);
            $stamp("{$zombie}\n");
            $living = $Holder->authenticate($zombie);
            $stamp("{$self}\n");
            $livingWorker = $Holder->authenticate($zombie, $self);
            fwrite($Pair[0], 'x');
            fclose($Pair[0]);
            // @@ Unreaped, the process table keeps it as a zombie
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
               $state = Census::read($zombie)[$zombie]['state'] ?? '';
               if (str_starts_with($state, 'Z')) {
                  break;
               }
               usleep(10000);
            }
         }
         $stamp("{$zombie}\n");
         $dead = $Holder->authenticate($zombie);
         $stamp("{$self}\n");
         $deadWorker = $Holder->authenticate($zombie, $self);

         yield assert(
            assertion: $living && str_starts_with($state, 'Z') && $dead === false,
            description: "a zombie named by the stamp never attests, while the same child attested alive (state {$state})"
         );
         yield assert(
            assertion: $livingWorker && str_starts_with($state, 'Z') && $deadWorker === false,
            description: "a zombie child never attests as a worker of the stamped master, while the same child attested alive (state {$state})"
         );

         // # A process of another UID
         $foreign = 0;
         if ($root) {
            // ! Root: a child that drops to nobody
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $nobody = $Pair === false ? -1 : $spawn(static function () use ($Pair): void {
               fclose($Pair[0]);
               fwrite($Pair[1], posix_setgid(65534) && posix_setuid(65534) ? 'ready' : 'fails');
               usleep(10000000);
            });
            if ($Pair !== false) {
               fclose($Pair[1]);
               $foreign = fread($Pair[0], 5) === 'ready' ? $nobody : 0;
               fclose($Pair[0]);
            }
         }
         else {
            // ! Any live process of another account (PID 1 on a host)
            foreach (Census::read(...range(1, Census::LIMIT)) as $PID => $row) {
               if ($row['uid'] !== posix_geteuid()) {
                  $foreign = $PID;
                  break;
               }
            }
         }

         if ($foreign > 0) {
            $stamp("{$foreign}\n");
            $attested = $Holder->authenticate($foreign);
            $stamp("{$self}\n");

            yield assert(
               assertion: $attested === false,
               description: "a process of another UID (PID {$foreign}) named by the stamp never attests"
            );
         }
         else {
            yield (new Assertion(
               description: 'UID binding not exercised: no process of another account is visible here'
            ))->skip();
         }

         // # Root: a child that dropped only its effective UID (65534; real
         //   and saved UIDs stay 0) is no worker — its UIDs disagree — and no
         //   master either. PHP sets no UID but the effective one alone (it
         //   has no setresuid), so a master's three owner checks are pinned
         //   together, by the leg above: alone, each is masked by another.
         if ($root) {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $dropped = $Pair === false ? -1 : $spawn(static function () use ($Pair): void {
               fclose($Pair[0]);
               fwrite($Pair[1], posix_seteuid(65534) ? 'ready' : 'fails');
               usleep(10000000);
            });
            $ready = '';
            if ($Pair !== false) {
               fclose($Pair[1]);
               $ready = (string) fread($Pair[0], 5);
               fclose($Pair[0]);
            }
            $stamp("{$dropped}\n");
            $asMaster = $Holder->authenticate($dropped);
            $stamp("{$self}\n");
            $asWorker = $Holder->authenticate($dropped, $self);

            yield assert(
               assertion: $ready === 'ready' && $asMaster === false && $asWorker === false,
               description: "a child running with only its effective UID dropped attests neither as the master nor as a worker ({$ready})"
            );
         }
         else {
            yield (new Assertion(
               description: 'Effective-UID binding not exercised: dropping only the effective UID needs root'
            ))->skip();
         }

         // # A new inode renamed over the held lock path
         file_put_contents($swap, "{$self}\n");
         chmod($swap, 0600);
         rename($swap, $file);
         $swapped = $Holder->authenticate($self);

         yield assert(
            assertion: $swapped === false,
            description: 'a fresh inode renamed over the held lock path never attests: its lock is free'
         );

         // @ Restore a held, stamped lock at the path
         $Holder->lock(LOCK_UN);
         @unlink($file);
         $relocked = $Holder->lock(LOCK_EX | LOCK_NB) && $Holder->authenticate($self);

         yield assert(
            assertion: $relocked,
            description: 'a fresh inode, locked and stamped again, attests its holder'
         );

         // # A swap or a re-stamp WHILE the proof reads the process table
         // ! An interceptor freezes the `ps` child the proof spawns (found in
         //   this process's procfs children list), changes the lock path
         //   under it, then lets it finish: only the post-check can refuse.
         //   It re-reads the stamp and the inode. Its explicit clearstatcache()
         //   is defence in depth no case here can single out: the fopen() and
         //   the `ps` spawn before it refresh PHP's per-path lstat cache too.
         $list = "/proc/{$self}/task/{$self}/children";
         $intercept = static function (Closure $Action) use ($Holder, $self, $list, $reap): array {
            // @@ A miss (the `ps` exited before it was caught) is retried
            for ($attempt = 1; $attempt <= 25; $attempt++) {
               $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
               if ($Pair === false) {
                  break;
               }
               $watcher = pcntl_fork();
               if ($watcher === 0) {
                  fclose($Pair[0]);
                  $me = posix_getpid();
                  $known = array_flip(array_map('intval', preg_split('/\s+/', trim((string) @file_get_contents($list))) ?: []));
                  fwrite($Pair[1], 'R');
                  $deadline = microtime(true) + 2.0;
                  // @@
                  while (microtime(true) < $deadline) {
                     foreach (preg_split('/\s+/', trim((string) @file_get_contents($list))) ?: [] as $entry) {
                        $PID = (int) $entry;
                        if ($PID <= 0 || $PID === $me || isSet($known[$PID])) {
                           continue;
                        }
                        // ! Frozen for real (state T), not exiting (Z): the
                        //   proof is then blocked on that `ps`, before its
                        //   post-check
                        posix_kill($PID, SIGSTOP);
                        $state = '';
                        for ($i = 0; $i < 2000; $i++) {
                           $stat = (string) @file_get_contents("/proc/{$PID}/stat");
                           $state = $stat === '' ? '' : substr($stat, (int) strrpos($stat, ')') + 2, 1);
                           if ($state === 'T' || $state === 't' || $state === 'Z' || $state === '') {
                              break;
                           }
                           usleep(50);
                        }
                        if ($state === 'T' || $state === 't') {
                           $Action();
                           fwrite($Pair[1], 'C');
                        }
                        posix_kill($PID, SIGCONT);
                        break 2;
                     }
                  }
                  fclose($Pair[1]);
                  posix_kill($me, SIGKILL);
               }
               fclose($Pair[1]);
               if ($watcher < 0) {
                  fclose($Pair[0]);
                  break;
               }
               fread($Pair[0], 1);
               $result = $Holder->authenticate($self);
               // ? `C` is written before the frozen `ps` resumes, so it is
               //   already here when the proof returns
               stream_set_blocking($Pair[0], false);
               $caught = fread($Pair[0], 1) === 'C';
               fclose($Pair[0]);
               $reap($watcher);
               if ($caught) {
                  return [$result, $attempt];
               }
            }

            // : Never caught — no verdict
            return [null, $attempt - 1];
         };

         if (is_readable($list) === false) {
            yield (new Assertion(
               description: 'Mid-check swap not exercised: no procfs children list to catch the `ps` run'
            ))->skip();
         }
         else {
            // @ Control: frozen and resumed, nothing changed
            [$control, $tries] = $intercept(static function (): void {});

            yield assert(
               assertion: $control === true,
               description: "a proof frozen mid-check and resumed unchanged still attests (the control; attempts: {$tries})"
            );

            // @ The stamp rewritten in place: the mtime moves on
            clearstatcache(true, $file);
            $moved = (int) filemtime($file) + 5;
            [$restamped, $tries] = $intercept(static function () use ($file, $moved): void {
               touch($file, $moved);
            });
            touch($file, time());

            yield assert(
               assertion: $restamped === false,
               description: "a stamp rewritten while the proof reads the process table never attests (attempts: {$tries})"
            );

            // @ A new holder's stamp within the same second, with a PID of
            //   the same length: the mtime restored, the size unchanged —
            //   only the stamp itself differs
            $twin = strlen((string) ($self + 1)) === strlen((string) $self) ? $self + 1 : $self - 1;
            [$twinned, $tries] = $intercept(static function () use ($file, $twin): void {
               clearstatcache(true, $file);
               $mtime = (int) filemtime($file);
               $Handle = fopen($file, 'r+b');
               if ($Handle !== false) {
                  ftruncate($Handle, 0);
                  fwrite($Handle, "{$twin}\n");
                  fclose($Handle);
               }
               touch($file, $mtime);
            });
            $stamp("{$self}\n");

            yield assert(
               assertion: $twinned === false,
               description: "a same-length stamp written within the same second while the proof reads the process table never attests (attempts: {$tries})"
            );

            // @ A new inode renamed over the path
            file_put_contents($swap, "{$self}\n");
            chmod($swap, 0600);
            [$replaced, $tries] = $intercept(static function () use ($swap, $file): void {
               rename($swap, $file);
            });

            yield assert(
               assertion: $replaced === false,
               description: "an inode renamed over the lock path while the proof reads the process table never attests (attempts: {$tries})"
            );

            // @ Restore
            $Holder->lock(LOCK_UN);
            @unlink($file);
            @unlink($swap);
            $Holder->lock(LOCK_EX | LOCK_NB);
         }

         // # A free lock, whose stamp still names a live process
         $Holder->lock(LOCK_UN);
         clearstatcache(true, $file);

         yield assert(
            assertion: @file_get_contents($file) === "{$self}\n" && $Holder->authenticate($self) === false,
            description: 'an unlocked inode never attests, though its stamp still names a live process'
         );

         // # The default mode of this OS
         State::$stamped = $default;
         $native = $Native->lock(LOCK_EX | LOCK_NB);
         clearstatcache(true, $Native->pidLockFile);
         $size = (int) @filesize($Native->pidLockFile);
         $nativeAttested = $Native->authenticate($self);
         $Native->lock(LOCK_UN);
         State::$stamped = true;

         yield assert(
            assertion: $native && $nativeAttested && ($default ? $size > 0 : $size === 0),
            description: $default
               ? 'by default this OS stamps its locks and attests the holder'
               : 'by default Linux leaves its lock empty and keeps the procfs proof'
         );

         // # Pins: the switch is the OS family — never a /proc probe
         $declared = (new ReflectionProperty(State::class, 'stamped'))->getDefaultValue();

         yield assert(
            assertion: $default === (PHP_OS_FAMILY === 'Darwin') && $declared === (PHP_OS_FAMILY === 'Darwin'),
            description: 'State::$stamped defaults to PHP_OS_FAMILY === \'Darwin\''
         );

         $tokens = token_get_all((string) file_get_contents((string) (new ReflectionClass(State::class))->getFileName()));
         $significant = array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => is_string($token)
               || ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT)
         ));
         $writes = [
            T_COALESCE_EQUAL, T_OR_EQUAL, T_AND_EQUAL, T_XOR_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL,
            T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_POW_EQUAL, T_CONCAT_EQUAL, T_SL_EQUAL, T_SR_EQUAL
         ];
         $expression = null;
         $assignments = 0;
         foreach ($significant as $index => $token) {
            if (is_array($token) === false || $token[0] !== T_VARIABLE || $token[1] !== '$stamped') {
               continue;
            }
            $previous = $significant[$index - 1] ?? '';
            $next = $significant[$index + 1] ?? '';
            // ? The declaration: everything up to its `;`
            if (is_array($previous) && $previous[0] === T_STRING && $previous[1] === 'bool') {
               $expression = '';
               for ($cursor = $index + 2; isSet($significant[$cursor]) && $significant[$cursor] !== ';'; $cursor++) {
                  $piece = $significant[$cursor];
                  $expression .= is_array($piece) ? $piece[1] : $piece;
               }
            }
            // ? Any later write (`self::$stamped = ...`)
            else if ($next === '=' || (is_array($next) && in_array($next[0], $writes, true))) {
               $assignments++;
            }
         }

         yield assert(
            assertion: $expression === "PHP_OS_FAMILY==='Darwin'" && $assignments === 0,
            description: 'the declared default is exactly `PHP_OS_FAMILY === \'Darwin\'` and State never rewrites it — no /proc probe decides the mode (got ' . json_encode($expression) . ", {$assignments} write(s))"
         );
      }
      finally {
         State::$stamped = $default;
         foreach ($children as $PID) {
            $reap($PID);
         }
         @chmod($file, 0600);
         $Holder->lock(LOCK_UN);
         $Other->lock(LOCK_UN);
         $Native->lock(LOCK_UN);
         $Shared->lock(LOCK_UN);
         $Limited->lock(LOCK_UN);
         foreach ([$Holder, $Other, $Native, $Shared, $Limited] as $State) {
            @unlink($State->pidFile);
            @unlink($State->pidLockFile);
            @unlink($State->commandFile);
         }
         @unlink($swap);
      }
   }
);
