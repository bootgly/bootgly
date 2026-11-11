<?php

use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;


/**
 * The whole-second contract of the SSE `Tick` on the static `Timer`, pinned
 * before the interval accepted fractions: the default interval is one second,
 * a non-positive one falls back to one second, and a `Tick` never runs before
 * its interval has elapsed since its last run, nor twice for one elapsed
 * interval.
 *
 * The supervisor is driven by hand (`supervise()` is what the static Timer
 * calls), so the case states the due rule itself. A loop left in the process
 * by another case is replaced by one without recurring timers, so the stream
 * always takes the static Timer.
 */
return new Test(
   description: 'SSE Tick should run once per elapsed whole-second interval, with 1 s as the default and the floor of a non-positive interval',
   test: new Assertions(Case: function (): Generator {
      $Sockets = [];
      $Streams = [];
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      // ! The static Timer arms a real alarm: an unhandled SIGALRM would kill
      //   this process, and a pending one must not outlive the case
      $OldHandler = pcntl_signal_get_handler(SIGALRM);
      $oldAlarm = pcntl_alarm(0);
      pcntl_signal(SIGALRM, static function (): void {});

      // ! A loop with no timers of its own: the stream stays on the static Timer
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
      // ! A sleep a signal cannot cut short
      $wait = static function (int $until): void {
         while (($left = $until - (int) hrtime(true)) > 0) {
            usleep((int) max(1, min(50_000, $left / 1_000)));
         }
      };
      $open = static function (array $arguments) use (&$Sockets, &$Streams): object {
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($pair === false) {
            throw new RuntimeException('Could not create the socket pair.');
         }
         $Sockets[] = $pair;

         $Connection = new class($pair[0], '127.0.0.1', 1) extends Connection {
            public function writing (&$Socket, null|int $length = null, string $buffer = ''): bool
            {
               return true;
            }

            // ! No event loop in this process — detach from `Server::$Event`
            public function close (): true
            {
               $this->status = Connections::STATUS_CLOSED;

               return true;
            }
         };
         $Connection->status = Connections::STATUS_ESTABLISHED;
         $Connection->expiration = 0;

         $Probe = new class {
            public int $ticks = 0;
            public null|SSE $SSE = null;
         };
         $SSE = new SSE(new Response);
         $SSE->heartbeat = 0;
         $SSE->bind($Connection, null);
         $SSE->open(static function () use ($Probe): void {
            $Probe->ticks++;
         }, ...$arguments);
         $Probe->SSE = $SSE;
         $Streams[] = $SSE;

         return $Probe;
      };
      $supervise = static function (array $Probes): array {
         $ticks = [];
         foreach ($Probes as $name => $Probe) {
            $Probe->SSE->supervise();
            $ticks[$name] = $Probe->ticks;
         }

         return $ticks;
      };

      try {
         TCP_Server_CLI::$context = [];
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $Legacy;
         }

         // ! Open right after a wall-clock second begins: the whole-second due
         //   rule compares `time()` readings, so the case must not straddle a
         //   boundary between `open()` and the first supervision
         while (microtime(true) - floor(microtime(true)) > 0.2) {
            usleep(20_000);
         }
         $origin = (int) hrtime(true);

         $Probes = [
            'default' => $open([]),
            'one' => $open(['interval' => 1]),
            'zero' => $open(['interval' => 0]),
            'negative' => $open(['interval' => -3]),
            'two' => $open(['interval' => 2]),
         ];
         // ! Supervised late and off its interval: see the last assertion
         $Late = $open(['interval' => 2]);
         $opened = true;
         foreach ($Probes as $Probe) {
            $opened = $opened && $Probe->SSE->opened && $Probe->SSE->closed === false;
         }

         yield assert(
            assertion: $opened,
            description: 'every stream opened on its double connection'
         );

         // @ No interval has elapsed
         $ticks = $supervise($Probes);

         yield assert(
            assertion: $ticks === ['default' => 0, 'one' => 0, 'zero' => 0, 'negative' => 0, 'two' => 0],
            description: 'a Tick never runs before its interval elapsed — observed: ' . json_encode($ticks)
         );

         // @ One second elapsed
         $wait($origin + 1_250_000_000);
         $ticks = $supervise($Probes);
         // @ ...and a second supervision inside the same interval adds nothing
         $again = $supervise($Probes);

         yield assert(
            assertion: $ticks === ['default' => 1, 'one' => 1, 'zero' => 1, 'negative' => 1, 'two' => 0]
               && $again === $ticks,
            description: 'after one second the default, 1 s, 0 and negative intervals ticked once (never twice), the 2 s one did not — observed: '
               . json_encode([$ticks, $again])
         );

         // @ Two seconds elapsed
         $wait($origin + 2_250_000_000);
         $ticks = $supervise($Probes);

         yield assert(
            assertion: $ticks === ['default' => 2, 'one' => 2, 'zero' => 2, 'negative' => 2, 'two' => 1],
            description: 'after two seconds the 1 s streams ticked twice and the 2 s stream once — observed: ' . json_encode($ticks)
         );

         // @ Teardown stops the Tick
         foreach ($Probes as $Probe) {
            $Probe->SSE->close();
         }
         $wait($origin + 3_300_000_000);
         $closed = $supervise($Probes);

         yield assert(
            assertion: $closed === $ticks,
            description: 'a closed stream never ticks again — observed: ' . json_encode($closed)
         );

         // @ The interval counts from the last run, not from a grid: first
         //   supervised 3 s after it opened, a 2 s Tick runs then — and not
         //   again one second later, only two seconds later
         $Runs = [];
         $Late->SSE->supervise();
         $Runs[] = $Late->ticks;
         $wait($origin + 4_300_000_000);
         $Late->SSE->supervise();
         $Runs[] = $Late->ticks;
         $wait($origin + 5_300_000_000);
         $Late->SSE->supervise();
         $Runs[] = $Late->ticks;

         yield assert(
            assertion: $Runs === [1, 1, 2],
            description: 'a 2 s Tick first supervised at 3 s runs at 3 s, not at 4 s, and again at 5 s — observed: ' . json_encode($Runs)
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
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         pcntl_alarm(0);
         pcntl_signal(SIGALRM, $OldHandler);
         if ($oldAlarm > 0) {
            pcntl_alarm($oldAlarm);
         }
      }
   })
);
