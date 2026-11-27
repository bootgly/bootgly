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


use const BOOTGLY_STORAGE_DIR;
use const STREAM_SERVER_BIND;
use function assert;
use function explode;
use function fclose;
use function is_dir;
use function is_resource;
use function json_encode;
use function scandir;
use function spl_object_id;
use function str_contains;
use function stream_select;
use function stream_socket_get_name;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function stream_socket_server;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;


/**
 * An embedded client never reads or writes a static: not at construction, not
 * through a whole lifecycle (hooks, dial, send, receive, close, stop). Every
 * static of the legacy API holds a sentinel the embedded client would
 * overwrite, reset or fire — and every sentinel survives. A legacy client built
 * before it keeps its process-wide reactor. The embedded refusals (workers,
 * WorkerStarted, start(), react() outside embedded or after a dial) are named.
 */
return new Test(
   description: 'UDP_Client_CLI: an embedded client keeps out of every static and of the host process',
   test: function () {
      $Client = new ReflectionClass(UDP_Client_CLI::class);
      $Registry = new ReflectionClass(Connections::class);
      // ! Every static of the legacy API, and its value before this case
      $statics = [
         [$Client, 'Event'], [$Client, 'onWorkerStarted'], [$Client, 'onClientConnect'],
         [$Client, 'onClientDisconnect'], [$Client, 'onDatagramRead'], [$Client, 'onDatagramWrite'],
         [$Client, 'started'], [$Client, 'status'],
         [$Registry, 'Connections'], [$Registry, 'stats'], [$Registry, 'errors'],
         [$Registry, 'writes'], [$Registry, 'reads'], [$Registry, 'written'], [$Registry, 'read'],
      ];
      $Saved = [];
      foreach ($statics as [$Class, $name]) {
         $Property = $Class->getProperty($name);
         if ($Property->isInitialized()) {
            $Saved[] = [$Property, $Property->getValue()];
         }
      }
      $debug = Vars::$debug;
      $print = Vars::$print;
      $exit = Vars::$exit;
      $pids = BOOTGLY_STORAGE_DIR . 'pids/';
      $entries = is_dir($pids) ? (array) scandir($pids) : [];
      $Peer = null;

      try {
         // @ A legacy client first: it owns the process-wide reactor
         $Legacy = new UDP_Client_CLI(UDP_Client_CLI::MODE_TEST);
         $Shared = UDP_Client_CLI::$Event;
         // ! A foreign host reactor (its legacy constructor writes the debugging
         //   vars, so it is built before the sentinels)
         $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);

         // ! Sentinels — what an embedded client would overwrite, reset or fire
         $fired = [];
         $Recorder = static function (string $name) use (&$fired): \Closure {
            return static function () use ($name, &$fired): void {
               $fired[] = $name;
            };
         };
         UDP_Client_CLI::$onWorkerStarted = $Recorder('workerStarted');
         UDP_Client_CLI::$onClientConnect = $Recorder('clientConnect');
         UDP_Client_CLI::$onClientDisconnect = $Recorder('clientDisconnect');
         UDP_Client_CLI::$onDatagramRead = $Recorder('datagramRead');
         UDP_Client_CLI::$onDatagramWrite = $Recorder('datagramWrite');
         $Client->setStaticPropertyValue('started', 1001);
         $Client->setStaticPropertyValue('status', 1002);
         Connections::$Connections = [];
         Connections::$stats = true;
         Connections::$errors = ['connection' => 1003, 'write' => 1004, 'read' => 1005];
         Connections::$writes = 1006;
         Connections::$reads = 1007;
         Connections::$written = 1008;
         Connections::$read = 1009;
         Vars::$debug = false;
         Vars::$print = false;
         Vars::$exit = true;
         $Snapshot = static fn (): string => (string) json_encode([
            'hooks' => [
               spl_object_id(UDP_Client_CLI::$onWorkerStarted), spl_object_id(UDP_Client_CLI::$onClientConnect),
               spl_object_id(UDP_Client_CLI::$onClientDisconnect), spl_object_id(UDP_Client_CLI::$onDatagramRead),
               spl_object_id(UDP_Client_CLI::$onDatagramWrite),
            ],
            'started' => $Client->getStaticPropertyValue('started'),
            'status' => $Client->getStaticPropertyValue('status'),
            'Connections' => Connections::$Connections,
            'stats' => Connections::$stats,
            'errors' => Connections::$errors,
            'counters' => [Connections::$writes, Connections::$reads, Connections::$written, Connections::$read],
            'Vars' => [Vars::$debug, Vars::$print, Vars::$exit],
         ]);
         $before = $Snapshot();

         // @ Embedded construction
         $Embedded = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
         yield assert(
            assertion: $Snapshot() === $before && UDP_Client_CLI::$Event === $Shared,
            description: 'constructing an embedded client changes no static, no debugging var, and the legacy client keeps its reactor'
         );
         yield assert(
            assertion: $Embedded->embedded === true
               && $Legacy->embedded === false
               && $Embedded->Reactor instanceof Select
               && $Embedded->Reactor !== $Shared
               && $Legacy->Reactor === null
               && $Embedded->owned === true,
            description: 'the embedded client owns its own reactor; a legacy client has none on its instance'
         );
         yield assert(
            assertion: (is_dir($pids) ? (array) scandir($pids) : []) === $entries,
            description: 'an embedded client writes no process state'
         );

         // @ Refusals by name
         $refusals = [];
         try {
            $Embedded->configure(new Configs(host: '127.0.0.1', port: 9, workers: 2));
         }
         catch (InvalidArgumentException $Exception) {
            $refusals['workers'] = $Exception->getMessage();
         }
         foreach ([1, 2] as $attempt) {
            try {
               $Embedded->on(Events::WorkerStarted, static function (): void {});
            }
            catch (LogicException $Exception) {
               $refusals["workerStarted{$attempt}"] = $Exception->getMessage();
            }
         }
         try {
            $Embedded->start();
         }
         catch (LogicException $Exception) {
            $refusals['start'] = $Exception->getMessage();
         }
         try {
            $Legacy->react($Host->Event);
         }
         catch (LogicException $Exception) {
            $refusals['legacyReact'] = $Exception->getMessage();
         }
         yield assert(
            assertion: isSet(
               $refusals['workers'], $refusals['workerStarted1'], $refusals['workerStarted2'],
               $refusals['start'], $refusals['legacyReact']
            )
               // ! The second attempt is refused for the mode again, never as "already registered"
               && str_contains($refusals['workerStarted2'], 'MODE_EMBEDDED')
               && $Legacy->Reactor === null
               && UDP_Client_CLI::$Event === $Shared,
            description: 'workers, WorkerStarted (never marked as taken), start() and a legacy react() are refused by name: '
               . json_encode($refusals)
         );

         // @ A whole embedded lifecycle — hooks, dial, send, receive, close, stop
         $Peer = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         yield assert(
            assertion: is_resource($Peer),
            description: 'the loopback peer is bound'
         );
         if (is_resource($Peer) === false) {
            return;
         }
         [$host, $port] = explode(':', (string) stream_socket_get_name($Peer, false));
         $Embedded->configure(new Configs(host: $host, port: (int) $port));
         $own = [];
         foreach (
            [Events::ClientConnect, Events::ClientDisconnect, Events::DatagramRead, Events::DatagramWrite] as $Event
         ) {
            $Embedded->on($Event, static function () use ($Event, &$own): void {
               $own[] = $Event->value;
            });
         }
         $Socket = $Embedded->connect();
         $adopted = null;
         try {
            $Embedded->react($Host->Event);
         }
         catch (LogicException $Exception) {
            $adopted = $Exception->getMessage();
         }
         $Flow = is_resource($Socket) ? ($Embedded->Connections->Flows[(int) $Socket] ?? null) : null;
         yield assert(
            assertion: $Flow !== null && $adopted !== null && $Embedded->owned === true,
            description: 'the dial registers an instance flow, and react() is refused once a flow is open'
         );
         if ($Flow === null) {
            return;
         }

         $Flow->output = 'ping';
         $Flow->writing($Socket);
         $read = [$Peer];
         $write = null;
         $except = null;
         stream_select($read, $write, $except, 1);
         $datagram = stream_socket_recvfrom($Peer, 100, 0, $address);
         stream_socket_sendto($Peer, 'pong', 0, $address);
         $Outcome = $Flow->receive(1.0);
         // ! A failing read is counted on the flow, never on the statics
         $Flow->fail($Socket, 'read', false);
         $Embedded->stop();

         yield assert(
            assertion: $datagram === 'ping'
               && $Outcome === Outcomes::Received
               && $Flow->input === 'pong'
               && $Flow->writes === 1
               && $Flow->reads === 1
               && $Flow->errors['read'] === 1
               && $Embedded->Connections->Flows === []
               && is_resource($Socket) === false,
            description: 'the flow sends and receives, counts on itself, and stop() closes it and returns'
         );
         yield assert(
            assertion: $own === ['clientConnect', 'datagramWrite', 'datagramRead', 'clientDisconnect']
               && $fired === [],
            description: 'only the instance hooks fire (' . json_encode($own) . '), never the static ones ('
               . json_encode($fired) . ')'
         );
         yield assert(
            assertion: $Snapshot() === $before && UDP_Client_CLI::$Event === $Shared,
            description: 'after the whole lifecycle every static still holds its sentinel: ' . $Snapshot()
         );
      }
      finally {
         // @ Restore the process state
         if (is_resource($Peer)) {
            fclose($Peer);
         }
         foreach ($Saved as [$Property, $value]) {
            try {
               $Property->setValue(null, $value);
            }
            catch (Throwable) {
               // ...
            }
         }
         Vars::$debug = $debug;
         Vars::$print = $print;
         Vars::$exit = $exit;
      }
   }
);
