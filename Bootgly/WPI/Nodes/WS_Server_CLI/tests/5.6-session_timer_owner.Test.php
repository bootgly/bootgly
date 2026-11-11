<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Registry;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame as ServerFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


/**
 * The heartbeat supervisor of a WebSocket session has one owner per worker:
 * the event loop when it offers recurring timers, the static `Timer`
 * otherwise — never both. On the loop it takes the place of the transport's
 * idle expiration as the connection's periodic check on the worker's
 * supervision sweep (`Connection::watch()`) — one loop timer for the whole
 * worker — so every close path stops it.
 *
 * The private `Select::tick()` is what `loop()` calls on every iteration; it
 * is driven by hand here, against the real clock.
 */
return new Test(
   description: 'A WebSocket session should keep its heartbeat supervisor on the loop that offers recurring timers, on the static Timer otherwise, and stop it on every close path',
   test: function () {
      $Reflection = new ReflectionClass(Select::class);
      $tick = $Reflection->getMethod('tick');
      $Repeaters = $Reflection->getProperty('Repeaters');
      // ! A loop with no timers of its own: the session stays on the static Timer
      $Legacy = new class implements Events, Loops, Scheduler {
         /** @var array<int> */
         public array $cancelled = [];
         public function add ($Socket, int $flag, $payload): bool { return true; }
         public function del ($Socket, int $flag): bool { return true; }
         public function loop (): void {}
         public function destroy (): void {}
         public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool { return false; }
         public function defer (float|int $deadline, Closure $Callback): int { return 0; }
         public function cancel (int $ID): bool { $this->cancelled[] = $ID; return false; }
         public function interrupt (Fiber $Fiber, Throwable $Throwable): bool { return false; }
      };
      // ! A sleep a signal cannot cut short
      $wait = static function (int $until): void {
         while (($left = $until - (int) hrtime(true)) > 0) {
            usleep((int) max(1, min(20_000, $left / 1_000)));
         }
      };
      $Evidence = [
         'error' => '',
         'owner' => null,
         'ping' => null,
         'disconnect' => null,
         'close' => null,
         'unschedulable' => null,
         'legacy' => null,
      ];
      $Pairs = [];
      /** @var array<int,Session> $Sessions */
      $Sessions = [];
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $oldHeartbeat = Session::$heartbeatInterval;
      $NS = 1_000_000_000;

      try {
         // ! Hermetic timer and server statics
         Timer::del();
         TCP_Server_CLI::$context = [];
         $Server = new TCP_Server_CLI;
         $Collection = new Connections($Server);
         $Loop = new Select($Collection);
         TCP_Server_CLI::$Event = $Loop;
         TCP_Server_CLI::$connectionIdleTimeout = 15;
         Session::$heartbeatInterval = 1;

         $Open = static function () use (&$Pairs, &$Sessions): array {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('could not create a socket pair');
            }
            stream_set_blocking($Pair[1], false);
            $Pairs[] = $Pair;
            $Connection = new Connection($Pair[0], '127.0.0.1', 41000 + count($Pairs));
            $Session = new Session($Connection);
            $Connection->decoded = $Session;
            $Sessions[] = $Session;

            return [$Connection, $Session, $Pair[1]];
         };
         $Intervals = static fn (): array => array_values(array_column($Repeaters->getValue($Loop), 'interval'));
         $Supervised = new ReflectionProperty(Connections::class, 'Supervised');
         $supervised = static fn (Connection $Connection): bool
            => isset($Supervised->getValue()[spl_object_id($Connection)]);

         // @ A) On the loop: the supervisor replaces the transport's idle
         //      expiration as the connection's check on the worker's sweep
         [$Connection, $Session, $Peer] = $Open();
         $inherited = $Connection->due - time();
         $before = $Intervals();
         $registered = (int) hrtime(true);
         $Session->establish();
         $Evidence['owner'] = [
            $inherited >= 14 && $inherited <= 15,
            $before,
            $Session->watched,
            $Session->timer,
            $Connection->due - time() <= 1 && $Connection->due - time() >= 0,
            $supervised($Connection),
            $Intervals(),
            $Connection->timers,
            Registry::snapshot(),
         ];

         // @ B) The sweep runs it: a silent peer is probed one interval later
         $tick->invoke($Loop);
         $early = (string) @fread($Peer, 65536);
         $wait($registered + (int) (2.2 * $NS));
         $tick->invoke($Loop);
         $Ping = ServerFrame::decode((string) @fread($Peer, 65536), 0, 1024);
         $Evidence['ping'] = [$early, $Ping?->opcode, $Session->awaitingPong, $Connection->status];

         // @ C) disconnect() takes it off the sweep
         $Session->disconnect();
         $Evidence['disconnect'] = [$Session->watched, $Session->timer, $Connection->due, $supervised($Connection), Registry::snapshot()];

         // @ D) Connection::close() reaches it too
         [$Closing, $Closed] = $Open();
         $Closed->establish();
         $live = [$Closed->watched, $Closing->due > 0, $supervised($Closing)];
         $Closing->close();
         $Evidence['close'] = [$live, $Closed->watched, $Closing->due, $supervised($Closing), $Intervals()];

         // @ E) An interval the loop cannot schedule: unsupervised, no check left
         Session::$heartbeatInterval = (int) (Select::HORIZON * 2);
         [$Far, $Farthest] = $Open();
         $Farthest->establish();
         $Evidence['unschedulable'] = [$Farthest->watched, $Farthest->timer, $Far->due, $supervised($Far), $Far->timers, Registry::snapshot()];
         $Farthest->disconnect();

         // @ F) A loop without the capability keeps the static Timer, alone
         TCP_Server_CLI::$Event = $Legacy;
         Session::$heartbeatInterval = 30;
         [$Static, $Statics] = $Open();
         $inherited = $Static->timers;
         $Statics->establish();
         $installed = [
            count($inherited),
            $Static->timers === [$Statics->timer],
            $Statics->watched,
            $Static->due,
            $supervised($Static),
            Registry::snapshot() === [$Statics->timer],
         ];
         $Statics->disconnect();
         $Evidence['legacy'] = [$installed, $Statics->timer, Registry::snapshot(), $Legacy->cancelled];
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Sessions as $Session) {
            try {
               $Session->disconnect();
               $Session->Connection->close();
            }
            catch (Throwable) {
               // ...
            }
         }
         foreach ($Pairs as $Pair) {
            foreach ($Pair as $Stream) {
               if (is_resource($Stream)) {
                  @fclose($Stream);
               }
            }
         }
         Timer::del();
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         Session::$heartbeatInterval = $oldHeartbeat;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }

      $observed = json_encode($Evidence);

      yield assert(
         assertion: $Evidence['error'] === ''
            && $Evidence['owner'] === [true, [1 * $NS], true, 0, true, true, [1 * $NS], [], []],
         description: "on a loop with recurring timers establish() swaps the transport's idle expiration for the supervisor at the heartbeat interval, on the worker's one sweep timer, and registers no static Timer task — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['ping'] === ['', WS::OPCODE_PING, true, Connections::STATUS_ESTABLISHED],
         description: "the sweep runs the supervisor: nothing before the interval, then the silent peer is probed — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['disconnect'] === [false, 0, 0, false, []]
            && $Evidence['close'] === [[true, true, true], false, 0, false, [1 * $NS]],
         description: "disconnect() and Connection::close() both take the supervisor off the sweep, which stays armed for the worker — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['unschedulable'] === [true, 0, 0, false, [], []],
         description: "an interval beyond the loop horizon leaves the session unsupervised, with no check on either owner — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['legacy'] === [[1, true, false, 0, false, true], 0, [], []],
         description: "on a loop without recurring timers the supervisor is one static Timer task, deleted on disconnect, and the loop is asked to cancel nothing — observed: {$observed}"
      );
   }
);
