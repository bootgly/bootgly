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
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events as Hooks;
use Bootgly\WPI\Nodes\WS_Client_CLI\Session;


/**
 * A WebSocket client keeps its timers on one owner: its event loop when the
 * loop offers recurring timers — the session supervisor as a recurring timer,
 * the handshake deadline and the reconnect backoff as one-shots — and the
 * static `Timer` otherwise. Never both.
 *
 * The private `Select::tick()` is what `loop()` calls on every iteration; it
 * is driven by hand here, against the real clock.
 */
return new Test(
   description: 'A WebSocket client should keep its supervisor, handshake deadline and reconnect backoff on the loop that offers recurring timers, on the static Timer otherwise',
   test: function () {
      $Reflection = new ReflectionClass(Select::class);
      $tick = $Reflection->getMethod('tick');
      $Repeaters = $Reflection->getProperty('Repeaters');
      $Monotonics = $Reflection->getProperty('MonotonicTimers');
      // ! A loop with no timers of its own: the client stays on the static Timer
      $Legacy = new class implements Events, Loops, Scheduler {
         /** @var array<int> */
         public array $cancelled = [];
         public int $deferred = 0;
         public function add ($Socket, int $flag, $payload): bool { return true; }
         public function del ($Socket, int $flag): bool { return true; }
         public function loop (): void {}
         public function destroy (): void {}
         public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool { return false; }
         public function defer (float|int $deadline, Closure $Callback): int { $this->deferred++; return 0; }
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
         'once' => null,
         'legacy' => null,
         'fallback' => null,
         'live' => null,
      ];
      $Streams = [];
      /** @var array<int,Session> $Sessions */
      $Sessions = [];
      $NS = 1_000_000_000;

      try {
         // ! Hermetic static Timer
         Timer::del();

         $Pair = static function () use (&$Streams): array {
            $Listener = stream_socket_server('tcp://127.0.0.1:0');
            if ($Listener === false) {
               throw new RuntimeException('could not create the loopback listener');
            }
            $Writer = stream_socket_client('tcp://' . stream_socket_get_name($Listener, false), $code, $message, 1.0);
            $Reader = stream_socket_accept($Listener, 1.0);
            if ($Writer === false || $Reader === false) {
               throw new RuntimeException('could not establish the loopback pair');
            }
            stream_set_blocking($Writer, false);
            stream_set_blocking($Reader, false);
            $Streams[] = $Listener;
            $Streams[] = $Writer;
            $Streams[] = $Reader;

            return [$Writer, $Reader];
         };
         $Build = static function (int $heartbeat): WS_Client_CLI {
            $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
            $Client->configure(new WS_Client_CLI\Configs(
               host: '127.0.0.1',
               port: 1,
               heartbeatInterval: $heartbeat,
               compression: false
            ));
            $Client->reset();

            return $Client;
         };
         $Open = static function (WS_Client_CLI $Client) use (&$Sessions, $Pair): array {
            [$Writer, $Reader] = $Pair();
            $Connection = new Connection($Writer, Client: $Client);
            $Session = new Session($Connection, 'test-key', $Client);
            $Client->Session = $Session;
            new ReflectionMethod($Client, 'wire')->invoke($Client);
            $Sessions[] = $Session;

            return [$Connection, $Session, $Reader];
         };

         // @ A) On the loop: one recurring timer, no static task
         $Client = $Build(1);
         $Loop = $Client->Event;
         if ($Loop instanceof Select === false) {
            throw new RuntimeException('the client loop is not a Select');
         }
         $Intervals = static fn (): array => array_values(array_column($Repeaters->getValue($Loop), 'interval'));
         [$Connection, $Session, $Reader] = $Open($Client);
         $registered = (int) hrtime(true);
         $Session->establish();
         $Evidence['owner'] = [$Session->timer !== 0, $Intervals(), $Connection->timers, Registry::snapshot()];

         // @ B) The loop runs it: a silent peer is probed one interval later
         $tick->invoke($Loop);
         $early = $Session->awaitingPong;
         $wait($registered + (int) (2.2 * $NS));
         $tick->invoke($Loop);
         $Evidence['ping'] = [$early, $Session->awaitingPong];

         // @ C) disconnect() cancels it on the loop
         $id = $Session->timer;
         $Session->disconnect();
         $Evidence['disconnect'] = [$Session->timer, $Intervals(), $Loop->cancel($id), Registry::snapshot()];
         $Connection->close();

         // @ D) The one-shots (handshake deadline, reconnect backoff) ride the loop
         $Defer = new ReflectionMethod(WS_Client_CLI::class, 'defer');
         $runs = 0;
         $pending = count($Monotonics->getValue($Loop));
         $armed = (int) hrtime(true);
         $Defer->invoke($Client, 1, static function () use (&$runs): void {
            $runs++;
         });
         $queued = [count($Monotonics->getValue($Loop)) - $pending, Registry::snapshot()];
         $wait($armed + (int) (0.5 * $NS));
         $tick->invoke($Loop);
         $before = $runs;
         $wait($armed + (int) (1.05 * $NS));
         $tick->invoke($Loop);
         $tick->invoke($Loop);
         $Evidence['once'] = [$queued, $before, $runs, count($Monotonics->getValue($Loop)) - $pending];

         // @ E) A loop without the capability keeps the static Timer, alone
         $Follower = $Build(30);
         $Follower->react($Legacy);
         [$Static, $Statics] = $Open($Follower);
         $Statics->establish();
         $installed = [
            $Statics->timer !== 0,
            $Static->timers === [$Statics->timer],
            Registry::snapshot() === [$Statics->timer],
         ];
         $Statics->disconnect();
         $Evidence['legacy'] = [$installed, $Statics->timer, Registry::snapshot(), $Legacy->cancelled];
         $Static->close();

         $Defer->invoke($Follower, 30, static function (): void {});
         $Evidence['fallback'] = [count(Registry::snapshot()), $Legacy->deferred];
         Timer::del();

         // @ F) Live: a mute peer is given up at the handshake deadline with
         //      the static Timer never holding a task
         $Mute = stream_socket_server('tcp://127.0.0.1:0');
         if ($Mute === false) {
            throw new RuntimeException('could not create the mute listener');
         }
         $Streams[] = $Mute;
         $port = (int) substr((string) strrchr((string) stream_socket_get_name($Mute, false), ':'), 1);
         $tasks = null;
         $Dialer = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
         $Dialer->configure(new WS_Client_CLI\Configs(
            host: '127.0.0.1',
            port: $port,
            compression: false,
            handshakeTimeout: 1
         ));
         $Dialer->on(Hooks::Disconnected, static function () use (&$tasks): void {
            $tasks = Registry::snapshot();
         });
         $started = microtime(true);
         $Dialer->connect('/');
         $elapsed = microtime(true) - $started;
         $Evidence['live'] = [$tasks, $elapsed >= 0.9 && $elapsed < 2.0, Registry::snapshot()];
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
         foreach ($Streams as $Stream) {
            if (is_resource($Stream)) {
               @fclose($Stream);
            }
         }
         Timer::del();
      }

      $observed = json_encode($Evidence);

      yield assert(
         assertion: $Evidence['error'] === ''
            && $Evidence['owner'] === [true, [1 * $NS], [], []],
         description: "on a loop with recurring timers establish() registers one supervisor at the heartbeat interval — off Connection::\$timers, with no static Timer task — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['ping'] === [false, true],
         description: "the loop runs the supervisor: nothing before the interval, then the silent peer is probed — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['disconnect'] === [0, [], false, []],
         description: "disconnect() cancels the supervisor on the loop — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['once'] === [[1, []], 0, 1, 0],
         description: "a deferred task is one monotonic one-shot of the loop: not before its delay, exactly once after, and no static Timer task — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['legacy'] === [[true, true, true], 0, [], []]
            && $Evidence['fallback'] === [1, 0],
         description: "on a loop without recurring timers the supervisor and the one-shots are static Timer tasks, and the loop is asked for nothing — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['live'] === [[], true, []],
         description: "a live handshake deadline fires on the loop one timeout after the dial, the static Timer holding no task — observed: {$observed}"
      );
   }
);
