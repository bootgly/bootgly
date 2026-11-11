<?php

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Repeating;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events\Select;


/**
 * `Repeating::repeat()` on the Select reactor: recurring callbacks on a fixed
 * monotonic grid, in the id space of `defer()`, stopped with `cancel()`.
 *
 * The private `tick()` is what `loop()` calls on every iteration; sections A–G
 * and J–K drive it by hand against the real clock, H–I run the real `loop()`.
 */
return new Test(
   description: 'Select::repeat() should run a callback on a fixed monotonic grid, skip missed periods, survive a throwing callback and stop on cancel()',
   test: new Assertions(Case: function (): Generator {
      $Reflection = new ReflectionClass(Select::class);
      $tick = $Reflection->getMethod('tick');
      $Repeaters = $Reflection->getProperty('Repeaters');
      $Schedule = $Reflection->getProperty('Schedule');
      $Stale = $Reflection->getProperty('stale');
      $Connections = new class implements Connections {
         public function connect (): bool
         {
            return true;
         }
      };
      $reactor = static function () use (&$Connections): Select {
         return new Select($Connections);
      };
      // ! A sleep a signal cannot cut short
      $wait = static function (int $until): void {
         while (($left = $until - (int) hrtime(true)) > 0) {
            usleep((int) max(1, min(20_000, $left / 1_000)));
         }
      };
      $MS = 1_000_000;
      $Reporters = Throwables::$reporters;
      $Sockets = [];

      try {
         // @@ A) The capability is optional: Scheduler and Loops gained nothing
         $names = static fn (string $interface): array => array_map(
            static fn (ReflectionMethod $Method): string => $Method->getName(),
            new ReflectionClass($interface)->getMethods()
         );
         $Legacy = new class implements Loops, Scheduler {
            public function add ($Socket, int $flag, $payload): bool { return true; }
            public function del ($Socket, int $flag): bool { return true; }
            public function loop (): void {}
            public function destroy (): void {}
            public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool { return false; }
            public function defer (float|int $deadline, Closure $Callback): int { return 0; }
            public function cancel (int $ID): bool { return false; }
            public function interrupt (Fiber $Fiber, Throwable $Throwable): bool { return false; }
         };

         yield assert(
            assertion: $names(Scheduler::class) === ['schedule', 'defer', 'cancel', 'interrupt']
               && $names(Loops::class) === ['add', 'del', 'loop', 'destroy']
               && $Legacy instanceof Repeating === false
               && $reactor() instanceof Repeating
               && is_subclass_of(Repeating::class, Scheduler::class),
            description: 'Scheduler and Loops keep their methods, a loop written against them stays valid and is not Repeating, Select is — observed: '
               . json_encode([$names(Scheduler::class), $names(Loops::class), $Legacy instanceof Repeating])
         );

         // @@ B) Refused intervals, the resolution floor, the shared id space
         $Select = $reactor();
         $refused = [];
         foreach ([0.0, -1.0, NAN, INF, -INF, Select::HORIZON * 2] as $interval) {
            try {
               $Select->repeat($interval, static function (): void {});
               $refused[] = false;
            }
            catch (InvalidArgumentException) {
               $refused[] = true;
            }
         }
         $deferred = $Select->defer(microtime(true) + 60.0, static function (): void {});
         $floor = $Select->repeat(0.000_000_001, static function (): void {});
         $horizon = $Select->repeat(Select::HORIZON, static function (): void {});
         $monotonic = $Select->defer((int) hrtime(true) + 60_000_000_000, static function (): void {});
         $Set = $Repeaters->getValue($Select);

         yield assert(
            assertion: $refused === [true, true, true, true, true, true]
               && count($Set) === 2
               && [$floor, $horizon, $monotonic] === [$deferred + 1, $deferred + 2, $deferred + 3]
               && $Set[$floor]['interval'] === (int) (Select::RESOLUTION * 1_000_000_000)
               && $Set[$horizon]['interval'] === (int) (Select::HORIZON * 1_000_000_000),
            description: 'zero, negative, NAN, ±INF and past-the-horizon intervals are refused and register nothing; a shorter-than-resolution one is raised to it; ids continue the defer() sequence — observed: '
               . json_encode(['refused' => $refused, 'ids' => [$deferred, $floor, $horizon, $monotonic], 'floor' => $Set[$floor]['interval'] ?? null])
         );

         $cancelled = [$Select->cancel($floor), $Select->cancel($floor), $Select->cancel($horizon), $Select->cancel(999_999)];

         yield assert(
            assertion: $cancelled === [true, false, true, false] && $Repeaters->getValue($Select) === [],
            description: 'cancel() stops a recurring timer once, and answers false for one already stopped or unknown — observed: ' . json_encode($cancelled)
         );

         // @@ C) The grid: not before the interval, once per period, no drift
         $Select = $reactor();
         $idle = $tick->invoke($Select);
         $runs = 0;
         $before = (int) hrtime(true);
         $ID = $Select->repeat(0.05, static function () use (&$runs): void {
            $runs++;
         });
         $after = (int) hrtime(true);
         $first = $Repeaters->getValue($Select)[$ID]['deadline'];
         $bound = $tick->invoke($Select);
         $early = $runs;

         $wait($first + 2 * $MS);
         $tick->invoke($Select);
         $once = $runs;
         $second = $Repeaters->getValue($Select)[$ID]['deadline'];
         $tick->invoke($Select);
         $same = $runs;

         yield assert(
            assertion: $idle === null
               && $first >= $before + 50 * $MS && $first <= $after + 50 * $MS
               && $bound !== null && $bound > 0.0 && $bound <= 0.05
               && $early === 0 && $once === 1 && $same === 1
               && $second === $first + 50 * $MS,
            description: 'no timer means no deadline; the first run is one interval after registration and bounds the selector wait; a due callback runs once per period and its next deadline is exactly one interval after the previous one — observed: '
               . json_encode(['idle' => $idle, 'bound' => $bound, 'runs' => [$early, $once, $same], 'step' => $second - $first])
         );

         // @@ C') A shorter timer registered after a longer one becomes the nearest
         $Far = $reactor();
         $Far->repeat(30.0, static function (): void {});
         $far = $tick->invoke($Far);
         $near = 0;
         $nearID = $Far->repeat(0.02, static function () use (&$near): void {
            $near++;
         });
         $bound = $tick->invoke($Far);
         $wait($Repeaters->getValue($Far)[$nearID]['deadline'] + $MS);
         $tick->invoke($Far);
         // @ ...and once it is cancelled the far one bounds the wait again
         $Far->cancel($nearID);
         $tick->invoke($Far);
         $rest = $tick->invoke($Far);

         yield assert(
            assertion: $far !== null && $far > 29.0 && $far <= 30.0
               && $bound !== null && $bound <= 0.02
               && $near === 1
               && $rest !== null && $rest > 29.0,
            description: 'the selector wait follows the nearest recurring deadline as timers come and go — observed: '
               . json_encode(['far' => $far, 'bound' => $bound, 'runs' => $near, 'rest' => $rest])
         );

         // @@ D) Missed periods are skipped, never replayed
         $wait($second + 3 * 50 * $MS + 10 * $MS);
         $now = (int) hrtime(true);
         $tick->invoke($Select);
         $late = $runs;
         $third = $Repeaters->getValue($Select)[$ID]['deadline'];
         $tick->invoke($Select);
         $burst = $runs;

         yield assert(
            assertion: $late === 2 && $burst === 2
               && $third > $now
               && $third - $now <= 50 * $MS
               && ($third - $first) % (50 * $MS) === 0,
            description: 'a run reached more than three periods late runs once, the missed ones are dropped, and the next deadline is the first point of the original grid after it — observed: '
               . json_encode(['runs' => [$late, $burst], 'ahead_ms' => ($third - $now) / $MS, 'offgrid_ns' => ($third - $first) % (50 * $MS)])
         );
         $Select->cancel($ID);

         // @@ E) A callback that throws is reported and keeps its schedule
         $Select = $reactor();
         $Reported = [];
         Throwables::$reporters = [static function (Throwable $Throwable, array $context) use (&$Reported): void {
            $Reported[] = [$Throwable->getMessage(), $context];
         }];
         $throws = 0;
         $ID = $Select->repeat(0.02, static function () use (&$throws): void {
            $throws++;
            throw new RuntimeException("boom {$throws}");
         });
         $escaped = false;
         try {
            $wait($Repeaters->getValue($Select)[$ID]['deadline'] + $MS);
            $tick->invoke($Select);
            $wait($Repeaters->getValue($Select)[$ID]['deadline'] + $MS);
            $tick->invoke($Select);
         }
         catch (Throwable) {
            $escaped = true;
         }
         // ! A reporter that itself fails must not escape either
         Throwables::$reporters = [static function (): void {
            throw new LogicException('reporter');
         }];
         try {
            $wait($Repeaters->getValue($Select)[$ID]['deadline'] + $MS);
            $tick->invoke($Select);
         }
         catch (Throwable) {
            $escaped = true;
         }
         Throwables::$reporters = $Reporters;

         yield assert(
            assertion: $escaped === false && $throws === 3
               && $Reported === [
                  ['boom 1', ['origin' => 'loop.repeat', 'id' => $ID]],
                  ['boom 2', ['origin' => 'loop.repeat', 'id' => $ID]],
               ]
               && isset($Repeaters->getValue($Select)[$ID]),
            description: 'a throwing callback never escapes the reactor, is reported with its origin and id, and runs again on its next period — observed: '
               . json_encode(['escaped' => $escaped, 'throws' => $throws, 'reported' => $Reported])
         );
         $Select->cancel($ID);

         // @@ F) Cancelling and registering inside a callback
         $Select = $reactor();
         $Log = [];
         $IDs = [];
         $IDs['self'] = $Select->repeat(0.02, static function () use (&$Log, &$IDs, $Select): void {
            $Log[] = 'self';
            $Select->cancel($IDs['self']);
         });
         $IDs['killer'] = $Select->repeat(0.02, static function () use (&$Log, &$IDs, $Select): void {
            $Log[] = 'killer';
            // @ One that this pass has not reached yet, and one it already ran
            $Select->cancel($IDs['victim']);
            $Select->cancel($IDs['self']);
            $IDs['born'] ??= $Select->repeat(0.02, static function () use (&$Log): void {
               $Log[] = 'born';
            });
         });
         $IDs['victim'] = $Select->repeat(0.02, static function () use (&$Log): void {
            $Log[] = 'victim';
         });
         $IDs['bystander'] = $Select->repeat(0.02, static function () use (&$Log): void {
            $Log[] = 'bystander';
         });
         $wait($Repeaters->getValue($Select)[$IDs['bystander']]['deadline'] + $MS);
         $tick->invoke($Select);
         $pass = $Log;
         $alive = array_keys($Repeaters->getValue($Select));
         $wait($Repeaters->getValue($Select)[$IDs['born']]['deadline'] + $MS);
         $bound = $tick->invoke($Select);
         $next = array_slice($Log, count($pass));

         yield assert(
            assertion: $pass === ['self', 'killer', 'bystander']
               && $alive === [$IDs['killer'], $IDs['bystander'], $IDs['born']]
               && $next === ['killer', 'bystander', 'born']
               && $bound !== null && $bound <= 0.02,
            description: 'a callback may cancel itself and others: one cancelled before its turn never runs in that pass, and one registered inside a callback first runs on a later pass — observed: '
               . json_encode(['pass' => $pass, 'next' => $next, 'alive' => $alive])
         );

         // @@ F') The wait handed to the selector is measured after the callbacks ran
         $Select = $reactor();
         $Select->repeat(30.0, static function (): void {});
         $slowID = $Select->repeat(0.02, static function () use ($wait, $MS): void {
            // ! A callback that takes 15 ms of its 20 ms period
            $wait((int) hrtime(true) + 15 * $MS);
         });
         $wait($Repeaters->getValue($Select)[$slowID]['deadline'] + $MS);
         $bound = $tick->invoke($Select);
         $remaining = ($Repeaters->getValue($Select)[$slowID]['deadline'] - (int) hrtime(true)) / 1_000_000_000;
         // @ ...and a callback that stops its own timer leaves nothing to wait for
         $selfID = 0;
         $selfID = $Select->repeat(0.001, static function () use (&$selfID, $Select): void {
            $Select->cancel($selfID);
         });
         $Select->cancel($slowID);
         $wait($Repeaters->getValue($Select)[$selfID]['deadline'] + $MS);
         $tick->invoke($Select);
         $rest = $tick->invoke($Select);

         yield assert(
            assertion: $bound !== null && $bound >= 0.0 && $bound - max(0.0, $remaining) < 0.005
               && $remaining < 0.010
               && $rest !== null && $rest > 29.0
               && count($Repeaters->getValue($Select)) === 1,
            description: 'the wait is the time left after a slow callback returned, not before it ran, and a timer that stopped itself bounds nothing — observed: '
               . json_encode(['bound' => $bound, 'remaining' => $remaining, 'rest' => $rest])
         );

         // @@ G) destroy() drops every recurring timer, also from a callback
         $Select = $reactor();
         $Log = [];
         $Select->repeat(0.02, static function () use (&$Log, $Select): void {
            $Log[] = 'destroyer';
            $Select->destroy();
         });
         $orphan = $Select->repeat(0.02, static function () use (&$Log): void {
            $Log[] = 'orphan';
         });
         $wait($Repeaters->getValue($Select)[$orphan]['deadline'] + $MS);
         $bound = $tick->invoke($Select);

         yield assert(
            assertion: $Log === ['destroyer']
               && $Repeaters->getValue($Select) === []
               && count($Schedule->getValue($Select)) === 0 && $Stale->getValue($Select) === 0
               && $bound === null
               && $Select->cancel($orphan) === false
               && $tick->invoke($Select) === null,
            description: 'destroy() leaves no recurring timer behind — the rest of the pass is skipped and a reused reactor starts with no deadline — observed: '
               . json_encode(['log' => $Log, 'bound' => $bound])
         );

         // @@ H) The real loop with no descriptor: the timer wakes the idle sleep
         $Select = $reactor();
         $Stamps = [];
         $started = (int) hrtime(true);
         $Select->repeat(0.02, static function () use (&$Stamps, $Select): void {
            $Stamps[] = (int) hrtime(true);
            if (count($Stamps) === 5) {
               $Select->loop = false;
            }
         });
         $Select->loop();
         $elapsed = ((int) hrtime(true) - $started) / $MS;
         $Select->destroy();

         yield assert(
            assertion: count($Stamps) === 5 && $elapsed >= 100.0 && $elapsed < 900.0,
            description: 'an idle loop with no descriptor runs a 20 ms timer five times in about 100 ms, not on its one-second idle sleep — observed: '
               . json_encode(['runs' => count($Stamps), 'elapsed_ms' => $elapsed])
         );

         // @@ I) The real loop blocked in the selector: the timer bounds the wait
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($pair === false) {
            throw new RuntimeException('Could not create the socket pair.');
         }
         $Sockets[] = $pair;
         $Select = $reactor();
         $Select->loop = true;
         // ! A registered descriptor that never becomes readable: without a
         //   deadline the selector would block on it forever
         $Select->add($pair[0], Select::EVENT_READ, null);
         $Stamps = [];
         $started = (int) hrtime(true);
         $ID = $Select->repeat(0.02, static function () use (&$Stamps, $Select): void {
            $Stamps[] = (int) hrtime(true);
            if (count($Stamps) === 5) {
               $Select->loop = false;
            }
         });
         $due = $Repeaters->getValue($Select)[$ID]['deadline'];
         $Select->loop();
         $elapsed = ((int) hrtime(true) - $started) / $MS;
         $Gaps = [];
         // ! One run per period: run k never comes before grid point k — a
         //   late wakeup may shorten a gap, never pull a run ahead of its point
         $ahead = 0;
         foreach ($Stamps as $index => $stamp) {
            if ($index > 0) {
               $Gaps[] = ($stamp - $Stamps[$index - 1]) / $MS;
            }
            if ($stamp < $due + $index * 20 * $MS) {
               $ahead++;
            }
         }
         $Select->destroy();

         yield assert(
            assertion: count($Stamps) === 5 && $elapsed >= 100.0 && $elapsed < 900.0
               && $ahead === 0,
            description: 'a loop blocked in stream_select() on an idle descriptor wakes for a 20 ms timer five times in about 100 ms, one run per period — observed: '
               . json_encode(['runs' => count($Stamps), 'elapsed_ms' => $elapsed, 'gaps_ms' => $Gaps])
         );

         // @@ J) A pass costs what is due, not what is waiting
         // ! 3000 timers whose first runs are spread over 300 ms, as streams
         //   opened at different instants are: about one wakeup per timer.
         //   A pass that walked every timer would burn the whole window —
         //   millions of visits; one that pops only the due ones needs a few
         //   milliseconds of CPU.
         $Select = $reactor();
         $runs = 0;
         $Order = [];
         for ($index = 0; $index < 3_000; $index++) {
            $Select->repeat(0.2 + $index * 0.000_1, static function () use (&$runs, &$Order, $index): void {
               // ! Order of the first runs only: an early timer is due again
               //   before a late one first runs
               static $first = true;
               $runs++;
               if ($first) {
                  $first = false;
                  $Order[] = $index;
               }
            });
         }
         $Usage = getrusage();
         $started = (int) hrtime(true);
         // ! Until the last timer first ran — about 500 ms — capped at 2 s
         while (count($Order) < 3_000 && (int) hrtime(true) - $started < 2_000 * $MS) {
            $pause = $tick->invoke($Select);
            if ($pause !== null && $pause > 0.0) {
               usleep((int) min(2_000, $pause * 1_000_000));
            }
         }
         $Spent = getrusage();
         $CPU = ($Spent['ru_utime.tv_sec'] - $Usage['ru_utime.tv_sec'])
            + ($Spent['ru_utime.tv_usec'] - $Usage['ru_utime.tv_usec']) / 1_000_000
            + ($Spent['ru_stime.tv_sec'] - $Usage['ru_stime.tv_sec'])
            + ($Spent['ru_stime.tv_usec'] - $Usage['ru_stime.tv_usec']) / 1_000_000;
         $Select->destroy();

         yield assert(
            assertion: $runs >= 3_000 && $Order === range(0, 2_999) && $CPU < 0.25,
            description: '3000 timers with staggered deadlines each run, in deadline order, for a CPU cost that follows the runs — observed: '
               . json_encode(['runs' => $runs, 'ordered' => $Order === range(0, 2_999), 'cpu_s' => round($CPU, 3)])
         );

         // @@ K) Stopped timers leave nothing behind that grows or wakes the reactor
         $Select = $reactor();
         $IDs = [];
         $Ran = [];
         for ($index = 0; $index < 200; $index++) {
            $IDs[$index] = $Select->repeat(0.5 + $index * 0.000_01, static function () use (&$Ran, $index): void {
               $Ran[] = $index;
            });
         }
         // @ Stop three of every four: the heap sheds their entries once they
         //   outnumber the timers alive
         $Sizes = [];
         foreach ($IDs as $index => $ID) {
            if ($index % 4 !== 0) {
               $Select->cancel($ID);
               $Sizes[] = count($Schedule->getValue($Select));
            }
         }
         $alive = count($Repeaters->getValue($Select));
         $kept = count($Schedule->getValue($Select));
         $counted = $Stale->getValue($Select);
         // @ Nothing is due yet — a rebuilt heap kept every deadline
         $tick->invoke($Select);
         $early = count($Ran);
         // @ Every deadline passes: the leftovers surface and are dropped
         $wait(max(array_column($Repeaters->getValue($Select), 'deadline')) + 1 * $MS);
         $tick->invoke($Select);
         $settled = [count($Schedule->getValue($Select)), $Stale->getValue($Select)];
         // @ Stop the rest: the last timer takes the heap with it
         foreach ($IDs as $index => $ID) {
            if ($index % 4 === 0) {
               $Select->cancel($ID);
            }
         }

         yield assert(
            // ? 200 entries until the leftovers (101) outnumber the timers
            //   alive (99): rebuilt to 99, then 49 more cancels leave 49 behind
            assertion: $alive === 50 && max($Sizes) === 200 && $kept === 99 && $counted === 49
               && $early === 0 && $settled === [50, 0]
               && $Ran === range(0, 196, 4)
               && count($Schedule->getValue($Select)) === 0 && $Stale->getValue($Select) === 0
               && $tick->invoke($Select) === null,
            description: 'cancelling 150 of 200 timers rebuilds the heap once the leftovers outnumber the timers alive and counts the rest; the leftovers are dropped as they surface; the 50 survivors run once each, on time and in deadline order; the last cancel leaves an empty heap with no deadline — observed: '
               . json_encode(['alive' => $alive, 'heap_peak' => max($Sizes), 'heap_after_cancels' => $kept, 'counted' => $counted, 'early' => $early, 'settled' => $settled, 'ran' => count($Ran), 'heap_after_all' => count($Schedule->getValue($Select))])
         );

         // @ A stopped timer that was the nearest is dropped by the next pass,
         //   which waits for the one behind it
         $Select = $reactor();
         $near = $Select->repeat(0.05, static function (): void {});
         $next = $Select->repeat(0.06, static function (): void {});
         $far = $Select->repeat(0.5, static function (): void {});
         $Select->cancel($near);
         $Select->cancel($next);
         // ! A handful of leftovers is not worth a rebuild, even when they
         //   outnumber the timers alive
         $before = [count($Schedule->getValue($Select)), $Stale->getValue($Select)];
         $pause = $tick->invoke($Select);
         $after = [count($Schedule->getValue($Select)), $Stale->getValue($Select)];
         $Select->destroy();

         yield assert(
            assertion: $before === [3, 2]
               && $pause !== null && $pause > 0.3 && $pause <= 0.5 && $after === [1, 0],
            description: 'two stopped timers ahead of the one alive stay in the heap until the next pass, which drops them and waits for the timer alive — observed: '
               . json_encode(['before' => $before, 'wait' => $pause, 'after' => $after])
         );

         // @ destroy() forgets the leftovers with the timers
         $Select = $reactor();
         $near = $Select->repeat(0.05, static function (): void {});
         $next = $Select->repeat(0.06, static function (): void {});
         $Select->repeat(0.5, static function (): void {});
         $Select->cancel($near);
         $Select->cancel($next);
         $held = [count($Schedule->getValue($Select)), $Stale->getValue($Select)];
         $Select->destroy();
         $dropped = [count($Repeaters->getValue($Select)), count($Schedule->getValue($Select)), $Stale->getValue($Select)];

         yield assert(
            assertion: $held === [3, 2] && $dropped === [0, 0, 0],
            description: 'destroy() with stopped timers still in the heap leaves no timer, no heap entry and no leftover count — observed: '
               . json_encode(['held' => $held, 'dropped' => $dropped])
         );
      }
      finally {
         Throwables::$reporters = $Reporters;
         foreach ($Sockets as $pair) {
            fclose($pair[0]);
            fclose($pair[1]);
         }
      }
   })
);
