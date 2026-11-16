<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections as WPIConnections;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;


/**
 * The SSE timers of a worker come due together. On a loop that owns
 * recurring timers (`Repeating`) a stream registers its `Tick` — or, without
 * one, its supervisor — with a slack of 1/16 of the period: the first run
 * moves up to the next point of a grid every timer of that period shares.
 * Streams opened at any instants then come due on at most 16 instants per
 * period and share one loop wakeup at each, where a timer per stream on a
 * grid of its own wakes the loop once per stream and period. A first run is
 * up to one slack late, never early. A stream whose heartbeat outpaces its
 * `Tick` holds both timers, each on the grid of 1/16 of its own period.
 *
 * Driven on a real Select: its private `tick()` is what `loop()` calls on
 * every pass, invoked by hand here against the real clock, and its private
 * `Repeaters` hold each timer's interval and next deadline.
 */
return new Test(
   description: 'SSE timers should come due on at most 16 instants per period, so the streams of a worker share their loop wakeups, never running before their interval',
   test: new Assertions(Case: function (): Generator {
      $Reflection = new ReflectionClass(Select::class);
      $tick = $Reflection->getMethod('tick');
      $Repeaters = $Reflection->getProperty('Repeaters');
      $Ticker = new ReflectionProperty(SSE::class, 'ticker');
      $Supervisor = new ReflectionProperty(SSE::class, 'timer');
      $Sockets = [];
      $Streams = [];
      $Select = null;
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      // ! A stream that fell back to the static Timer would arm a real alarm:
      //   an unhandled SIGALRM would kill this process
      $OldHandler = pcntl_signal_get_handler(SIGALRM);
      $oldAlarm = pcntl_alarm(0);
      pcntl_signal(SIGALRM, static function (): void {});
      $Connections = new class implements WPIConnections {
         public function connect (): bool
         {
            return true;
         }
      };
      $NS = 1_000_000_000;
      // ! A sleep a signal cannot cut short
      $wait = static function (int $until): void {
         while (($left = $until - (int) hrtime(true)) > 0) {
            usleep((int) max(1, min(20_000, $left / 1_000)));
         }
      };
      // @ One stream on a double connection, opened between two clock reads
      $open = static function (null|Closure $Tick, float $interval, int $heartbeat = 0) use (&$Sockets, &$Streams): array {
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

            // ! No loop serves this double — detach from `Server::$Event`
            public function close (): true
            {
               $this->status = Connections::STATUS_CLOSED;

               return true;
            }
         };
         $Connection->status = Connections::STATUS_ESTABLISHED;
         $Connection->expiration = 0;

         $SSE = new SSE(new Response);
         $SSE->heartbeat = $heartbeat;
         $SSE->bind($Connection, null);
         $before = (int) hrtime(true);
         $SSE->open($Tick, $interval);
         $after = (int) hrtime(true);
         $Streams[] = $SSE;

         return [$SSE, $before, $after];
      };
      // @ Where the first runs of the streams' timers of one period fall:
      //   before the period elapsed, a slack late or more, off the grid of
      //   the slack — and on how many instants, and phases of the period
      $inspect = static function (array $Opened, ReflectionProperty $Own, int $period, int $quantum) use ($Repeaters, &$Select): array {
         $Set = $Repeaters->getValue($Select);
         $Census = ['strays' => 0, 'early' => 0, 'late' => 0, 'offgrid' => 0];
         $Instants = [];
         $Phases = [];
         foreach ($Opened as [$SSE, $before, $after]) {
            $Timer = $Set[$Own->getValue($SSE)] ?? null;
            // ? No timer of this period for the stream
            if ($Timer === null || $Timer['interval'] !== $period) {
               $Census['strays']++;
               continue;
            }

            $deadline = $Timer['deadline'];
            $Census['early'] += (int) ($deadline < $before + $period);
            $Census['late'] += (int) ($deadline >= $after + $period + $quantum);
            $Census['offgrid'] += (int) ($deadline % $quantum !== 0);
            $Instants[$deadline] = true;
            $Phases[$deadline % $period] = true;
         }
         $Census['instants'] = count($Instants);
         $Census['phases'] = count($Phases);

         // :
         return $Census;
      };

      try {
         TCP_Server_CLI::$context = [];
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         $Select = new Select($Connections);
         TCP_Server_CLI::$Event = $Select;

         // @@ A) 48 streams with a 250 ms Tick, opened 2 to 11 ms apart: over
         //       more than one interval, none at the same instant
         $streams = 48;
         $interval = 0.25;
         $period = (int) ($interval * $NS);
         $quantum = intdiv($period, 16);
         $Ticks = array_fill(0, $streams, 0);
         $First = [];
         $Opened = [];
         $origin = (int) hrtime(true);
         $offset = 0;
         for ($index = 0; $index < $streams; $index++) {
            $offset += $index === 0 ? 0 : 2_000_000 + ($index * 3_779_000) % 9_000_000;
            $wait($origin + $offset);
            $Opened[] = $open(static function () use (&$Ticks, &$First, $index): void {
               $First[$index] ??= (int) hrtime(true);
               $Ticks[$index]++;
            }, $interval);
         }
         $spread = $Opened[$streams - 1][1] - $Opened[0][1];
         $census = $inspect($Opened, $Ticker, $period, $quantum);
         $supervisors = 0;
         foreach ($Opened as [$SSE]) {
            $supervisors += (int) ($Supervisor->getValue($SSE) !== 0);
         }
         $timers = count($Repeaters->getValue($Select));
         $observed = json_encode(['spread_ms' => $spread / 1_000_000, 'timers' => $timers, 'supervisors' => $supervisors] + $census);

         yield assert(
            assertion: $spread > $period && $timers === $streams && $supervisors === 0
               && $census['strays'] === 0 && $census['early'] === 0 && $census['late'] === 0
               && $census['offgrid'] === 0 && $census['phases'] <= 16,
            description: "{$streams} streams opened over more than one interval register one Tick timer each, due at or after its interval, less than 1/16 of it late, on the grid of that 1/16 — so they come due on at most 16 instants per interval, not {$streams} — observed: {$observed}"
         );

         // @@ B) The reactor driven until every stream ticked and two
         //       intervals passed: one wakeup runs every Tick due at its instant
         $passes = 0;
         $start = (int) hrtime(true);
         $least = $start + 2 * $period;
         $limit = $start + 20 * $period;
         while (($now = (int) hrtime(true)) < $limit && ($now < $least || count($First) < $streams)) {
            $ran = array_sum($Ticks);
            $pause = $tick->invoke($Select);
            $passes += (int) (array_sum($Ticks) > $ran);
            usleep((int) max(1, min(50_000, $pause === null ? 1_000 : $pause * 1_000_000)));
         }
         $elapsed = (int) hrtime(true) - $start;
         // ! Every wakeup after the first runs the Ticks of at least one grid
         //   point inside the window; the first may run only those already due
         $bound = intdiv($elapsed, $quantum) + 2;
         $premature = 0;
         foreach ($Opened as $index => [, $before]) {
            $premature += (int) (isset($First[$index]) && $First[$index] < $before + $period);
         }
         $observed = json_encode([
            'passes' => $passes,
            'bound' => $bound,
            'elapsed_ms' => $elapsed / 1_000_000,
            'ticks' => [min($Ticks), max($Ticks), array_sum($Ticks)],
            'premature' => $premature,
         ]);

         yield assert(
            assertion: count($First) === $streams && $premature === 0
               && $passes >= 1 && $passes <= $bound,
            description: "every stream ticked, none before its interval elapsed, and the loop woke for the Ticks at most once per 1/16 of the interval — a timer per stream wakes it once per stream and interval — observed: {$observed}"
         );

         // @@ C) 8 streams without a Tick, opened 3 to 7 ms apart: the
         //       supervisor alone, every 10 s (heartbeat off), with a slack
         //       of 1/16 of its cadence
         $cadence = 10 * $NS;
         $step = intdiv($cadence, 16);
         $Watched = [];
         $origin = (int) hrtime(true);
         $offset = 0;
         for ($index = 0; $index < 8; $index++) {
            $offset += $index === 0 ? 0 : 3_000_000 + ($index * 1_237_000) % 4_000_000;
            $wait($origin + $offset);
            $Watched[] = $open(null, 1.0);
         }
         $span = $Watched[7][2] - $Watched[0][1];
         $watched = $inspect($Watched, $Supervisor, $cadence, $step);
         $tickers = 0;
         foreach ($Watched as [$SSE]) {
            $tickers += (int) ($Ticker->getValue($SSE) !== 0);
         }
         $timers = count($Repeaters->getValue($Select));
         $observed = json_encode(['span_ms' => $span / 1_000_000, 'timers' => $timers, 'tickers' => $tickers] + $watched);

         yield assert(
            assertion: $timers === $streams + 8 && $tickers === 0
               && $watched['strays'] === 0 && $watched['early'] === 0 && $watched['late'] === 0
               && $watched['offgrid'] === 0 && $watched['instants'] <= intdiv($span, $step) + 2,
            description: "a stream without a Tick registers one supervisor timer at its 10 s cadence, due at or after it, less than 1/16 of it late, on the grid of that 1/16 — streams opened within one 1/16 come due on at most 2 instants — observed: {$observed}"
         );

         // @@ D) 4 streams with a 2 s Tick and a 1 s heartbeat: the supervisor
         //       outpaces the Tick, so each stream holds two timers, each with
         //       a slack of 1/16 of its own period — 125 ms for the Tick,
         //       62.5 ms for the supervisor. Each stream opens an eighth into a
         //       125 ms slot of the Tick's grid: in the first half of a slot
         //       the two grids part, the Tick comes due at the end of the slot
         //       and the supervisor half a slot before it
         $paced = 4;
         $interval = 2.0;
         $heartbeat = 1;
         $period = (int) ($interval * $NS);
         $quantum = intdiv($period, 16);
         $cadence = $heartbeat * $NS;
         $step = intdiv($cadence, 16);
         $Paced = [];
         for ($index = 0; $index < $paced; $index++) {
            $now = (int) hrtime(true);
            $wait($now - $now % $quantum + $quantum + intdiv($quantum, 8));
            $Paced[] = $open(static function (): void {}, $interval, $heartbeat);
         }
         $ticks = $inspect($Paced, $Ticker, $period, $quantum);
         $beats = $inspect($Paced, $Supervisor, $cadence, $step);
         // ! Where both first runs of a stream fall in a slot of the Tick's
         //   grid, for each stream registered in the first half of a slot —
         //   its registration lies between the clock reads around open()
         $Set = $Repeaters->getValue($Select);
         $Halves = [];
         foreach ($Paced as [$SSE, $before, $after]) {
            // ? Not provably in the first half of one slot (a stalled open)
            if (
               intdiv($before, $quantum) !== intdiv($after, $quantum)
               || $before % $quantum === 0 || $after % $quantum >= $step
            ) {
               continue;
            }

            $Halves[] = [
               ($Set[$Ticker->getValue($SSE)]['deadline'] ?? -1) % $quantum,
               ($Set[$Supervisor->getValue($SSE)]['deadline'] ?? -1) % $quantum,
            ];
         }
         $timers = count($Set);
         $observed = json_encode(['timers' => $timers, 'halves' => $Halves, 'tick' => $ticks, 'supervisor' => $beats]);

         yield assert(
            assertion: $timers === $streams + 8 + 2 * $paced
               && $ticks['strays'] === 0 && $ticks['early'] === 0 && $ticks['late'] === 0 && $ticks['offgrid'] === 0
               && $beats['strays'] === 0 && $beats['early'] === 0 && $beats['late'] === 0 && $beats['offgrid'] === 0
               && $Halves !== [] && $Halves === array_fill(0, count($Halves), [0, $step]),
            description: "a stream whose 2 s Tick is slower than its 1 s heartbeat registers two timers, the Tick on the grid of 1/16 of its interval and the supervisor on the grid of 1/16 of its cadence — opened in the first half of a 125 ms slot, the Tick comes due at the end of the slot and the supervisor 62.5 ms before it — observed: {$observed}"
         );

         // @@ E) Closing the streams cancels every timer they registered
         $closed = 0;
         foreach ([...$Opened, ...$Watched, ...$Paced] as [$SSE]) {
            $SSE->close();
            $closed += (int) $SSE->closed;
         }
         $timers = count($Repeaters->getValue($Select));
         $pause = $tick->invoke($Select);
         $observed = json_encode(['closed' => $closed, 'timers' => $timers, 'pause' => $pause]);

         yield assert(
            assertion: $closed === $streams + 8 + $paced && $timers === 0 && $pause === null,
            description: "closing the streams leaves the loop with no recurring timer and no deadline — observed: {$observed}"
         );

         // @@ F) Pin: each repeat() call of SSE::open() passes a slack, a share
         //       of its period
         $Tokens = token_get_all((string) file_get_contents((string) new ReflectionClass(SSE::class)->getFileName()));
         $count = count($Tokens);
         // ! The next token that is not whitespace or a comment
         $skip = static function (int $index) use ($Tokens, $count): int {
            while (
               $index < $count && is_array($Tokens[$index])
               && in_array($Tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
               $index++;
            }

            // :
            return $index;
         };
         // ! The body of open(): from its first `{` to the brace that closes it
         $first = null;
         $last = null;
         for ($index = 0; $index < $count && $first === null; $index++) {
            if (is_array($Tokens[$index]) && $Tokens[$index][0] === T_FUNCTION) {
               $name = $skip($index + 1);
               if (is_array($Tokens[$name] ?? null) && $Tokens[$name][0] === T_STRING && $Tokens[$name][1] === 'open') {
                  for ($first = $name; $first < $count && $Tokens[$first] !== '{'; $first++);
               }
            }
         }
         $depth = 0;
         for ($index = (int) $first; $index < $count; $index++) {
            $Token = $Tokens[$index];
            if ($Token === '{' || (is_array($Token) && in_array($Token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
               $depth++;
            }
            else if ($Token === '}' && --$depth === 0) {
               $last = $index;
               break;
            }
         }
         // @@ Each `->repeat(` call of that body: its top-level arguments
         $Calls = [];
         for ($index = (int) $first; $index < (int) $last; $index++) {
            $Token = $Tokens[$index];
            if (is_array($Token) === false || in_array($Token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) === false) {
               continue;
            }
            $name = $skip($index + 1);
            $paren = $skip($name + 1);
            if (
               is_array($Tokens[$name] ?? null) === false || $Tokens[$name][0] !== T_STRING
               || $Tokens[$name][1] !== 'repeat' || ($Tokens[$paren] ?? null) !== '('
            ) {
               continue;
            }

            $Arguments = [[]];
            $nesting = 0;
            for ($cursor = $paren + 1; $cursor < $count; $cursor++) {
               $Token = $Tokens[$cursor];
               if (in_array($Token, ['(', '[', '{'], true) || (is_array($Token) && in_array($Token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                  $nesting++;
               }
               else if (in_array($Token, [')', ']', '}'], true)) {
                  if ($nesting === 0) {
                     break;
                  }
                  $nesting--;
               }
               else if ($Token === ',' && $nesting === 0) {
                  $Arguments[] = [];
                  continue;
               }
               if (is_array($Token) && in_array($Token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                  continue;
               }
               $Arguments[count($Arguments) - 1][] = is_array($Token) ? $Token[1] : $Token;
            }
            // ? A trailing comma closes no argument
            $Arguments = array_values(array_filter($Arguments, static fn (array $Argument): bool => $Argument !== []));
            $Calls[] = [count($Arguments), in_array('PHASES', $Arguments[2] ?? [], true)];
         }
         $observed = json_encode($Calls);

         yield assert(
            assertion: $first !== null && $last !== null
               && $Calls === [[3, true], [3, true]],
            description: "both repeat() calls of SSE::open() — the Tick's and the supervisor's — pass a third argument, the slack, a share of the period by SSE::PHASES — observed [arguments, slack by PHASES]: {$observed}"
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
         $Select?->destroy();
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
         pcntl_alarm(0);
         pcntl_signal(SIGALRM, $OldHandler);
         if ($oldAlarm > 0) {
            pcntl_alarm($oldAlarm);
         }
      }
   })
);
