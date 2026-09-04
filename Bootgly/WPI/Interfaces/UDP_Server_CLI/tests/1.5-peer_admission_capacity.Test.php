<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Reset as TimerReset;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Endpoints\Servers\Decoder;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;


/**
 * Security regression H7 — UDP port churn must remain inside finite peer,
 * per-IP, timer and per-dispatch ceilings even when stats are disabled.
 *
 * Every attack datagram crosses a real loopback UDP socket and the production
 * Router::reading() → Connections::accept() → Connection → Decoder path.
 * Small configured limits keep the proof harmless: the vulnerable baseline
 * retains 64 peer objects and 64 timer callbacks; the secure contract retains
 * only eight peers behind one central expiration task.
 */
return new Test(
   description: 'UDP peer churn must remain inside admission, timer and dispatch ceilings',
   test: new Assertions(Case: function (): Generator {
      $segments = Display::$segments;
      $PreviousDecoder = UDP_Server_CLI::$Decoder;
      $PreviousEncoder = UDP_Server_CLI::$Encoder;
      $PreviousAlarm = pcntl_signal_get_handler(SIGALRM);
      Timer::init(static function (): void {});

      $configuration = [
         'maxConnections',
         'maxConnectionsPerIP',
         'connectionIdleTimeout',
         'maxDatagramsPerTick',
      ];
      $supported = true;
      $defaults = [];
      $Constructor = new ReflectionMethod(Configs::class, '__construct');
      $Parameters = [];
      foreach ($Constructor->getParameters() as $Parameter) {
         $Parameters[$Parameter->getName()] = $Parameter;
      }
      foreach ($configuration as $name) {
         $Parameter = $Parameters[$name] ?? null;
         if ($Parameter instanceof ReflectionParameter === false) {
            $supported = false;
            continue;
         }
         $defaults[$name] = $Parameter->getDefaultValue();
      }

      $IPProperty = property_exists(Connections::class, 'IPConnections')
         ? new ReflectionProperty(Connections::class, 'IPConnections')
         : null;
      $PeersProperty = new ReflectionProperty(Connections::class, 'Peers');
      $PendingProperty = new ReflectionProperty(Lease::class, 'Pending');
      $TasksProperty = new ReflectionProperty(Timer::class, 'tasks');
      $ManagerReset = new ReflectionProperty(Connections::class, 'resetObserver');
      $DirectReset = new ReflectionProperty(Connection::class, 'resetObserver');
      $ResetObservers = new ReflectionProperty(TimerReset::class, 'Observers');

      $Count = static function () use ($TasksProperty): int {
         $count = 0;
         foreach ($TasksProperty->getValue() as $tasks) {
            $count += count($tasks);
         }

         return $count;
      };
      $Backdate = static function () use ($TasksProperty): void {
         $due = [];
         foreach ($TasksProperty->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }

         $TasksProperty->setValue(null, $due === [] ? [] : [time() - 1 => $due]);
      };
      $IPs = static function () use ($IPProperty): array {
         return $IPProperty instanceof ReflectionProperty
            ? $IPProperty->getValue()
            : [];
      };
      $usedPeers = [];
      $Source = static function (string $IP) use (&$usedPeers): array {
         for ($attempt = 0; $attempt < 256; $attempt++) {
            $SourceSocket = @stream_socket_server(
               "udp://{$IP}:0", $code, $message, STREAM_SERVER_BIND
            );
            if ($SourceSocket === false) {
               throw new RuntimeException("Could not bind H7 source {$IP}: {$message}");
            }
            stream_set_blocking($SourceSocket, false);

            $peer = stream_socket_get_name($SourceSocket, false);
            if (
               is_string($peer)
               && $peer !== ''
               && isSet($usedPeers[$peer]) === false
            ) {
               $usedPeers[$peer] = true;

               return [$SourceSocket, $peer];
            }

            fclose($SourceSocket);
         }

         throw new RuntimeException("Could not allocate a unique H7 source for {$IP}.");
      };
      $Drain = static function ($Socket, Connections $Connections): int {
         $read = [$Socket];
         $write = null;
         $except = null;
         $selected = @stream_select($read, $write, $except, 0, 200_000);
         if ($selected === 1) {
            $Connections->Router->reading($Socket);
         }

         return (int) $selected;
      };
      $Send = static function (
         $SourceSocket,
         string $target,
         string $payload,
         $ServerSocket,
         Connections $Connections,
      ) use ($Drain): bool {
         $sent = @stream_socket_sendto($SourceSocket, $payload, 0, $target);
         if ($sent !== strlen($payload)) {
            return false;
         }

         return $Drain($ServerSocket, $Connections) === 1;
      };
      $Build = static function (
         int $max,
         int $perIP,
         int $idle,
         int $batch,
         int &$handled,
      ) use ($supported): array {
         $arguments = [
            'host' => '127.0.0.1',
            'port' => 0,
            'workers' => 1,
         ];
         if ($supported) {
            $arguments['maxConnections'] = $max;
            $arguments['maxConnectionsPerIP'] = $perIP;
            $arguments['connectionIdleTimeout'] = $idle;
            $arguments['maxDatagramsPerTick'] = $batch;
         }
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new Configs(...$arguments));
         UDP_Server_CLI::$Decoder = new class(
            static function () use (&$handled): void {
               $handled++;
            }
         ) implements Decoder {
            // * Data
            private Closure $Handler;

            /** @param Closure $Handler Counter callback for accepted datagrams. */
            public function __construct (Closure $Handler)
            {
               $this->Handler = $Handler;
            }

            /** {@inheritDoc} */
            public function decode (
               ServerPackages $Package, string $buffer, int $size
            ): States
            {
               ($this->Handler)();

               return States::Incomplete;
            }
         };

         $ServerSocket = @stream_socket_server(
            'udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND
         );
         if ($ServerSocket === false) {
            throw new RuntimeException("Could not bind H7 server: {$message}");
         }
         stream_set_blocking($ServerSocket, false);

         $SocketProperty = new ReflectionProperty($Server, 'Socket');
         $SocketProperty->setValue($Server, $ServerSocket);

         return [$Server, $ServerSocket, $Server->Connections];
      };
      $Close = static function (array &$sources, $ServerSocket) use (
         $IPProperty,
         $PeersProperty,
         $PendingProperty,
         $ManagerReset,
         $DirectReset,
         $ResetObservers,
         $TasksProperty,
      ): void {
         foreach (array_values(Connections::$Connections) as $Connection) {
            $Connection->close();
         }
         Connections::$Connections = [];
         Connections::$blacklist = [];
         unset($Connection);
         gc_collect_cycles();
         Lease::drain();
         Timer::del();
         gc_collect_cycles();
         Lease::drain();
         $remainingAlarm = pcntl_alarm(0);

         $peers = $PeersProperty->isInitialized()
            ? $PeersProperty->getValue()
            : [];
         $IPs = $IPProperty instanceof ReflectionProperty
            && $IPProperty->isInitialized()
            ? $IPProperty->getValue()
            : [];
         $Pending = $PendingProperty->isInitialized()
            ? $PendingProperty->getValue()
            : [];
         if (
            $peers !== []
            || $IPs !== []
            || $Pending !== []
            || $TasksProperty->getValue() !== []
            || $ManagerReset->getValue() !== 0
            || $DirectReset->getValue() !== 0
            || $ResetObservers->getValue() !== []
            || $remainingAlarm !== 0
         ) {
            throw new RuntimeException('H7 teardown left peer, lease or timer state.');
         }

         foreach ($sources as $SourceSocket) {
            if (is_resource($SourceSocket)) {
               @fclose($SourceSocket);
            }
         }
         $sources = [];

         if (is_resource($ServerSocket)) {
            @fclose($ServerSocket);
         }
      };

      $evidence = [
         'fixture_error' => '',
         'supported' => $supported,
         'defaults' => $defaults,
         'instance_nonblocking' => false,
         'global' => [],
         'per_ip' => [],
         'idle' => [],
         'batch' => [],
         'controls' => [],
      ];
      $sources = [];
      $ServerSocket = null;

      Display::show(Display::NONE);
      UDP_Server_CLI::$Decoder = null;
      UDP_Server_CLI::$Encoder = null;

      try {
         // # Real instance control — a production UDP listener must be
         //   nonblocking before Router::reading() drains it.
         $Probe = new UDP_Server_CLI(Modes::Test);
         $Probe->configure(new Configs(host: '127.0.0.1', port: 0, workers: 1));
         $ProbeSocket = $Probe->instance();
         $metadata = is_resource($ProbeSocket)
            ? stream_get_meta_data($ProbeSocket)
            : [];
         $evidence['instance_nonblocking'] = ($metadata['blocked'] ?? true) === false;
         $Probe->close();

         // # Global ceiling — 64 real source ports against a configured cap 8.
         Timer::del();
         $handled = 0;
         [$Server, $ServerSocket, $Connections] = $Build(8, 0, 30, 128, $handled);
         $target = stream_socket_get_name($ServerSocket, false);
         if (is_string($target) === false) {
            throw new RuntimeException('Could not identify H7 global target.');
         }
         $usedPeers[$target] = true;

         $peers = [];
         $sent = 0;
         for ($index = 0; $index < 64; $index++) {
            [$SourceSocket, $peer] = $Source('127.0.0.1');
            $sources[] = $SourceSocket;
            $peers[] = $peer;
            $sent += $Send(
               $SourceSocket,
               $target,
               "H7-global-{$index}",
               $ServerSocket,
               $Connections,
            ) ? 1 : 0;
         }
         $beforeHealth = [count(Connections::$Connections), $handled, $Count()];
         $healthy = $Send(
            $sources[0],
            $target,
            'H7-global-health',
            $ServerSocket,
            $Connections,
         );
         $evidence['global'] = [
            'sent' => $sent,
            'live' => count(Connections::$Connections),
            'handled' => $handled,
            'tasks' => $Count(),
            'connection_errors' => Connections::$errors['connection'],
            'read_errors' => Connections::$errors['read'],
            'first_registered' => isset(Connections::$Connections[$peers[0]]),
            'health_sent' => $healthy,
            'health_delta' => [
               count(Connections::$Connections) - $beforeHealth[0],
               $handled - $beforeHealth[1],
               $Count() - $beforeHealth[2],
            ],
         ];
         $Close($sources, $ServerSocket);
         $ServerSocket = null;

         // # Per-IP ceiling + balanced release/readmission.
         $handled = 0;
         [$Server, $ServerSocket, $Connections] = $Build(8, 3, 30, 16, $handled);
         $target = stream_socket_get_name($ServerSocket, false);
         if (is_string($target) === false) {
            throw new RuntimeException('Could not identify H7 per-IP target.');
         }
         $usedPeers[$target] = true;
         $peers = [];
         $sent = 0;
         for ($index = 0; $index < 4; $index++) {
            [$SourceSocket, $peer] = $Source('127.0.0.1');
            $sources[] = $SourceSocket;
            $peers[] = $peer;
            $sent += $Send(
               $SourceSocket,
               $target,
               "H7-per-IP-{$index}",
               $ServerSocket,
               $Connections,
            ) ? 1 : 0;
         }
         $closed = $Connections->close($peers[1]);
         [$Replacement, $replacementPeer] = $Source('127.0.0.1');
         $sources[] = $Replacement;
         $replacementSent = $Send(
            $Replacement,
            $target,
            'H7-per-IP-replacement',
            $ServerSocket,
            $Connections,
         );
         $evidence['per_ip'] = [
            'sent' => $sent,
            'closed' => $closed,
            'replacement_sent' => $replacementSent,
            'replacement_registered' => isset(Connections::$Connections[$replacementPeer]),
            'live' => count(Connections::$Connections),
            'handled' => $handled,
            'tasks' => $Count(),
            'IP_connections' => $IPs(),
            'connection_errors' => Connections::$errors['connection'],
            'read_errors' => Connections::$errors['read'],
         ];
         $Close($sources, $ServerSocket);
         $ServerSocket = null;

         // # Expiration is a protection, not a stats feature. A fresh datagram
         //   renews liveness; a genuinely idle peer is swept and its slot reused.
         $handled = 0;
         [$Server, $ServerSocket, $Connections] = $Build(8, 3, 30, 16, $handled);
         Connections::$stats = false;
         $target = stream_socket_get_name($ServerSocket, false);
         if (is_string($target) === false) {
            throw new RuntimeException('Could not identify H7 idle target.');
         }
         $usedPeers[$target] = true;
         [$First, $firstPeer] = $Source('127.0.0.1');
         $sources[] = $First;
         $firstSent = $Send(
            $First, $target, 'H7-idle-first', $ServerSocket, $Connections
         );
         $FirstConnection = Connections::$Connections[$firstPeer] ?? null;
         $FirstWeak = $FirstConnection instanceof Connection
            ? WeakReference::create($FirstConnection)
            : null;
         $firstTasks = $Count();
         $connectionTimers = $FirstConnection?->timers ?? [];

         if ($FirstConnection !== null) {
            $FirstConnection->used = time() - 31;
         }
         $refreshSent = $Send(
            $First, $target, 'H7-idle-refresh', $ServerSocket, $Connections
         );
         $Backdate();
         Timer::tick();
         $aliveAfterRefresh = isset(Connections::$Connections[$firstPeer]);

         if ($FirstConnection !== null) {
            $FirstConnection->used = time() - 31;
         }
         unset($FirstConnection);
         $Backdate();
         Timer::tick();
         $liveAfterExpiry = count(Connections::$Connections);
         $tasksAfterExpiry = $Count();
         $destroyedAfterExpiry = $FirstWeak?->get() === null;

         [$Second, $secondPeer] = $Source('127.0.0.1');
         $sources[] = $Second;
         $secondSent = $Send(
            $Second, $target, 'H7-idle-second', $ServerSocket, $Connections
         );
         $evidence['idle'] = [
            'first_sent' => $firstSent,
            'refresh_sent' => $refreshSent,
            'first_tasks' => $firstTasks,
            'connection_timers' => $connectionTimers,
            'alive_after_refresh' => $aliveAfterRefresh,
            'live_after_expiry' => $liveAfterExpiry,
            'tasks_after_expiry' => $tasksAfterExpiry,
            'destroyed_after_expiry' => $destroyedAfterExpiry,
            'second_sent' => $secondSent,
            'second_registered' => isset(Connections::$Connections[$secondPeer]),
            'live_after_reuse' => count(Connections::$Connections),
            'tasks_after_reuse' => $Count(),
            'IP_connections' => $IPs(),
         ];
         $Close($sources, $ServerSocket);
         $ServerSocket = null;

         // # Fairness — one readiness turn processes at most the configured
         //   batch; the next turn drains the queued suffix.
         $handled = 0;
         [$Server, $ServerSocket, $Connections] = $Build(8, 0, 30, 2, $handled);
         $target = stream_socket_get_name($ServerSocket, false);
         if (is_string($target) === false) {
            throw new RuntimeException('Could not identify H7 batch target.');
         }
         $usedPeers[$target] = true;
         $sent = 0;
         foreach (['127.0.0.3', '127.0.0.4', '127.0.0.5'] as $IP) {
            [$SourceSocket] = $Source($IP);
            $sources[] = $SourceSocket;
            $payload = "H7-batch-{$IP}";
            $sent += @stream_socket_sendto($SourceSocket, $payload, 0, $target)
               === strlen($payload) ? 1 : 0;
         }
         $firstSelected = $Drain($ServerSocket, $Connections);
         $firstHandled = $handled;
         $secondSelected = $Drain($ServerSocket, $Connections);
         $evidence['batch'] = [
            'sent' => $sent,
            'first_selected' => $firstSelected,
            'first_handled' => $firstHandled,
            'second_selected' => $secondSelected,
            'second_delta' => $handled - $firstHandled,
            'live' => count(Connections::$Connections),
            'tasks' => $Count(),
         ];
      }
      catch (Throwable $Throwable) {
         $class = $Throwable::class;
         $message = $Throwable->getMessage();
         $evidence['fixture_error'] = "{$class}: {$message}";
      }
      finally {
         $Close($sources, $ServerSocket);
         Connections::$stats = false;

         if ($IPProperty instanceof ReflectionProperty) {
            $IPProperty->setValue(null, []);
         }
         UDP_Server_CLI::$Decoder = $PreviousDecoder;
         UDP_Server_CLI::$Encoder = $PreviousEncoder;
         pcntl_signal(SIGALRM, $PreviousAlarm === false ? SIG_DFL : $PreviousAlarm);
         Display::show($segments);
      }

      $controls = $evidence['fixture_error'] === ''
         && ($evidence['global']['sent'] ?? 0) === 64
         && ($evidence['global']['first_registered'] ?? false) === true
         && ($evidence['global']['health_sent'] ?? false) === true
         && ($evidence['per_ip']['sent'] ?? 0) === 4
         && ($evidence['per_ip']['closed'] ?? false) === true
         && ($evidence['per_ip']['replacement_sent'] ?? false) === true
         && ($evidence['idle']['first_sent'] ?? false) === true
         && ($evidence['idle']['refresh_sent'] ?? false) === true
         && ($evidence['idle']['second_sent'] ?? false) === true
         && ($evidence['batch']['sent'] ?? 0) === 3
         && ($evidence['batch']['first_selected'] ?? 0) === 1;
      $evidence['controls'] = ['passed' => $controls];
      $JSON = (string) json_encode($evidence);

      yield new Assertion(
         description: 'H7 harness sends real datagrams and keeps an admitted peer healthy',
         fallback: "H7 harness/control failed: {$JSON}"
      )
         ->expect($controls)
         ->to->be(true)
         ->assert();

      $defaultsFinite = $supported
         && ($defaults['maxConnections'] ?? 0) > 0
         && ($defaults['maxConnectionsPerIP'] ?? 0) > 0
         && ($defaults['connectionIdleTimeout'] ?? 0) > 0
         && ($defaults['maxDatagramsPerTick'] ?? 0) > 0;
      $secure = $defaultsFinite
         && $evidence['instance_nonblocking'] === true
         && ($evidence['global']['live'] ?? -1) === 8
         && ($evidence['global']['handled'] ?? -1) === 9
         && ($evidence['global']['tasks'] ?? -1) === 1
         && ($evidence['global']['connection_errors'] ?? -1) === 56
         && ($evidence['global']['read_errors'] ?? -1) === 56
         && ($evidence['global']['health_delta'] ?? null) === [0, 1, 0]
         && ($evidence['per_ip']['live'] ?? -1) === 3
         && ($evidence['per_ip']['handled'] ?? -1) === 4
         && ($evidence['per_ip']['tasks'] ?? -1) === 1
         && ($evidence['per_ip']['connection_errors'] ?? -1) === 1
         && ($evidence['per_ip']['read_errors'] ?? -1) === 1
         && ($evidence['per_ip']['IP_connections']['127.0.0.1'] ?? -1) === 3
         && ($evidence['idle']['first_tasks'] ?? -1) === 1
         && ($evidence['idle']['connection_timers'] ?? null) === []
         && ($evidence['idle']['alive_after_refresh'] ?? false) === true
         && ($evidence['idle']['live_after_expiry'] ?? -1) === 0
         && ($evidence['idle']['tasks_after_expiry'] ?? -1) === 0
         && ($evidence['idle']['destroyed_after_expiry'] ?? false) === true
         && ($evidence['idle']['second_registered'] ?? false) === true
         && ($evidence['idle']['live_after_reuse'] ?? -1) === 1
         && ($evidence['idle']['tasks_after_reuse'] ?? -1) === 1
         && ($evidence['idle']['IP_connections']['127.0.0.1'] ?? -1) === 1
         && ($evidence['batch']['first_handled'] ?? -1) === 2
         && ($evidence['batch']['second_selected'] ?? -1) === 1
         && ($evidence['batch']['second_delta'] ?? -1) === 1
         && ($evidence['batch']['live'] ?? -1) === 3
         && ($evidence['batch']['tasks'] ?? -1) === 1;

      yield new Assertion(
         description: 'UDP port churn stays inside configured peer, timer and dispatch ceilings',
         fallback: "CONFIRMED H7: UDP port churn retained peers/timers beyond configured ceilings or disabled idle/fairness protection; evidence={$JSON}"
      )
         ->expect($secure, Op::Identical, true)
         ->assert();
   })
);
