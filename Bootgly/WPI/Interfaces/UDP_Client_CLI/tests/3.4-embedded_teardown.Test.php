<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use const STREAM_SERVER_BIND;
use function assert;
use function count;
use function explode;
use function fclose;
use function gc_collect_cycles;
use function hrtime;
use function is_resource;
use function json_encode;
use function microtime;
use function stream_socket_get_name;
use function stream_socket_server;
use Closure;
use Fiber;
use LogicException;
use ReflectionProperty;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections\Connection;


/**
 * Teardown releases the descriptor: a closed flow leaves the reactor it lived
 * in (no read or write registration, no parked waiter), its registry and the
 * process (the socket is closed) — whether it is closed by stop() while a
 * Fiber waits on it (the wait returns `Closed` and the worker's loop keeps
 * running), by `Connections->close()`, or by an unwind of its own wait (an
 * exception from the bridge, a Fiber collected mid-wait). A bridge that stops
 * suspending trips the wait after eight fast wakes instead of hot-spinning.
 */
return new Test(
   description: 'UDP_Client_CLI: an embedded flow releases its descriptor on every teardown path',
   test: function () {
      $Peer = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      $Clients = [];
      $Bridge = static fn (mixed $value = null): mixed => Fiber::suspend($value);
      $Worker = static fn (): Select => new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST)->Event;
      // ! What the reactor still holds for a descriptor id
      $Holds = static function (Select $Loop, int $id): array {
         $held = [];
         foreach (['reads', 'writes', 'reading', 'writing', 'awaitingReads', 'awaitingWrites'] as $table) {
            $Table = new ReflectionProperty(Select::class, $table);
            if (isSet($Table->getValue($Loop)[$id])) {
               $held[] = $table;
            }
         }

         return $held;
      };

      try {
         yield assert(
            assertion: is_resource($Peer),
            description: 'the loopback peer is bound'
         );
         if (is_resource($Peer) === false) {
            return;
         }
         [$host, $port] = explode(':', (string) stream_socket_get_name($Peer, false));
         $dial = function (null|Select $Loop, null|Closure $Wait) use (&$Clients, $host, $port): array {
            $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
            if ($Loop !== null) {
               $Client->react($Loop);
            }
            if ($Wait !== null) {
               $Client->schedule($Wait);
            }
            $Client->configure(new Configs(host: $host, port: (int) $port));
            $Clients[] = $Client;
            $Socket = $Client->connect();
            /** @var Connection $Flow */
            $Flow = $Client->Connections->Flows[(int) $Socket];

            return [$Client, $Flow, $Socket];
         };

         // # stop() while a Fiber is parked on the flow
         $Loop = $Worker();
         [$Client, $Flow, $Socket] = $dial($Loop, $Bridge);
         $id = (int) $Socket;
         $disconnected = 0;
         $Client->on(Events::ClientDisconnect, static function () use (&$disconnected): void {
            $disconnected++;
         });
         $Outcome = null;
         $elapsed = null;
         $Fiber = new Fiber(function () use ($Flow, &$Outcome, &$elapsed): void {
            $started = hrtime(true);
            $Outcome = $Flow->receive(5.0);
            $elapsed = (hrtime(true) - $started) / 1e9;
         });
         $Loop->schedule($Fiber, $Fiber->start());
         $parked = $Holds($Loop, $id);
         $after = null;
         $alive = false;
         $now = microtime(true);
         $Loop->defer($now + 0.1, static function () use ($Client, $Flow, $Socket, $Loop, $Holds, $id, &$after): void {
            // ! A write registration too: the close must drop both
            $Loop->add($Socket, Select::EVENT_WRITE, $Flow);
            $Client->stop();
            $after = $Holds($Loop, $id);
         });
         $Loop->defer($now + 0.3, static function () use (&$alive): void {
            $alive = true;
         });
         $Loop->defer($now + 0.5, static function () use ($Loop): void {
            $Loop->destroy();
         });
         $Loop->loop();

         yield assert(
            assertion: $parked === ['reads', 'awaitingReads']
               && $Outcome === Outcomes::Closed
               && $elapsed !== null && $elapsed < 0.3
               && $alive === true,
            description: "a parked wait whose flow stop() closes returns Closed ({$elapsed}s) and the worker's loop keeps running"
         );
         yield assert(
            assertion: $after === []
               && is_resource($Socket) === false
               && $Client->Connections->Flows === []
               && $disconnected === 1,
            description: 'the closed flow leaves no registration nor waiter in the reactor (' . json_encode($after)
               . '), no registry entry and no open descriptor, and disconnects once'
         );

         // # Connections->close() by socket, and release() of a stale flow
         [$Client, $Flow, $Socket] = $dial(null, null);
         $Twin = new Connection($Socket, $Client);
         $Client->Connections->release($Twin);
         $kept = ($Client->Connections->Flows[(int) $Socket] ?? null) === $Flow;
         $closed = $Client->Connections->close($Socket);
         $again = $Client->Connections->close($Socket);
         yield assert(
            assertion: $kept && $closed === true && $again === false
               && is_resource($Socket) === false
               && $Client->Connections->Flows === [],
            description: 'release() of another flow under the same id keeps the registered one; Connections->close() closes and unregisters it'
         );

         // # A write disarms the flow's own reactor
         $Loop = $Worker();
         [$Client, $Flow, $Socket] = $dial($Loop, null);
         $Loop->add($Socket, Select::EVENT_WRITE, $Flow);
         $armed = $Holds($Loop, (int) $Socket);
         $Flow->output = 'ping';
         $Flow->writing($Socket);
         $disarmed = $Holds($Loop, (int) $Socket);
         yield assert(
            assertion: $armed === ['writes', 'writing'] && $disarmed === [] && $Flow->writes === 1,
            description: 'a datagram written on an embedded flow drops its write interest from the reactor the flow lives in: '
               . json_encode([$armed, $disarmed])
         );
         $Client->stop();

         // # A flow closed while it opens is reported as a failed dial
         $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
         $Clients[] = $Client;
         $Client->configure(new Configs(host: $host, port: (int) $port));
         $Client->on(Events::ClientConnect, static function ($Socket, Connection $Flow): void {
            $Flow->close();
         });
         $Socket = $Client->connect();
         yield assert(
            assertion: $Socket === false
               && $Client->Connections->failures === 1
               && $Client->Connections->Flows === [],
            description: 'connect() returns false when its flow does not survive registration, counted as a failure'
         );

         // # A disconnect hook that throws leaves no closed flow registered
         [$Client, $Flow, $Socket] = $dial(null, null);
         $Client->on(Events::ClientDisconnect, static function (): never {
            throw new RuntimeException('hook boom');
         });
         $thrown = null;
         try {
            $Client->stop();
         }
         catch (RuntimeException $Exception) {
            $thrown = $Exception->getMessage();
         }
         $adopted = true;
         try {
            $Client->react($Worker());
         }
         catch (LogicException) {
            $adopted = false;
         }
         yield assert(
            assertion: $thrown === 'hook boom'
               && is_resource($Socket) === false
               && $Client->Connections->Flows === []
               && $adopted,
            description: 'a throwing disconnect hook propagates, and the flow is already gone from the registry'
         );

         // # stop() closes every flow past a disconnect hook that throws
         [$Client, $FlowA, $SocketA] = $dial(null, null);
         $SocketB = $Client->connect();
         $hooks = 0;
         $Client->on(Events::ClientDisconnect, static function () use (&$hooks): never {
            $hooks++;
            throw new RuntimeException("hook boom {$hooks}");
         });
         $thrown = null;
         try {
            $Client->stop();
         }
         catch (RuntimeException $Exception) {
            $thrown = $Exception->getMessage();
         }
         yield assert(
            assertion: $thrown === 'hook boom 1' && $hooks === 2
               && is_resource($SocketA) === false && is_resource($SocketB) === false
               && $Client->Connections->Flows === [],
            description: "stop() closes every flow although each disconnect hook throws, then rethrows the first error ({$thrown})"
         );

         // # A connect hook that throws takes its socket with it
         $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
         $Clients[] = $Client;
         $Client->configure(new Configs(host: $host, port: (int) $port));
         $Client->on(Events::ClientConnect, static function (): never {
            throw new RuntimeException('connect hook boom');
         });
         $thrown = null;
         try {
            $Client->connect();
         }
         catch (RuntimeException $Exception) {
            $thrown = $Exception->getMessage();
         }
         $adopted = true;
         try {
            $Client->react($Worker());
         }
         catch (LogicException) {
            $adopted = false;
         }
         yield assert(
            assertion: $thrown === 'connect hook boom'
               && is_resource($Client->Socket) === false
               && $Client->Connections->Flows === []
               && $Client->Connections->failures === 1
               && $adopted,
            description: 'a throwing connect hook propagates, its socket closed and counted as a failed dial; the client may still adopt a reactor'
         );

         // # A connect hook that arms the reactor and throws leaves no registration
         $Loop = $Worker();
         $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
         $Clients[] = $Client;
         $Client->react($Loop);
         $Client->configure(new Configs(host: $host, port: (int) $port));
         $armedId = null;
         $Client->on(Events::ClientConnect, static function ($Socket, Connection $Flow) use ($Loop, &$armedId): never {
            $armedId = (int) $Socket;
            $Loop->add($Socket, Select::EVENT_WRITE, $Flow);
            $Loop->add($Socket, Select::EVENT_READ, $Flow);
            throw new RuntimeException('armed boom');
         });
         $thrown = null;
         try {
            $Client->connect();
         }
         catch (RuntimeException $Exception) {
            $thrown = $Exception->getMessage();
         }
         $left = $armedId === null ? null : $Holds($Loop, $armedId);
         yield assert(
            assertion: $thrown === 'armed boom' && $left === [] && is_resource($Client->Socket) === false,
            description: 'a connect hook that arms the reactor and throws leaves no registration on its closed socket: '
               . json_encode($left)
         );

         // # A dial nested in a connect hook keeps the outer dial in progress
         $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
         $Clients[] = $Client;
         $Client->configure(new Configs(host: $host, port: (int) $port));
         $depth = 0;
         $refused = null;
         $Client->on(Events::ClientConnect, static function () use ($Client, $Worker, &$depth, &$refused): void {
            $depth++;
            // ? The inner dial's hook fails
            if ($depth === 2) {
               throw new RuntimeException('inner boom');
            }
            try {
               $Client->connect();
            }
            catch (RuntimeException) {
               // ...
            }
            // @ The outer dial is still opening its flow
            try {
               $Client->react($Worker());
               $refused = false;
            }
            catch (LogicException) {
               $refused = true;
            }
         });
         $Socket = $Client->connect();
         yield assert(
            assertion: $depth === 2 && $refused === true && $Client->owned === true && is_resource($Socket),
            description: 'react() stays refused in the connect hook of an outer dial after a nested dial has unwound'
         );

         // # Adoption stays refused while any flow is open
         [$Client, $FlowA, $SocketA] = $dial(null, null);
         $SocketB = $Client->connect();
         $Client->Connections->close($SocketB);
         $refused = false;
         try {
            $Client->react($Worker());
         }
         catch (LogicException) {
            $refused = true;
         }
         yield assert(
            assertion: $refused && is_resource($SocketA) && $Client->owned,
            description: 'react() is refused while an older flow is still open, even after the last one closed'
         );

         // # A connect hook is too late to adopt a reactor
         $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
         $Clients[] = $Client;
         $Client->configure(new Configs(host: $host, port: (int) $port));
         $refused = null;
         $Client->on(Events::ClientConnect, static function () use ($Client, $Worker, &$refused): void {
            try {
               $Client->react($Worker());
            }
            catch (LogicException $Exception) {
               $refused = $Exception->getMessage();
            }
         });
         $Socket = $Client->connect();
         yield assert(
            assertion: $refused !== null && $Client->owned === true && is_resource($Socket),
            description: 'react() from the connect hook of a dial in progress is refused, the client keeps its reactor'
         );

         // # An exception from the bridge unwinds the wait and closes its flow
         [$Client, $Flow, $Socket] = $dial($Worker(), static function (): never {
            throw new RuntimeException('host shutting down');
         });
         $thrown = null;
         $Fiber = new Fiber(function () use ($Flow, &$thrown): void {
            try {
               $Flow->receive(1.0);
            }
            catch (RuntimeException $Exception) {
               $thrown = $Exception->getMessage();
            }
         });
         $Fiber->start();
         yield assert(
            assertion: $thrown === 'host shutting down'
               && is_resource($Socket) === false
               && $Client->Connections->Flows === [],
            description: 'a foreign exception from the bridge propagates, and the abandoned wait closed its flow'
         );

         // # A Fiber collected mid-wait closes its flow
         [$Client, $Flow, $Socket] = $dial($Worker(), $Bridge);
         $Fiber = new Fiber(function () use ($Flow): void {
            $Flow->receive(5.0);
         });
         $Parked = $Fiber->start();
         $waited = $Parked instanceof Readiness;
         $Fiber = null;
         $Parked = null;
         gc_collect_cycles();
         yield assert(
            assertion: $waited
               && is_resource($Socket) === false
               && $Client->Connections->Flows === [],
            description: 'a waiting Fiber destroyed by its host closes the flow it waited on'
         );

         // # A bridge that stops suspending trips the wait
         // ! Eight consecutive fast wakes trip it; a wake a busy host slows
         //   past the threshold restarts the count, so a run is retried
         //   until one is undisturbed (exactly eight calls)
         $Runs = [];
         for ($run = 0; $run < 3; $run++) {
            $calls = 0;
            [$Client, $Flow, $Socket] = $dial($Worker(), static function () use (&$calls): mixed {
               $calls++;

               return null;
            });
            $Outcome = null;
            $elapsed = null;
            $Fiber = new Fiber(function () use ($Flow, &$Outcome, &$elapsed): void {
               $started = hrtime(true);
               $Outcome = $Flow->receive(5.0);
               $elapsed = (hrtime(true) - $started) / 1e9;
            });
            $Fiber->start();
            $Runs[] = [
               'outcome' => $Outcome?->name,
               'calls' => $calls,
               'elapsed' => $elapsed,
               'open' => is_resource($Socket),
            ];
            if ($calls === 8) {
               break;
            }
         }
         $tripped = true;
         foreach ($Runs as $Run) {
            $tripped = $tripped && $Run['outcome'] === 'Stalled' && $Run['calls'] >= 8
               && $Run['elapsed'] !== null && $Run['elapsed'] < 0.1 && $Run['open'];
         }
         yield assert(
            assertion: $tripped && $Runs[count($Runs) - 1]['calls'] === 8,
            description: 'a non-suspending bridge trips the wait after 8 fast wakes, at once, the flow left open: '
               . json_encode($Runs)
         );
      }
      finally {
         // @ Cleanup
         foreach ($Clients as $Client) {
            try {
               $Client->stop();
            }
            catch (Throwable) {
               // ...
            }
         }
         if (is_resource($Peer)) {
            fclose($Peer);
         }
      }
   }
);
