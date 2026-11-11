<?php

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Signalling;
use Bootgly\ACI\Process\Signals;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events\Select;


/**
 * `Signalling::trap()` / `untrap()` on the Select reactor: signal handlers
 * owned by the loop, dispatched between callbacks at the top of a pass and
 * after an interrupted wait.
 *
 * Only SIGUSR1 and SIGUSR2 are raised, each always caught: the runner process
 * is the one signalled. Their dispositions, the asynchronous-signal switch and
 * the throwable reporters are captured first and restored in `finally`.
 */
return new Test(
   description: 'Select::trap() should run a signal handler on the loop between callbacks, keep out-of-band handlers dispatched, survive a throwing handler and restore what it found on untrap() and destroy()',
   test: new Assertions(Case: function (): Generator {
      $Reflection = new ReflectionClass(Select::class);
      $Handlers = $Reflection->getProperty('Handlers');
      $Connections = new class implements Connections {
         public function connect (): bool
         {
            return true;
         }
      };
      $reactor = static function () use (&$Connections): Select {
         return new Select($Connections);
      };
      $MS = 1_000_000;
      // ! Every loop run is bounded: a handler that never comes cannot hang
      //   the runner — the run reports `expired` instead
      $run = static function (Select $Select, float $limit = 2.0) use ($MS): array {
         $expired = false;
         $Select->loop = true;
         $ID = $Select->repeat($limit, static function () use (&$expired, $Select): void {
            $expired = true;
            $Select->loop = false;
         });
         $started = (int) hrtime(true);
         $Select->loop();
         $elapsed = ((int) hrtime(true) - $started) / $MS;
         $Select->cancel($ID);

         return ['elapsed' => $elapsed, 'expired' => $expired];
      };
      // ! A descriptor that never becomes readable: the selector blocks on it
      $block = static function (Select $Select) use (&$Sockets): void {
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($pair === false) {
            throw new RuntimeException('Could not create the socket pair.');
         }
         $Sockets[] = $pair;
         $Select->add($pair[0], Select::EVENT_READ, null);
      };
      // ! Tokens of one method's body — a call is pinned by token, not by text
      $count = static function (string $method, string $function) use ($Reflection): int {
         $Method = $Reflection->getMethod($method);
         $lines = array_slice(
            file((string) $Method->getFileName()),
            $Method->getStartLine() - 1,
            $Method->getEndLine() - $Method->getStartLine() + 1
         );
         $calls = 0;
         foreach (token_get_all('<?php ' . implode('', $lines)) as $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === $function) {
               $calls++;
            }
         }

         return $calls;
      };
      $self = posix_getpid();
      $Sockets = [];
      $Loops = [];
      $Reporters = Throwables::$reporters;
      $async = pcntl_async_signals(false);
      $Found = [
         SIGUSR1 => pcntl_signal_get_handler(SIGUSR1),
         SIGUSR2 => pcntl_signal_get_handler(SIGUSR2),
      ];
      // ! What the signals do before any loop traps them: two known closures,
      //   so "put back what it found" is an identity, and no raise ever meets
      //   a default action
      $Seen = [];
      $Base1 = static function (int $signal) use (&$Seen): void {
         $Seen[] = "base:{$signal}";
      };
      $Base2 = static function (int $signal) use (&$Seen): void {
         $Seen[] = "base:{$signal}";
      };

      try {
         pcntl_signal(SIGUSR1, $Base1, false);
         pcntl_signal(SIGUSR2, $Base2, false);

         // @@ A) The capability is optional: Loops gained nothing
         $names = static fn (string $interface): array => array_map(
            static fn (ReflectionMethod $Method): string => $Method->getName(),
            new ReflectionClass($interface)->getMethods()
         );
         $Legacy = new class implements Loops {
            public function add ($Socket, int $flag, $payload): bool { return true; }
            public function del ($Socket, int $flag): bool { return true; }
            public function loop (): void {}
            public function destroy (): void {}
         };

         yield assert(
            assertion: $names(Loops::class) === ['add', 'del', 'loop', 'destroy']
               && $names(Scheduler::class) === ['schedule', 'defer', 'cancel', 'interrupt']
               && array_values(array_diff($names(Signalling::class), $names(Loops::class))) === ['trap', 'untrap']
               && $Legacy instanceof Signalling === false
               && $reactor() instanceof Signalling
               && is_subclass_of(Signalling::class, Loops::class),
            description: 'Loops and Scheduler keep their methods, a loop written against Loops stays valid and is not Signalling, Select is — observed: '
               . json_encode([$names(Loops::class), $names(Scheduler::class), $names(Signalling::class), $Legacy instanceof Signalling])
         );

         // @@ B) Signals a process cannot catch are refused before PHP is asked
         $Select = $reactor();
         $refused = [];
         foreach ([SIGKILL, SIGSTOP, 0, -1, 32, 33, 65, PHP_INT_MAX] as $signal) {
            try {
               $Select->trap($signal, static function (): void {});
               $refused[$signal] = false;
            }
            catch (InvalidArgumentException) {
               $refused[$signal] = true;
            }
         }

         yield assert(
            assertion: in_array(false, $refused, true) === false
               && $Handlers->getValue($Select) === []
               && pcntl_signal_get_handler(SIGUSR1) === $Base1,
            description: 'SIGKILL, SIGSTOP and numbers that are not catchable signals are refused with InvalidArgumentException and nothing is installed — observed: '
               . json_encode($refused)
         );

         // @@ C) A loop that never trapped a signal installs, changes and
         //    restores nothing — destroy() included
         $Catchable = array_values(array_diff(range(1, 31), [SIGKILL, SIGSTOP]));
         $dispositions = static fn (): array => array_map(
            static fn (int $signal): mixed => pcntl_signal_get_handler($signal),
            $Catchable
         );
         $Before = $dispositions();
         $Select = $reactor();
         $block($Select);
         $released = $Select->untrap(SIGUSR1);
         $Select->destroy();
         $After = $dispositions();

         yield assert(
            assertion: $released === false && $Before === $After,
            description: 'a loop without traps leaves every disposition identical through untrap() and destroy()'
         );

         // @@ D) A signal raised outside the wait runs at the top of the next
         //    pass, with its number; a handler that stops the loop is obeyed
         //    in that same pass
         $Select = $reactor();
         $Loops[] = $Select;
         $block($Select);
         $Seen = [];
         $Select->trap(SIGUSR1, static function (int $signal) use (&$Seen, $Select): void {
            $Seen[] = $signal;
            $Select->loop = false;
         });
         $installed = pcntl_signal_get_handler(SIGUSR1);
         posix_kill($self, SIGUSR1);
         $Run = $run($Select);

         yield assert(
            assertion: $Seen === [SIGUSR1] && $Run['expired'] === false && $Run['elapsed'] < 500.0
               && $installed instanceof Closure && $installed !== $Base1,
            description: 'a pending signal reaches the trapped handler on the first pass and the loop stops there, without waiting — observed: '
               . json_encode([$Seen, $Run])
         );

         // @@ E) A signal raised during the wait wakes the selector
         $Seen = [];
         $PID = pcntl_fork();
         if ($PID === 0) {
            usleep(150_000);
            posix_kill($self, SIGUSR1);
            // ! Hard exit — no shutdown handlers, no inherited output flush
            posix_kill(posix_getpid(), SIGKILL);
         }
         $Run = $run($Select);
         pcntl_waitpid((int) $PID, $status);

         yield assert(
            assertion: $Seen === [SIGUSR1] && $Run['expired'] === false
               && $Run['elapsed'] >= 100.0 && $Run['elapsed'] < 1500.0,
            description: 'a signal sent 150 ms into a blocked wait interrupts it and runs the handler then — observed: '
               . json_encode([$Seen, $Run])
         );

         // @@ F) Out-of-band handlers keep running on the loop — beside a
         //    trapped one, and on a loop that trapped nothing
         $Seen = [];
         posix_kill($self, SIGUSR2);
         posix_kill($self, SIGUSR1);
         $Run = $run($Select);
         $Select->destroy();
         $beside = $Seen;

         $Bare = $reactor();
         $block($Bare);
         $Seen = [];
         pcntl_signal(SIGUSR2, static function (int $signal) use (&$Seen, $Bare): void {
            $Seen[] = "outside:{$signal}";
            $Bare->loop = false;
         }, false);
         posix_kill($self, SIGUSR2);
         $BareRun = $run($Bare);
         $Bare->destroy();
         pcntl_signal(SIGUSR2, $Base2, false);

         yield assert(
            assertion: $beside === ['base:' . SIGUSR2, SIGUSR1] && $Run['expired'] === false
               && $Seen === ['outside:' . SIGUSR2] && $BareRun['expired'] === false && $BareRun['elapsed'] < 500.0,
            description: 'a handler installed with pcntl_signal() is dispatched by the same pass as a trapped one, and by a loop with no trap at all — observed: '
               . json_encode([$beside, $Seen, $Run, $BareRun])
         );

         // @@ G) One handler per signal: trapping again replaces it; untrap()
         //    puts back what the first trap found
         $Select = $reactor();
         $Loops[] = $Select;
         $block($Select);
         $Seen = [];
         $Select->trap(SIGUSR1, static function () use (&$Seen): void {
            $Seen[] = 'first';
         });
         $Select->trap(SIGUSR1, static function () use (&$Seen, $Select): void {
            $Seen[] = 'second';
            $Select->loop = false;
         });
         $held = count($Handlers->getValue($Select));
         posix_kill($self, SIGUSR1);
         $Run = $run($Select);
         $first = $Select->untrap(SIGUSR1);
         $restored = pcntl_signal_get_handler(SIGUSR1);
         $second = $Select->untrap(SIGUSR1);

         yield assert(
            assertion: $Seen === ['second'] && $held === 1 && $Run['expired'] === false
               && $first === true && $second === false && $restored === $Base1
               && $Handlers->getValue($Select) === [],
            description: 'the second trap of a signal replaces the first handler; untrap() answers true once and restores the handler found — observed: '
               . json_encode([$Seen, $held, $first, $second, $restored === $Base1])
         );

         // @@ H) A handler that throws is reported once and the dispatch goes
         //    on: the other pending signal still runs in the same pass
         $Reported = [];
         Throwables::$reporters = [static function (Throwable $Throwable, array $context) use (&$Reported): void {
            $Reported[] = [$Throwable->getMessage(), $context];
         }];
         $Seen = [];
         $Select->trap(SIGUSR1, static function () use (&$Seen): void {
            $Seen[] = 'throws';

            throw new RuntimeException('signal handler failure');
         });
         $Select->trap(SIGUSR2, static function (int $signal) use (&$Seen, $Select): void {
            $Seen[] = $signal;
            $Select->loop = false;
         });
         posix_kill($self, SIGUSR1);
         posix_kill($self, SIGUSR2);
         $thrown = null;
         try {
            $Run = $run($Select);
         }
         catch (Throwable $Throwable) {
            $thrown = $Throwable::class;
         }
         Throwables::$reporters = $Reporters;

         yield assert(
            assertion: $thrown === null && $Seen === ['throws', SIGUSR2]
               && $Run['expired'] === false && $Run['elapsed'] < 500.0
               && $Reported === [['signal handler failure', ['origin' => 'loop.signal', 'signal' => SIGUSR1]]],
            description: 'the throwable goes to Throwables::notify() with its origin and signal, the loop keeps running and the second signal is handled in the same pass — observed: '
               . json_encode([$thrown, $Seen, $Reported, $Run])
         );

         // @@ I) Re-entrancy: a handler releases itself, traps another signal
         //    and arms a timer — the timer runs in the same pass
         $Select->destroy();
         $Select = $reactor();
         $Loops[] = $Select;
         $block($Select);
         $Seen = [];
         $Select->trap(SIGUSR1, static function () use (&$Seen, $Select): void {
            $Seen[] = 'one';
            $Select->untrap(SIGUSR1);
            $Select->trap(SIGUSR2, static function () use (&$Seen, $Select): void {
               $Seen[] = 'two';
               $Select->loop = false;
            });
            $Select->defer((int) hrtime(true), static function () use (&$Seen, $Select): void {
               $Seen[] = 'timer';
               $Select->loop = false;
            });
         });
         posix_kill($self, SIGUSR1);
         $Run = $run($Select);
         $swapped = pcntl_signal_get_handler(SIGUSR1) === $Base1
            && array_keys($Handlers->getValue($Select)) === [SIGUSR2];
         posix_kill($self, SIGUSR2);
         $Next = $run($Select);

         yield assert(
            assertion: $Seen === ['one', 'timer', 'two'] && $swapped
               && $Run['expired'] === false && $Run['elapsed'] < 500.0 && $Next['expired'] === false,
            description: 'a handler may release its own signal and trap another; the timer it arms fires before the loop waits — observed: '
               . json_encode([$Seen, $swapped, $Run, $Next])
         );

         // @@ J) destroy() releases every trap and a re-armed loop traps again;
         //    a disposition replaced after the trap is left alone
         $Select->trap(SIGUSR1, static function (): void {});
         $Select->destroy();
         $cleared = pcntl_signal_get_handler(SIGUSR1) === $Base1
            && pcntl_signal_get_handler(SIGUSR2) === $Base2
            && $Handlers->getValue($Select) === []
            && $Select->untrap(SIGUSR2) === false;
         $block($Select);
         $Seen = [];
         $Select->trap(SIGUSR1, static function (int $signal) use (&$Seen, $Select): void {
            $Seen[] = $signal;
            $Select->loop = false;
         });
         posix_kill($self, SIGUSR1);
         $Run = $run($Select);
         $Foreign = static function (): void {};
         pcntl_signal(SIGUSR1, $Foreign, false);
         $released = $Select->untrap(SIGUSR1);
         $kept = pcntl_signal_get_handler(SIGUSR1) === $Foreign;
         pcntl_signal(SIGUSR1, $Base1, false);

         yield assert(
            assertion: $cleared && $Seen === [SIGUSR1] && $Run['expired'] === false
               && $released === true && $kept,
            description: 'destroy() puts both signals back and forgets the handlers, the loop traps again after it, and untrap() never undoes a handler installed after its own — observed: '
               . json_encode([$cleared, $Seen, $released, $kept])
         );

         // @@ K) Two loops of one process: the signal belongs to the one that
         //    holds it, a second trap is refused until it is released
         $Holder = $reactor();
         $Other = $reactor();
         $Loops[] = $Holder;
         $Loops[] = $Other;
         $Holder->trap(SIGUSR1, static function (): void {});
         $refusal = null;
         try {
            $Other->trap(SIGUSR1, static function (): void {});
         }
         catch (LogicException $Exception) {
            $refusal = $Exception->getMessage();
         }
         $untouched = $Handlers->getValue($Other) === []
            && array_keys($Handlers->getValue($Holder)) === [SIGUSR1];
         $Other->trap(SIGUSR2, static function (): void {});
         $Holder->untrap(SIGUSR1);
         $Other->trap(SIGUSR1, static function (): void {});
         $taken = array_keys($Handlers->getValue($Other)) === [SIGUSR2, SIGUSR1];
         $Other->destroy();
         $Holder->destroy();

         yield assert(
            assertion: $refusal !== null && $untouched && $taken
               && pcntl_signal_get_handler(SIGUSR1) === $Base1
               && pcntl_signal_get_handler(SIGUSR2) === $Base2,
            description: 'a signal held by one loop is refused to another with LogicException and granted once released — observed: '
               . json_encode([$refusal, $untouched, $taken])
         );

         // @@ L) The admission probe dispatches nothing: a refused add() runs
         //    no handler, and the signals stay queued for the pass
         $Select = $reactor();
         $Loops[] = $Select;
         $Seen = [];
         $Select->trap(SIGUSR1, static function (int $signal) use (&$Seen): void {
            $Seen[] = $signal;
         });
         $Closed = stream_socket_server('tcp://127.0.0.1:0');
         if ($Closed === false) {
            throw new RuntimeException('Could not create the probe socket.');
         }
         fclose($Closed);
         posix_kill($self, SIGUSR1);
         posix_kill($self, SIGUSR2);
         $added = $Select->add($Closed, Select::EVENT_READ, null);
         $inside = $Seen;
         pcntl_signal_dispatch();
         $Select->destroy();

         yield assert(
            assertion: $added === false && $inside === []
               && $Seen === [SIGUSR1, 'base:' . SIGUSR2]
               && $count('check', 'pcntl_signal_dispatch') === 0
               && $count('loop', 'pcntl_signal_dispatch') === 2,
            description: 'add() of a dead descriptor is refused without running a trapped or an out-of-band handler; check() holds no dispatch and loop() holds its two — observed: '
               . json_encode([$added, $inside, $Seen, $count('check', 'pcntl_signal_dispatch'), $count('loop', 'pcntl_signal_dispatch')])
         );

         // @@ M) A trap made before a fork survives into the child
         $Select = $reactor();
         $Loops[] = $Select;
         $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($pipe === false) {
            throw new RuntimeException('Could not create the report channel.');
         }
         $Sockets[] = $pipe;
         $block($Select);
         $Select->trap(SIGUSR1, static function (int $signal) use ($Select, $pipe): void {
            fwrite($pipe[1], "handled:{$signal}");
            $Select->loop = false;
         });
         $PID = pcntl_fork();
         if ($PID === 0) {
            posix_kill(posix_getpid(), SIGUSR1);
            $Run = $run($Select);
            fwrite($pipe[1], $Run['expired'] ? '|expired' : '|stopped');
            fclose($pipe[1]);
            // ! Hard exit — no shutdown handlers, no inherited output flush
            posix_kill(posix_getpid(), SIGKILL);
         }
         pcntl_waitpid((int) $PID, $status);
         stream_set_blocking($pipe[0], false);
         $report = (string) fread($pipe[0], 64);
         $Select->destroy();

         yield assert(
            assertion: $report === 'handled:' . SIGUSR1 . '|stopped',
            description: "a forked child's copy of the loop handles the signal with the handler trapped before the fork — observed: "
               . json_encode($report)
         );

         // @@ N) A signal still pending when it is released reaches the
         //    handler that takes the signal back, once
         $Select = $reactor();
         $Loops[] = $Select;
         $Seen = [];
         $Select->trap(SIGUSR1, static function () use (&$Seen): void {
            $Seen[] = 'loop';
         });
         posix_kill($self, SIGUSR1);
         $Select->untrap(SIGUSR1);
         pcntl_signal_dispatch();
         pcntl_signal_dispatch();

         yield assert(
            assertion: $Seen === ['base:' . SIGUSR1],
            description: 'a pending signal released before its dispatch runs the restored handler once and never the released one — observed: '
               . json_encode($Seen)
         );

         // @@ O) Process\Signals records what install() routed, once each —
         //    the list a worker takes over on its loop
         $Signals = new ReflectionClass(Signals::class)->newInstanceWithoutConstructor();
         $Seen = [];
         $Signals->handler = static function (int $signal) use (&$Seen): void {
            $Seen[] = $signal;
         };
         $empty = $Signals->installed;
         $Signals->install([SIGUSR1]);
         $Signals->install([SIGUSR1, SIGUSR2]);
         posix_kill($self, SIGUSR2);
         pcntl_signal_dispatch();
         $writable = true;
         try {
            $Signals->installed = []; // @phpstan-ignore-line
         }
         catch (Error) {
            $writable = false;
         }

         yield assert(
            assertion: $empty === [] && $Signals->installed === [SIGUSR1, SIGUSR2]
               && $Seen === [SIGUSR2] && $writable === false,
            description: 'Signals::$installed lists each installed signal once, in order, and is read-only — observed: '
               . json_encode([$empty, $Signals->installed, $Seen, $writable])
         );

         // @@ P) What a worker does, and an application on top of it: the
         //    loop takes over the installed signals; the application's own
         //    trap replaces the server's handler for that signal, and its
         //    release hands the signal back to the server; a real-time signal
         //    is trappable
         $Select = $reactor();
         $Loops[] = $Select;
         $block($Select);
         foreach ($Signals->installed as $signal) {
            $Select->trap($signal, $Signals->handler);
         }
         $Seen = [];
         $Own = [];
         $Select->trap(SIGUSR1, static function (int $signal) use (&$Own, $Select): void {
            $Own[] = $signal;
            $Select->loop = false;
         });
         posix_kill($self, SIGUSR1);
         $Run = $run($Select);
         $replaced = [$Seen, $Own];
         $released = $Select->untrap(SIGUSR1);
         posix_kill($self, SIGUSR1);
         pcntl_signal_dispatch();
         $returned = $Seen;
         // ? Real-time signals exist on Linux only
         $realtime = defined('SIGRTMIN') ? (int) constant('SIGRTMIN') : 0;
         $Seen = [];
         $Realtime = ['expired' => false];
         if ($realtime > 0) {
            $Select->trap($realtime, static function (int $signal) use (&$Seen, $Select): void {
               $Seen[] = $signal;
               $Select->loop = false;
            });
            posix_kill($self, $realtime);
            $Realtime = $run($Select);
            $Select->untrap($realtime);
         }

         yield assert(
            assertion: $replaced === [[], [SIGUSR1]] && $Run['expired'] === false
               && $released === true && $returned === [SIGUSR1]
               && $Seen === ($realtime > 0 ? [$realtime] : []) && $Realtime['expired'] === false,
            description: "an application's trap replaces the server's handler for the signal, untrap() hands the signal back to the server, and a real-time signal is trapped like any other — observed: "
               . json_encode([$replaced, $Run, $released, $returned, $Seen, $Realtime])
         );

         // @@ Q) Trapping again after other code replaced the trampoline takes
         //    the signal back, and what is put back then is that newer
         //    handler — never the one found the first time
         $Select->destroy();
         pcntl_signal(SIGUSR1, $Base1, false);
         pcntl_signal(SIGUSR2, $Base2, false);
         $Select = $reactor();
         $Loops[] = $Select;
         $block($Select);
         $Select->trap(SIGUSR1, static function (): void {});
         $Foreign = static function (): void {};
         pcntl_signal(SIGUSR1, $Foreign, false);
         $Seen = [];
         $Select->trap(SIGUSR1, static function (int $signal) use (&$Seen, $Select): void {
            $Seen[] = $signal;
            $Select->loop = false;
         });
         $retaken = pcntl_signal_get_handler(SIGUSR1) !== $Foreign;
         posix_kill($self, SIGUSR1);
         $Run = $run($Select);
         $Select->untrap(SIGUSR1);
         $returned = pcntl_signal_get_handler(SIGUSR1) === $Foreign;
         pcntl_signal(SIGUSR1, $Base1, false);

         yield assert(
            assertion: $retaken && $Seen === [SIGUSR1] && $Run['expired'] === false && $returned,
            description: 'a second trap reinstalls the trampoline over a handler installed meanwhile, and untrap() puts that handler back — observed: '
               . json_encode([$retaken, $Seen, $Run, $returned])
         );

         // @@ R) Other code saves the trampoline, the loop releases the
         //    signal, the other code restores what it saved: the dead
         //    trampoline runs nothing and hands the signal to its default
         //    action; a loop that traps over it again can still release it
         $Select->trap(SIGUSR1, static function (): void {});
         $Saved = pcntl_signal_get_handler(SIGUSR1);
         pcntl_signal(SIGUSR1, $Foreign, false);
         $Select->untrap(SIGUSR1);
         pcntl_signal(SIGUSR1, $Saved, false);
         $Seen = [];
         posix_kill($self, SIGUSR1);
         pcntl_signal_dispatch();
         $healed = pcntl_signal_get_handler(SIGUSR1) === SIG_DFL;
         pcntl_signal(SIGUSR1, $Saved, false);
         $Select->trap(SIGUSR1, static function (int $signal) use (&$Seen, $Select): void {
            $Seen[] = $signal;
            $Select->loop = false;
         });
         posix_kill($self, SIGUSR1);
         $Run = $run($Select);
         $thrown = null;
         try {
            $released = $Select->untrap(SIGUSR1);
            $Select->destroy();
         }
         catch (Throwable $Throwable) {
            $thrown = $Throwable::class;
         }
         $defaulted = pcntl_signal_get_handler(SIGUSR1) === SIG_DFL;
         pcntl_signal(SIGUSR1, $Base1, false);

         yield assert(
            assertion: $healed && $Seen === [SIGUSR1] && $Run['expired'] === false
               && $thrown === null && $released === true && $defaulted,
            description: 'a trampoline put back after its release drops the signal to the default action; trapped again, it is released without a failure — observed: '
               . json_encode([$healed, $Seen, $Run, $thrown, $defaulted])
         );

         // @@ S) An Error is a failure like any other: reported, and the
         //    loop goes on
         $Select = $reactor();
         $Loops[] = $Select;
         $block($Select);
         $Reported = [];
         Throwables::$reporters = [static function (Throwable $Throwable, array $context) use (&$Reported): void {
            $Reported[] = [$Throwable::class, $context];
         }];
         $Seen = [];
         $Select->trap(SIGUSR1, static function (): void {
            throw new TypeError('signal handler error');
         });
         $Select->trap(SIGUSR2, static function (int $signal) use (&$Seen, $Select): void {
            $Seen[] = $signal;
            $Select->loop = false;
         });
         posix_kill($self, SIGUSR1);
         posix_kill($self, SIGUSR2);
         $thrown = null;
         try {
            $Run = $run($Select);
         }
         catch (Throwable $Throwable) {
            $thrown = $Throwable::class;
         }
         Throwables::$reporters = $Reporters;
         $Select->destroy();

         yield assert(
            assertion: $thrown === null && $Seen === [SIGUSR2] && $Run['expired'] === false
               && $Reported === [[TypeError::class, ['origin' => 'loop.signal', 'signal' => SIGUSR1]]],
            description: 'an Error thrown by a handler is reported and the other signal still runs — observed: '
               . json_encode([$thrown, $Seen, $Reported])
         );

         // @@ T) Only a loop's trampoline makes it the holder: a handler that
         //    is merely a method of another loop is an ordinary handler
         $Other = $reactor();
         $Select = $reactor();
         $Loops[] = $Other;
         $Loops[] = $Select;
         $Method = $Other->untrap(...);
         pcntl_signal(SIGUSR1, $Method, false);
         $refusal = null;
         try {
            $Select->trap(SIGUSR1, static function (): void {});
         }
         catch (LogicException $Exception) {
            $refusal = $Exception->getMessage();
         }
         $Select->untrap(SIGUSR1);
         $returned = pcntl_signal_get_handler(SIGUSR1) === $Method;
         pcntl_signal(SIGUSR1, $Base1, false);

         yield assert(
            assertion: $refusal === null && $returned,
            description: 'a handler bound to another loop that is not its trampoline does not make that loop the holder — observed: '
               . json_encode([$refusal, $returned])
         );

         // @@ U) A loop that released the signal is not its holder, even
         //    while other code keeps that loop's trampoline installed
         $Dead = $reactor();
         $Select = $reactor();
         $Loops[] = $Dead;
         $Loops[] = $Select;
         $Dead->trap(SIGUSR1, static function (): void {});
         $Saved = pcntl_signal_get_handler(SIGUSR1);
         $Dead->untrap(SIGUSR1);
         pcntl_signal(SIGUSR1, $Saved, false);
         $refusal = null;
         $Seen = [];
         try {
            $Select->trap(SIGUSR1, static function (int $signal) use (&$Seen): void {
               $Seen[] = $signal;
            });
         }
         catch (LogicException $Exception) {
            $refusal = $Exception->getMessage();
         }
         posix_kill($self, SIGUSR1);
         pcntl_signal_dispatch();
         $released = $Select->untrap(SIGUSR1);
         $after = pcntl_signal_get_handler(SIGUSR1);
         pcntl_signal(SIGUSR1, $Base1, false);

         yield assert(
            assertion: $refusal === null && $Seen === [SIGUSR1]
               && $released && $after === SIG_DFL,
            description: 'a signal whose installed handler is the trampoline of a loop that released it is granted, and released to the default action — observed: '
               . json_encode([$refusal, $Seen, $released, $after === SIG_DFL])
         );

         // @@ V) A handler this loop could not put back — a callable only
         //    its own class may call — is refused before anything changes,
         //    so no release ever fails on it
         $Owner = new class {
            // Only the class itself may install its private method
            public function install (int $signal): void
            {
               pcntl_signal($signal, [$this, 'handle'], false);
            }
            private function handle (int $signal): void {}
         };
         $Owner->install(SIGUSR1);
         $Private = pcntl_signal_get_handler(SIGUSR1);
         $Select = $reactor();
         $Loops[] = $Select;
         $refusal = null;
         try {
            $Select->trap(SIGUSR1, static function (): void {});
         }
         catch (LogicException $Exception) {
            $refusal = $Exception->getMessage();
         }
         $kept = pcntl_signal_get_handler(SIGUSR1) === $Private
            && $Handlers->getValue($Select) === [];
         $failure = null;
         try {
            $Select->destroy();
         }
         catch (Throwable $Throwable) {
            $failure = $Throwable->getMessage();
         }
         pcntl_signal(SIGUSR1, $Base1, false);

         yield assert(
            assertion: $refusal !== null && $kept && $failure === null,
            description: 'a handler that only its own class may call is refused with LogicException, left installed, and destroy() does not fail — observed: '
               . json_encode([$refusal, $kept, $failure])
         );

         // @@ W) Order inside trap() and untrap(), pinned by token: the
         //    handler is stored before the trampoline is installed, and the
         //    disposition goes back before the handler is dropped
         $order = static function (string $method) use ($Reflection): array {
            $Method = $Reflection->getMethod($method);
            $lines = array_slice(
               file((string) $Method->getFileName()),
               $Method->getStartLine() - 1,
               $Method->getEndLine() - $Method->getStartLine() + 1
            );
            $marks = [];
            $tokens = token_get_all('<?php ' . implode('', $lines));
            foreach ($tokens as $index => $token) {
               if (is_array($token) === false) {
                  continue;
               }
               if ($token[0] === T_STRING && $token[1] === 'pcntl_signal') {
                  $marks[] = 'install';
               }
               else if ($token[0] === T_UNSET) {
                  $marks[] = 'drop';
               }
               else if (
                  $token[0] === T_STRING && $token[1] === 'Handlers'
                  && ($tokens[$index + 4] ?? null) !== null
               ) {
                  // ? `Handlers[$signal] =` — a store, not a read
                  $next = [];
                  for ($ahead = $index + 1; $ahead < $index + 8 && isset($tokens[$ahead]); $ahead++) {
                     $piece = $tokens[$ahead];
                     if (is_array($piece) && $piece[0] === T_WHITESPACE) {
                        continue;
                     }
                     $next[] = is_array($piece) ? $piece[1] : $piece;
                  }
                  if (array_slice($next, 0, 4) === ['[', '$signal', ']', '=']) {
                     $marks[] = 'store';
                  }
               }
            }

            return $marks;
         };
         $trapped = $order('trap');
         $untrapped = $order('untrap');

         yield assert(
            assertion: $trapped === ['store', 'install', 'store']
               && $untrapped === ['install', 'drop'],
            description: 'trap() stores the handler before it installs the trampoline and untrap() restores before it drops — observed: '
               . json_encode([$trapped, $untrapped])
         );
      }
      finally {
         Throwables::$reporters = $Reporters;
         foreach ($Loops as $Loop) {
            $Loop->destroy();
         }
         // ! Nothing pending may meet a restored default action
         pcntl_signal(SIGUSR1, $Base1, false);
         pcntl_signal(SIGUSR2, $Base2, false);
         pcntl_signal_dispatch();
         foreach ($Found as $signal => $disposition) {
            pcntl_signal($signal, $disposition, false);
         }
         pcntl_async_signals($async);
         foreach ($Sockets as $pair) {
            foreach ($pair as $Socket) {
               if (is_resource($Socket)) {
                  fclose($Socket);
               }
            }
         }
      }
   })
);
