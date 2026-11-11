<?php

use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections as Dialing;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;


/**
 * The SSE supervisor and its `Tick` interval on both timer backends.
 *
 * On a loop that owns recurring timers (`Repeating` — the built-in Select) the
 * `Tick` runs on a loop timer of its own, at exactly its interval: it may be a
 * fraction of a second, it follows the loop's fixed grid on the monotonic clock
 * and its id never joins the connection's static-Timer ids. A supervisor timer
 * joins it only when the heartbeat or the lease renewal must outpace the Tick.
 * On a loop without recurring timers both share one static `Timer`, in whole
 * seconds, and a fractional interval is rounded up.
 */
return new Test(
   description: 'SSE should tick at a sub-second interval on a loop with recurring timers and fall back to whole seconds on one without',
   test: function (): Generator {
      $Sockets = [];
      $Streams = [];
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      // ! The static Timer arms a real alarm on the fallback path: an
      //   unhandled SIGALRM would kill this process
      $OldHandler = pcntl_signal_get_handler(SIGALRM);
      $oldAlarm = pcntl_alarm(0);
      pcntl_signal(SIGALRM, static function (): void {});

      $Ticker = new ReflectionMethod(Select::class, 'tick');
      $Repeaters = new ReflectionProperty(Select::class, 'Repeaters');
      $Tasks = new ReflectionProperty(Timer::class, 'tasks');
      $Ticked = new ReflectionProperty(SSE::class, 'ticked');
      $Wrote = new ReflectionProperty(SSE::class, 'wrote');
      $MS = 1_000_000;
      // ! A sleep a signal cannot cut short
      $wait = static function (int $until): void {
         while (($left = $until - (int) hrtime(true)) > 0) {
            usleep((int) max(1, min(20_000, $left / 1_000)));
         }
      };
      $Dialing = new class implements Dialing {
         public function connect (): bool
         {
            return true;
         }
      };
      $Legacy = new class implements Events, Loops, Scheduler {
         public function add ($Socket, int $flag, $payload): bool { return true; }
         public function del ($Socket, int $flag): bool { return true; }
         public function loop (): void {}
         public function destroy (): void {}
         public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool { return false; }
         public function defer (float|int $deadline, Closure $Callback): int { return 0; }
         public function cancel (int $ID): bool { return false; }
         public function interrupt (Fiber $Fiber, Throwable $Throwable): bool { return false; }
      };
      // @ One stream on a double connection: $options = heartbeat, expiration
      $open = static function (array $arguments, array $options = []) use (&$Sockets, &$Streams): object {
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($pair === false) {
            throw new RuntimeException('Could not create the socket pair.');
         }
         $Sockets[] = $pair;

         $Connection = new class($pair[0], '127.0.0.1', 1) extends Connection {
            public int $writes = 0;

            public function writing (&$Socket, null|int $length = null, string $buffer = ''): bool
            {
               $this->writes++;

               return true;
            }

            // ! No event loop serves this double — detach from `Server::$Event`
            public function close (): true
            {
               $this->status = Connections::STATUS_CLOSED;

               return true;
            }
         };
         $Connection->status = Connections::STATUS_ESTABLISHED;
         $Connection->expiration = $options['expiration'] ?? 0;

         $Probe = new class {
            public int $ticks = 0;
            public null|SSE $SSE = null;
            public null|Connection $Connection = null;
         };
         $SSE = new SSE(new Response);
         $SSE->heartbeat = $options['heartbeat'] ?? 0;
         $SSE->bind($Connection, null);
         $Tick = ($options['tick'] ?? true)
            ? static function () use ($Probe): void {
               $Probe->ticks++;
            }
            : null;
         $SSE->open($Tick, ...$arguments);
         $Probe->SSE = $SSE;
         $Probe->Connection = $Connection;
         $Streams[] = $SSE;

         return $Probe;
      };
      // @ The intervals a stream registered on the loop, in seconds and in
      //   registration order: the Tick's own timer, then the supervisor's
      $cadence = static function (Select $Select) use ($Repeaters): array {
         $Intervals = [];
         foreach ($Repeaters->getValue($Select) as $Repeater) {
            $Intervals[] = (float) ($Repeater['interval'] / 1_000_000_000);
         }

         return $Intervals;
      };
      $count = static function () use ($Tasks): int {
         $total = 0;
         foreach ($Tasks->getValue() as $Bucket) {
            $total += count($Bucket);
         }

         return $total;
      };

      try {
         TCP_Server_CLI::$context = [];
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         Timer::del();

         // ---
         // @@ A) Loop with recurring timers: a 100 ms Tick
         $Select = new Select($Dialing);
         TCP_Server_CLI::$Event = $Select;

         $Probe = $open(['interval' => 0.1]);
         $Set = $Repeaters->getValue($Select);
         $ID = array_key_first($Set);
         $first = $Set[$ID]['deadline'] ?? 0;

         yield assert(
            assertion: $Probe->SSE->opened && count($Set) === 1
               && $Set[$ID]['interval'] === 100 * $MS
               && $Probe->Connection->timers === []
               && $count() === 0,
            description: 'the Tick is one recurring timer of the loop at its interval, supervising too; nothing is armed on the static Timer nor listed in the connection timer ids — observed: '
               . json_encode(['repeaters' => count($Set), 'interval_ms' => ($Set[$ID]['interval'] ?? 0) / $MS, 'connection' => $Probe->Connection->timers, 'tasks' => $count()])
         );

         // @ Drive the reactor up to the fifth run: due 400 ms after the
         //   first, never earlier — and long before a whole-second Tick would
         //   get there. A pass runs a timer once, so the count stops at five.
         // ! No other timer runs: the Tick's own renews the connection lease
         $Probe->Connection->used = 0;
         $cap = $first + 1_500 * $MS;
         while ($Probe->ticks < 5 && (int) hrtime(true) < $cap) {
            $Ticker->invoke($Select);
            usleep(2_000);
         }
         $elapsed = (int) hrtime(true) - $first;
         $ticks = $Probe->ticks;
         $renewed = $Probe->Connection->used;

         yield assert(
            assertion: $ticks === 5 && $elapsed >= 400 * $MS && $renewed > 0,
            description: 'a 100 ms Tick reaches its fifth run 400 ms after the first, not before, and its runs renew the connection lease — observed: '
               . json_encode(['ticks' => $ticks, 'elapsed_ms' => $elapsed / $MS, 'renewed' => $renewed > 0])
         );

         // @@ B) A late run never swallows the next period, a stall never replays
         // ! 30 ms late, then on time: only 70 ms separate the two runs, and
         //   both are due — the Tick keeps its own grid
         $next = $Repeaters->getValue($Select)[$ID]['deadline'];
         $wait($next + 30 * $MS);
         $Ticker->invoke($Select);
         $wait($next + 100 * $MS + 1 * $MS);
         $Ticker->invoke($Select);
         $jitter = $Probe->ticks - $ticks;
         // ! Stalled for more than three periods: one run, then nothing extra
         //   — neither from the loop nor from a supervision by hand, which
         //   never runs the Tick while the loop owns it
         $wait((int) hrtime(true) + 350 * $MS);
         $Ticker->invoke($Select);
         $Ticker->invoke($Select);
         // ! ...even one whose whole-second stamp says an interval elapsed
         $Ticked->setValue($Probe->SSE, 0);
         $Probe->SSE->supervise();
         $stall = $Probe->ticks - $ticks - $jitter;
         // ! A run 150 ms late — more than a period — and then the loop's
         //   next grid point, 50 ms after it: the Tick follows the loop's
         //   grid, so that point is a Tick too
         $next = $Repeaters->getValue($Select)[$ID]['deadline'];
         $wait($next + 150 * $MS);
         $Ticker->invoke($Select);
         $regrid = $Repeaters->getValue($Select)[$ID]['deadline'] - $next;
         $wait($next + 200 * $MS + 1 * $MS);
         $Ticker->invoke($Select);
         $late = $Probe->ticks - $ticks - $jitter - $stall;

         yield assert(
            assertion: $jitter === 2 && $stall === 1 && $regrid === 200 * $MS && $late === 2,
            description: 'a run 30 ms late is followed by the next one on time; a stall of three periods yields one Tick, not a burst; after a run more than a period late the next grid point still ticks — observed: '
               . json_encode(['after_jitter' => $jitter, 'after_stall' => $stall, 'regrid_ms' => $regrid / $MS, 'after_late' => $late])
         );

         // @ Teardown stops the loop timer
         $Probe->SSE->close();
         $ticks = $Probe->ticks;
         $wait((int) hrtime(true) + 150 * $MS);
         $Ticker->invoke($Select);

         yield assert(
            assertion: $Repeaters->getValue($Select) === [] && $Probe->ticks === $ticks
               && $Ticker->invoke($Select) === null,
            description: 'close() cancels the recurring timer: the loop holds none and has no deadline left — observed: '
               . json_encode(['repeaters' => count($Repeaters->getValue($Select)), 'ticks_after' => $Probe->ticks - $ticks])
         );

         // @@ C) The timers registered on the loop
         $Cadences = [];
         $Left = [];
         $Cases = [
            'int 1' => [['interval' => 1], []],
            'float 0.25, heartbeat 15' => [['interval' => 0.25], ['heartbeat' => 15]],
            'no tick, heartbeat 15' => [[], ['heartbeat' => 15, 'tick' => false]],
            'no tick, heartbeat 3' => [[], ['heartbeat' => 3, 'tick' => false]],
            'tick 30' => [['interval' => 30], []],
            'tick 20, expiration 5' => [['interval' => 20], ['expiration' => 5]],
            'tick 0.5, expiration 5' => [['interval' => 0.5], ['expiration' => 5]],
            'tick 4.5, expiration 5' => [['interval' => 4.5], ['expiration' => 5]],
            'tick 1.5, expiration 2' => [['interval' => 1.5], ['expiration' => 2]],
            'tick 0.5, expiration 1' => [['interval' => 0.5], ['expiration' => 1]],
            'zero' => [['interval' => 0], []],
            'negative' => [['interval' => -0.5], []],
            'NAN' => [['interval' => NAN], []],
            'INF' => [['interval' => INF], []],
            'below the resolution' => [['interval' => 0.000_000_001], []],
            'tick 1.5, heartbeat 1' => [['interval' => 1.5], ['heartbeat' => 1]],
            'beyond the horizon' => [['interval' => Select::HORIZON * 2], []],
         ];
         foreach ($Cases as $name => [$arguments, $options]) {
            $Stream = $open($arguments, $options);
            $Cadences[$name] = $cadence($Select);
            $Stream->SSE->disconnect();
            $Left[$name] = count($Repeaters->getValue($Select));
         }

         yield assert(
            assertion: $Cadences === [
               'int 1' => [1.0],
               'float 0.25, heartbeat 15' => [0.25],
               'no tick, heartbeat 15' => [10.0],
               'no tick, heartbeat 3' => [3.0],
               'tick 30' => [30.0, 10.0],
               'tick 20, expiration 5' => [20.0, 4.0],
               'tick 0.5, expiration 5' => [0.5],
               'tick 4.5, expiration 5' => [4.5, 4.0],
               'tick 1.5, expiration 2' => [1.5, 1.0],
               'tick 0.5, expiration 1' => [0.5],
               'zero' => [1.0],
               'negative' => [1.0],
               'NAN' => [1.0],
               'INF' => [1.0],
               'below the resolution' => [Select::RESOLUTION],
               'tick 1.5, heartbeat 1' => [1.5, 1.0],
               'beyond the horizon' => [10.0],
            ] && array_sum($Left) === 0,
            description: 'the Tick has a loop timer at exactly its interval (1 s for a non-positive or non-finite one, the loop resolution as the floor, none beyond the horizon); a supervisor timer joins at the tightest of heartbeat and 10 s, a full second under the idle reaper, only when there is no Tick or it must outpace it; teardown cancels every one — observed: '
               . json_encode(['timers' => $Cadences, 'left' => $Left])
         );

         // @@ C'') The Tick's own timer supervises: with no other timer, a
         //     Tick that sends nothing still gets the heartbeat out
         $Quiet = $open(['interval' => 0.25], ['heartbeat' => 1]);
         $Set = $Repeaters->getValue($Select);
         $lone = count($Set);
         $due = $Set[array_key_first($Set)]['deadline'] ?? 0;
         $writes = $Quiet->Connection->writes;
         // ! A whole heartbeat without a write, and a lease to renew
         $Wrote->setValue($Quiet->SSE, 0);
         $Quiet->Connection->used = 0;
         $wait($due + 1 * $MS);
         $Ticker->invoke($Select);
         $pinged = $Quiet->Connection->writes - $writes;
         $renewed = $Quiet->Connection->used;
         $Quiet->SSE->disconnect();

         yield assert(
            assertion: $lone === 1 && $Quiet->ticks === 1 && $pinged === 1 && $renewed > 0,
            description: 'a 0.25 s Tick under a 1 s heartbeat is the only loop timer of its stream, and its run sends the heartbeat and renews the lease — observed: '
               . json_encode(['timers' => $lone, 'ticks' => $Quiet->ticks, 'pings' => $pinged, 'renewed' => $renewed > 0])
         );

         // @@ C') A supervisor faster than the Tick never moves it: the Tick
         //    keeps the spacing of its own interval
         $Slow = $open(['interval' => 1.5], ['heartbeat' => 1]);
         $origin = (int) hrtime(true);
         $At = [];
         $seen = 0;
         while ((int) hrtime(true) < $origin + 3_400 * $MS) {
            $Ticker->invoke($Select);
            if ($Slow->ticks !== $seen) {
               $seen = $Slow->ticks;
               $At[] = (int) round(((int) hrtime(true) - $origin) / $MS / 100);
            }
            usleep(5_000);
         }
         $Slow->SSE->disconnect();

         yield assert(
            // ? A loaded machine may see a run a little late, never early
            assertion: count($At) === 2
               && $At[0] >= 15 && $At[0] <= 17 && $At[1] >= 30 && $At[1] <= 32,
            description: 'a 1.5 s Tick under a 1 s heartbeat runs at 1.5 s and 3.0 s, never on the supervisor cadence — observed (tenths of a second): '
               . json_encode($At)
         );

         // ---
         // @@ D) Loop without recurring timers: the static Timer, whole seconds
         TCP_Server_CLI::$Event = $Legacy;
         // ! Open right after a wall-clock second begins: this path compares
         //   `time()` readings
         while (microtime(true) - floor(microtime(true)) > 0.2) {
            usleep(20_000);
         }
         $origin = (int) hrtime(true);

         $Tenth = $open(['interval' => 0.1]);
         $Half = $open(['interval' => 1.5]);
         $Intervals = [];
         foreach ($Tasks->getValue() as $Bucket) {
            foreach ($Bucket as $id => $Task) {
               $Intervals[$id] = $Task[0];
            }
         }

         yield assert(
            assertion: $Repeaters->getValue($Select) === []
               && count($Tenth->Connection->timers) === 1 && count($Half->Connection->timers) === 1
               && ($Intervals[$Tenth->Connection->timers[0]] ?? null) === 1
               && ($Intervals[$Half->Connection->timers[0]] ?? null) === 2,
            description: 'without recurring timers the supervisor is a static Timer task listed in the connection timer ids, its interval rounded up to whole seconds — observed: '
               . json_encode(['intervals' => $Intervals, 'repeaters' => count($Repeaters->getValue($Select))])
         );

         $Observed = [];
         foreach ([300, 1_250, 2_250] as $at) {
            $wait($origin + $at * $MS);
            $Tenth->SSE->supervise();
            $Half->SSE->supervise();
            $Observed[$at] = [$Tenth->ticks, $Half->ticks];
         }

         yield assert(
            assertion: $Observed === [300 => [0, 0], 1_250 => [1, 0], 2_250 => [2, 1]],
            description: 'on the fallback a 0.1 s Tick runs once per second and a 1.5 s one every two — observed: ' . json_encode($Observed)
         );

         $Tenth->SSE->close();
         $Half->SSE->disconnect();

         yield assert(
            assertion: $count() === 0
               && $Tenth->Connection->timers === [] && $Half->Connection->timers === [],
            description: 'teardown deletes the static Timer task and its connection timer id — observed: '
               . json_encode(['tasks' => $count(), 'connection' => [$Tenth->Connection->timers, $Half->Connection->timers]])
         );
      }
      finally {
         foreach ($Streams as $SSE) {
            $SSE->disconnect();
         }
         foreach ($Sockets as $pair) {
            fclose($pair[0]);
            fclose($pair[1]);
         }
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
         Timer::del();
         pcntl_alarm(0);
         pcntl_signal(SIGALRM, $OldHandler);
         if ($oldAlarm > 0) {
            pcntl_alarm($oldAlarm);
         }
      }
   }
);
