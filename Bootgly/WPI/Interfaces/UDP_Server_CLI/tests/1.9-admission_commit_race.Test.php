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
use Bootgly\ACI\Events\Timer\Registry as TimerRegistry;
use Bootgly\ACI\Events\Timer\Reset as TimerReset;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Authority as ConnectionAuthority;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;


return new Test(
   description: 'UDP admission commit must remain atomic under async reentry',
   skip: function_exists('pcntl_fork') === false
      || function_exists('pcntl_waitpid') === false
      || function_exists('pcntl_async_signals') === false
      || function_exists('posix_kill') === false
      || function_exists('posix_setpgid') === false
      || function_exists('stream_socket_pair') === false,
   test: new Assertions(Case: function (): Generator {
      $PreviousAlarm = pcntl_signal_get_handler(SIGALRM);
      Timer::init(static function (): void {});
      Timer::del();

      $Socket = stream_socket_server(
         'udp://127.0.0.1:0',
         $socketCode,
         $socketMessage,
         STREAM_SERVER_BIND,
      );
      yield new Assertion(description: 'commit-race UDP socket is bound')
         ->expect($Socket !== false)
         ->to->be(true)
         ->assert();
      if ($Socket === false) {
         Timer::del();
         pcntl_signal(
            SIGALRM,
            $PreviousAlarm === false ? SIG_DFL : $PreviousAlarm,
         );
         return;
      }

      $SocketProperty = new ReflectionProperty(UDP_Server_CLI::class, 'Socket');
      $Peers = new ReflectionProperty(Connections::class, 'Peers');
      $IPConnections = new ReflectionProperty(Connections::class, 'IPConnections');
      $Authorities = new ReflectionProperty(Connection::class, 'Authorities');
      $GenerationBuckets = new ReflectionProperty(Connection::class, 'GenerationBuckets');
      $Closed = new ReflectionProperty(Connection::class, 'closed');
      $Quarantines = new ReflectionProperty(Connection::class, 'Quarantines');
      $DirectQuarantines = new ReflectionProperty(
         Connection::class,
         'DirectQuarantines',
      );
      $ConnectionQuarantineTimer = new ReflectionProperty(
         Connection::class,
         'quarantineTimer',
      );
      $ConnectionResetObserver = new ReflectionProperty(
         Connection::class,
         'resetObserver',
      );
      $QuarantineTokens = new ReflectionProperty(
         Connections::class,
         'quarantineTokens',
      );
      $ManagerTimer = new ReflectionProperty(Connections::class, 'timer');
      $CurrentManager = new ReflectionProperty(Connections::class, 'CurrentManager');
      $CurrentConnections = new ReflectionProperty(
         Connections::class,
         'CurrentConnections',
      );
      $ManagerResetObserver = new ReflectionProperty(
         Connections::class,
         'resetObserver',
      );
      $TimeoutCounts = new ReflectionProperty(Connections::class, 'timeoutCounts');
      $MinimumTimeout = new ReflectionProperty(Connections::class, 'minimumTimeout');
      $ResetObservers = new ReflectionProperty(TimerReset::class, 'Observers');
      $ResetRecoveries = new ReflectionProperty(TimerReset::class, 'Recoveries');
      $Tasks = new ReflectionProperty(Timer::class, 'tasks');
      $Sweep = new ReflectionMethod(Connections::class, 'sweep');
      $Committing = property_exists(Connections::class, 'committing')
         ? new ReflectionProperty(Connections::class, 'committing')
         : null;

      /** Force isolation only after failure evidence has already been frozen. */
      $Reset = static function () use ($IPConnections, $Peers): void {
         foreach (array_values(Connections::$Connections ?? []) as $Connection) {
            try {
               $Connection->close();
            }
            catch (Throwable) {
               // Evidence is already frozen; teardown must continue.
            }
         }
         Connections::$Connections = [];
         if (isSet(Connections::$blacklist)) {
            Connections::$blacklist = [];
         }
         unset($Connection);
         gc_collect_cycles();
         Lease::drain();
         Timer::del();
         gc_collect_cycles();
         Lease::drain();

         if ($Peers->isInitialized() && $Peers->getValue() !== []) {
            $Peers->setValue(null, []);
         }
         if (
            $IPConnections->isInitialized()
            && $IPConnections->getValue() !== []
         ) {
            $IPConnections->setValue(null, []);
         }
      };

      /**
       * Exercise one same-key or cross-key admission commit race.
       *
       * @return array<string,mixed>
       */
      $Probe = static function (bool $same) use (
         $Authorities,
         $Committing,
         $ConnectionQuarantineTimer,
         $ConnectionResetObserver,
         $DirectQuarantines,
         $GenerationBuckets,
         $IPConnections,
         $ManagerResetObserver,
         $ManagerTimer,
         $MinimumTimeout,
         $Peers,
         $Quarantines,
         $QuarantineTokens,
         $ResetObservers,
         $ResetRecoveries,
         $Socket,
         $SocketProperty,
         $Sweep,
         $Tasks,
         $TimeoutCounts,
      ): array {
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: $same ? 0 : 1,
            maxConnectionsPerIP: $same ? 1 : 0,
            connectionIdleTimeout: $same ? 0 : 1,
         ));
         $SocketProperty->setValue($Server, $Socket);
         $Manager = $Server->Connections;

         // ! Resolve every autoload path before asynchronous signals begin.
         $Warm = $Manager->accept('127.9.0.1:29999');
         if ($Warm instanceof Connection === false) {
            throw new RuntimeException('Could not warm admission commit fixture.');
         }
         $Warm->close();
         unset($Warm);
         gc_collect_cycles();
         Lease::drain();

         $PreviousAsync = pcntl_async_signals();
         $PreviousUSR1 = pcntl_signal_get_handler(SIGUSR1);
         $active = false;
         $outerPeer = '';
         $nestedPeer = '';
         $Nested = null;
         $Outer = null;
         $signals = 0;
         $activeSignals = 0;
         $nestedAttempts = 0;
         $nestedAdmissions = 0;
         $iterations = 0;
         $signalError = '';
         $outerError = '';
         $teardownError = '';
         $childPID = 0;
         $childReaped = false;
         $race = null;
         $AllReferences = [];
         $cleanup = [];

         try {
            pcntl_async_signals(true);
            pcntl_signal(
               SIGUSR1,
               static function () use (
                  &$Nested,
                  &$active,
                  &$activeSignals,
                  &$nestedPeer,
                  &$nestedAttempts,
                  &$signalError,
                  &$signals,
                  $Manager,
               ): void {
                  $signals++;
                  if (
                     $active === false
                     || $Nested !== null
                     || $signalError !== ''
                  ) {
                     return;
                  }

                  $activeSignals++;
                  $nestedAttempts++;
                  try {
                     $Nested = $Manager->accept($nestedPeer);
                  }
                  catch (Throwable $Throwable) {
                     $class = $Throwable::class;
                     $message = $Throwable->getMessage();
                     $signalError = "{$class}: {$message}";
                  }
               },
               false,
            );

            $parentPID = getmypid();
            $childPID = pcntl_fork();
            if ($childPID === 0) {
               pcntl_signal(SIGTERM, SIG_DFL, false);
               pcntl_async_signals(true);
               $deadline = hrtime(true) + 10_000_000_000;
               $cadence = 1;
               while (hrtime(true) < $deadline) {
                  posix_kill($parentPID, SIGUSR1);
                  $cadence = (($cadence * 17) % 97) + 1;
                  usleep($cadence);
               }
               posix_kill(getmypid(), SIGKILL);
               exit(0);
            }
            if ($childPID < 0) {
               throw new RuntimeException('Could not fork admission commit fixture.');
            }

            $deadline = hrtime(true) + 500_000_000;
            while ($signals === 0 && hrtime(true) < $deadline) {
               usleep(100);
            }
            if ($signals === 0) {
               throw new RuntimeException('Admission commit signal fixture did not start.');
            }

            for ($index = 0; $index < 10_000; $index++) {
               $third = intdiv($index, 250) % 250;
               $fourth = ($index % 250) + 1;
               $port = 20_000 + ($index % 40_000);
               $outerIP = $same
                  ? "127.12.{$third}.{$fourth}"
                  : "127.10.{$third}.{$fourth}";
               $nestedIP = $same
                  ? $outerIP
                  : "127.11.{$third}.{$fourth}";
               $outerPeer = "{$outerIP}:{$port}";
               $nestedPeer = $same
                  ? $outerPeer
                  : "{$nestedIP}:{$port}";
               $Nested = null;
               $Outer = null;
               $attemptsBefore = $nestedAttempts;
               try {
                  $active = true;
                  $Outer = $Manager->accept($outerPeer);
               }
               catch (Throwable $Throwable) {
                  $class = $Throwable::class;
                  $message = $Throwable->getMessage();
                  $outerError = "{$class}: {$message}";
               }
               finally {
                  $active = false;
               }
               if ($Nested instanceof Connection) {
                  $nestedAdmissions++;
               }
               $attempted = $nestedAttempts > $attemptsBefore;

               // ? Keep the non-overlap path lean enough that periodic signals
               //   sample the much shorter admission window. Inspect every
               //   attempted reentry, including one which returned null.
               if (
                  $attempted === false
                  && $outerError === ''
                  && $signalError === ''
               ) {
                  if ($Outer instanceof Connection) {
                     $AllReferences[] = WeakReference::create($Outer);
                  }
                  try {
                     $Outer?->close();
                  }
                  catch (Throwable $Throwable) {
                     $class = $Throwable::class;
                     $message = $Throwable->getMessage();
                     $outerError = "{$class}: {$message}";
                  }
                  gc_collect_cycles();
                  Lease::drain();
                  $commitIdle = $Committing === null
                     || (bool) $Committing->getValue() === false;
                  if (
                     $outerError === ''
                     && $Peers->getValue() === []
                     && $IPConnections->getValue() === []
                     && Connections::$Connections === []
                     && $commitIdle
                  ) {
                     unset($Outer);
                     $Outer = null;
                     $iterations++;
                     continue;
                  }
               }

               $Rows = $Peers->getValue();
               $IPs = $IPConnections->getValue();
               $Candidates = [];
               foreach ([$Outer, $Nested] as $Candidate) {
                  if ($Candidate instanceof Connection) {
                     $Candidates[spl_object_id($Candidate)] = $Candidate;
                  }
               }
               foreach ($Rows as $Row) {
                  $Candidate = $Row[1]->get();
                  if ($Candidate instanceof Connection) {
                     $Candidates[spl_object_id($Candidate)] = $Candidate;
                  }
               }
               foreach (Connections::$Connections as $Candidate) {
                  $Candidates[spl_object_id($Candidate)] = $Candidate;
               }
               unset($Candidate);

               $authorized = 0;
               foreach ($Candidates as $Candidate) {
                  if (ConnectionAuthority::check($Candidate)) {
                     $authorized++;
                  }
               }
               unset($Candidate);

               $rowsValid = true;
               $publicValid = true;
               $ExpectedIPs = [];
               $Canonical = null;
               foreach ($Rows as $key => $Row) {
                  $Candidate = $Row[1]->get();
                  if ($Candidate instanceof Connection) {
                     $Canonical = $Candidate;
                  }
                  $rowIP = $Row[0];
                  $ExpectedIPs[$rowIP] = ($ExpectedIPs[$rowIP] ?? 0) + 1;
                  if (
                     $Candidate instanceof Connection === false
                     || ConnectionAuthority::check($Candidate) === false
                     || $Candidate->id !== $key
                     || $Candidate->ip !== $rowIP
                     || is_object($Row[2]) === false
                     || $Row[3] !== ($same ? 0 : 1)
                  ) {
                     $rowsValid = false;
                  }
                  if (
                     $Candidate instanceof Connection === false
                     || (Connections::$Connections[$key] ?? null) !== $Candidate
                  ) {
                     $publicValid = false;
                  }
               }
               foreach (Connections::$Connections as $key => $Candidate) {
                  if (
                     isSet($Rows[$key]) === false
                     || $Rows[$key][1]->get() !== $Candidate
                  ) {
                     $publicValid = false;
                  }
               }
               unset($Candidate);
               $AuthorityMap = $Authorities->getValue();
               $authorityCount = count($AuthorityMap);
               $authorityCanonical = $Canonical instanceof Connection
                  && isSet($AuthorityMap[$Canonical]);
               $indexedAuthorities = 0;
               $indexedCanonical = false;
               foreach ($GenerationBuckets->getValue() as $GenerationBucket) {
                  $indexedAuthorities += count($GenerationBucket);
                  if (
                     $Canonical instanceof Connection
                     && isSet($GenerationBucket[$Canonical])
                  ) {
                     $indexedCanonical = true;
                  }
               }
               unset($GenerationBucket);

               $returnsValid = true;
               foreach (
                  [[$Outer, $outerPeer], [$Nested, $nestedPeer]]
                  as [$Candidate, $expectedPeer]
               ) {
                  if ($Candidate instanceof Connection) {
                     $key = $Candidate->id;
                     $Row = $Rows[$key] ?? null;
                     if (
                        ConnectionAuthority::check($Candidate) === false
                        || $key !== $expectedPeer
                        || $Row === null
                        || $Row[0] !== $Candidate->ip
                        || $Row[1]->get() !== $Candidate
                        || (Connections::$Connections[$key] ?? null)
                           !== $Candidate
                     ) {
                        $returnsValid = false;
                     }
                  }
               }
               unset($Candidate);
               ksort($ExpectedIPs);
               ksort($IPs);
               $IPValid = $IPs === $ExpectedIPs;
               $IPCount = $IPs[$outerIP] ?? 0;
               $IPTotal = array_sum($IPs);
               $commitIdle = $Committing === null
                  || (bool) $Committing->getValue() === false;
               $managerTimer = (int) $ManagerTimer->getValue();
               $resetObserver = (int) $ManagerResetObserver->getValue();
               $taskIDs = [];
               foreach ($Tasks->getValue() as $tasks) {
                  foreach (array_keys($tasks) as $id) {
                     $taskIDs[] = $id;
                  }
               }
               sort($taskIDs);
               $timerIDs = TimerRegistry::snapshot();
               sort($timerIDs);
               $observerValid = $resetObserver > 0
                  && isSet($ResetObservers->getValue()[$resetObserver])
                  && isSet($ResetRecoveries->getValue()[$resetObserver])
                  && array_keys($ResetObservers->getValue()) === [$resetObserver]
                  && array_keys($ResetRecoveries->getValue()) === [$resetObserver];
               $supervisorValid = $observerValid
                  && (
                     $same
                     ? (
                        $managerTimer === 0
                        && $taskIDs === []
                        && $timerIDs === []
                        && $TimeoutCounts->getValue() === []
                        && (int) $MinimumTimeout->getValue() === 0
                     )
                     : (
                        $managerTimer > 0
                        && TimerRegistry::check($managerTimer)
                        && $taskIDs === [$managerTimer]
                        && $timerIDs === [$managerTimer]
                        && $TimeoutCounts->getValue() === [1 => 1]
                        && (int) $MinimumTimeout->getValue() === 1
                     )
                  );
               $occupied = count($Rows) === 1
                  && count(Connections::$Connections) === 1
                  && $IPTotal === 1
                  && $authorized === 1
                  && $authorityCount === 1
                  && $authorityCanonical
                  && $indexedAuthorities === 1
                  && $indexedCanonical
                  && (
                     $Outer instanceof Connection
                     || $Nested instanceof Connection
                  );
               $violated = $attempted && (
                  $same
                  ? (
                     $IPCount > 1
                     || $IPValid === false
                     || $occupied === false
                     || $rowsValid === false
                     || $publicValid === false
                     || $returnsValid === false
                     || $supervisorValid === false
                     || $commitIdle === false
                  )
                  : (
                     count($Rows) > 1
                     || $IPValid === false
                     || $occupied === false
                     || $rowsValid === false
                     || $publicValid === false
                     || $returnsValid === false
                     || $supervisorValid === false
                     || $commitIdle === false
                  )
               );
               if ($violated) {
                  $race = [
                     'index' => $index,
                     'outer' => $Outer instanceof Connection,
                     'nested' => $Nested instanceof Connection,
                     'same_object' => $Outer instanceof Connection
                        && $Outer === $Nested,
                     'outer_authority' => $Outer instanceof Connection
                        && ConnectionAuthority::check($Outer),
                     'nested_authority' => $Nested instanceof Connection
                        && ConnectionAuthority::check($Nested),
                     'authorized' => $authorized,
                     'global_authorities' => $authorityCount,
                     'authority_canonical' => $authorityCanonical,
                     'indexed_authorities' => $indexedAuthorities,
                     'index_canonical' => $indexedCanonical,
                     'occupied' => $occupied,
                     'IP_count' => $IPCount,
                     'IP_total' => $IPTotal,
                     'IP_valid' => $IPValid,
                     'expected_IPs' => $ExpectedIPs,
                     'outer_IP' => $outerIP,
                     'peers' => array_keys($Rows),
                     'peer_IPs' => array_map(
                        static fn (array $Row): string => $Row[0],
                        $Rows,
                     ),
                     'IPs' => $IPs,
                     'public' => array_keys(Connections::$Connections),
                     'rows_valid' => $rowsValid,
                     'public_valid' => $publicValid,
                     'returns_valid' => $returnsValid,
                     'manager_timer' => $managerTimer,
                     'timer_tasks' => $taskIDs,
                     'timer_status' => $timerIDs,
                     'reset_observer' => $resetObserver,
                     'supervisor_valid' => $supervisorValid,
                     'committing_idle' => $commitIdle,
                  ];
               }

               foreach ($Candidates as $Candidate) {
                  $AllReferences[] = WeakReference::create($Candidate);
                  try {
                     $Candidate->close();
                  }
                  catch (Throwable) {
                     // Cleanup evidence below records any surviving authority.
                  }
               }
               // ! $Nested is captured by-reference by the signal handler;
               //   assigning preserves that reference cell for the next turn.
               unset(
                  $AuthorityMap,
                  $Candidate,
                  $Candidates,
                  $Canonical,
                  $Outer,
                  $Row,
                  $Rows,
               );
               $Outer = null;
               $Nested = null;
               gc_collect_cycles();
               Lease::drain();
               $iterations++;
               if (
                  $race !== null
                  || $outerError !== ''
                  || $signalError !== ''
               ) {
                  break;
               }
            }
         }
         finally {
            $active = false;
            if ($childPID > 0) {
               posix_kill($childPID, SIGTERM);
               do {
                  $waited = pcntl_waitpid($childPID, $status);
               }
               while (
                  $waited === -1
                  && pcntl_get_last_error() === PCNTL_EINTR
               );
               $childReaped = $waited === $childPID;
            }
            pcntl_signal_dispatch();
            pcntl_signal(SIGUSR1, SIG_IGN, false);
            pcntl_signal_dispatch();
            pcntl_signal(
               SIGUSR1,
               $PreviousUSR1 === false ? SIG_DFL : $PreviousUSR1,
               false,
            );
            pcntl_async_signals($PreviousAsync);
            foreach ([$Nested, $Outer] as $Candidate) {
               try {
                  $Candidate?->close();
               }
               catch (Throwable $Throwable) {
                  if ($teardownError === '') {
                     $class = $Throwable::class;
                     $message = $Throwable->getMessage();
                     $teardownError = "{$class}: {$message}";
                  }
               }
            }
            unset($Candidate, $Outer);
            $Nested = null;
            $Outer = null;
            gc_collect_cycles();
            Lease::drain();
         }

         $Inspect = static function () use (
            $Authorities,
            $ConnectionQuarantineTimer,
            $ConnectionResetObserver,
            $DirectQuarantines,
            $GenerationBuckets,
            $ManagerResetObserver,
            $ManagerTimer,
            $MinimumTimeout,
            $Quarantines,
            $QuarantineTokens,
            $ResetObservers,
            $ResetRecoveries,
            $Tasks,
            $TimeoutCounts,
         ): array {
            $indexed = 0;
            foreach ($GenerationBuckets->getValue() as $GenerationBucket) {
               $indexed += count($GenerationBucket);
            }

            return [
               'global_authorities' => count($Authorities->getValue()),
               'indexed_authorities' => $indexed,
               'generation_buckets' => count($GenerationBuckets->getValue()),
               'quarantine_tokens' => count($QuarantineTokens->getValue()),
               'managed_quarantines' => count($Quarantines->getValue()),
               'direct_quarantines' => count($DirectQuarantines->getValue()),
               'manager_timer' => (int) $ManagerTimer->getValue(),
               'manager_reset_observer' => (int) $ManagerResetObserver->getValue(),
               'timeout_counts' => $TimeoutCounts->getValue(),
               'minimum_timeout' => (int) $MinimumTimeout->getValue(),
               'connection_timer' => (int) $ConnectionQuarantineTimer->getValue(),
               'connection_reset_observer' => (int) $ConnectionResetObserver->getValue(),
               'timer_tasks' => count($Tasks->getValue()),
               'timer_status' => TimerRegistry::snapshot(),
               'reset_observers' => count($ResetObservers->getValue()),
               'reset_recoveries' => count($ResetRecoveries->getValue()),
            ];
         };
         $Clean = static function () use (
            $IPConnections,
            $Inspect,
            $Peers,
         ): bool {
            $State = $Inspect();

            return $Peers->getValue() === []
               && $IPConnections->getValue() === []
               && Connections::$Connections === []
               && $State === [
                  'global_authorities' => 0,
                  'indexed_authorities' => 0,
                  'generation_buckets' => 0,
                  'quarantine_tokens' => 0,
                  'managed_quarantines' => 0,
                  'direct_quarantines' => 0,
                  'manager_timer' => 0,
                  'manager_reset_observer' => 0,
                  'timeout_counts' => [],
                  'minimum_timeout' => 0,
                  'connection_timer' => 0,
                  'connection_reset_observer' => 0,
                  'timer_tasks' => 0,
                  'timer_status' => [],
                  'reset_observers' => 0,
                  'reset_recoveries' => 0,
               ];
         };

         // @ Stop async ownership first, then drive only normal close/sweep
         //   paths. No registry is reset before the cleanup evidence is read.
         for ($pass = 0; $pass < 16; $pass++) {
            foreach (array_keys($Peers->getValue()) as $key) {
               try {
                  $Manager->close($key);
               }
               catch (Throwable $Throwable) {
                  if ($teardownError === '') {
                     $class = $Throwable::class;
                     $message = $Throwable->getMessage();
                     $teardownError = "{$class}: {$message}";
                  }
               }
            }
            foreach (array_values(Connections::$Connections) as $Connection) {
               try {
                  $Connection->close();
               }
               catch (Throwable) {
                  // Cleanup evidence below records the retained state.
               }
            }
            unset($Connection);
            try {
               $Sweep->invoke(null);
            }
            catch (Throwable $Throwable) {
               if ($teardownError === '') {
                  $class = $Throwable::class;
                  $message = $Throwable->getMessage();
                  $teardownError = "{$class}: {$message}";
               }
            }
            gc_collect_cycles();
            Lease::drain();
            if ($Clean()) {
               break;
            }
         }
         $live = 0;
         $liveAuthorities = 0;
         foreach ($AllReferences as $Reference) {
            $Candidate = $Reference->get();
            if ($Candidate instanceof Connection) {
               $live++;
               if (ConnectionAuthority::check($Candidate)) {
                  $liveAuthorities++;
               }
            }
         }
         unset($Candidate, $AllReferences);
         $cleanup = [
            'peers' => $Peers->getValue(),
            'IPs' => $IPConnections->getValue(),
            'public' => array_keys(Connections::$Connections),
            'live' => $live,
            'authorities' => $liveAuthorities,
            ...$Inspect(),
         ];

         $reuseIP = $same && $race !== null
            ? (string) array_key_first($race['IPs'])
            : ($same ? '127.12.249.250' : '127.13.249.250');
         $reusePeer = "{$reuseIP}:65530";
         $Reuse = null;
         $ReuseReference = null;
         $reuseAdmitted = false;
         $reuseAuthorized = false;
         $reuseSwept = null;
         $reuseError = '';
         try {
            $Reuse = $Manager->accept($reusePeer);
            $reuseAdmitted = $Reuse instanceof Connection;
            $reuseAuthorized = $Reuse instanceof Connection
               && ConnectionAuthority::check($Reuse);
            $ReuseReference = $Reuse instanceof Connection
               ? WeakReference::create($Reuse)
               : null;
            if ($Reuse instanceof Connection && $same === false) {
               $Reuse->used = time() - 2;
               $due = [];
               foreach ($Tasks->getValue() as $tasks) {
                  foreach ($tasks as $id => $task) {
                     $due[$id] = $task;
                  }
               }
               $Tasks->setValue(null, $due === [] ? [] : [time() - 1 => $due]);
               Timer::tick();
               $reuseSwept = ConnectionAuthority::check($Reuse) === false;
            }
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $reuseError = "{$class}: {$message}";
         }
         finally {
            try {
               $Reuse?->close();
            }
            catch (Throwable $Throwable) {
               if ($teardownError === '') {
                  $class = $Throwable::class;
                  $message = $Throwable->getMessage();
                  $teardownError = "{$class}: {$message}";
               }
            }
            unset($Reuse);
            gc_collect_cycles();
            Lease::drain();
         }
         if ($reuseSwept !== null && $ReuseReference !== null) {
            $reuseSwept = $reuseSwept && $ReuseReference->get() === null;
         }
         $reuseReleased = $ReuseReference === null
            || $ReuseReference->get() === null;
         $reuseClean = $Clean();

         $Evidence = [
            'signals' => $signals,
            'active_signals' => $activeSignals,
            'nested_attempts' => $nestedAttempts,
            'nested_admissions' => $nestedAdmissions,
            'iterations' => $iterations,
            'signal_error' => $signalError,
            'outer_error' => $outerError,
            'teardown_error' => $teardownError,
            'child_reaped' => $childReaped,
            'race' => $race,
            'cleanup' => $cleanup,
            'reuse_admitted' => $reuseAdmitted,
            'reuse_authorized' => $reuseAuthorized,
            'reuse_error' => $reuseError,
            'reuse_released' => $reuseReleased,
            'reuse_swept' => $reuseSwept,
            'reuse_clean' => $reuseClean,
            'generation_buckets' => count($GenerationBuckets->getValue()),
         ];

         return $Evidence;
      };

      /**
       * Invalidate manager authority inside one guarded commit and verify rollback.
       *
       * @return array<string,mixed>
       */
      $Rollback = static function (Closure $Report) use (
         $Authorities,
         $Closed,
         $Committing,
         $ConnectionQuarantineTimer,
         $ConnectionResetObserver,
         $CurrentConnections,
         $CurrentManager,
         $DirectQuarantines,
         $GenerationBuckets,
         $IPConnections,
         $ManagerResetObserver,
         $ManagerTimer,
         $MinimumTimeout,
         $Peers,
         $Quarantines,
         $QuarantineTokens,
         $ResetObservers,
         $ResetRecoveries,
         $Socket,
         $SocketProperty,
         $Sweep,
         $Tasks,
         $TimeoutCounts,
      ): array {
         $Report('rollback:setup');
         if ($Committing === null) {
            return ['supported' => false];
         }

         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 2,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 1,
         ));
         $SocketProperty->setValue($Server, $Socket);
         $Manager = $Server->Connections;
         $anchorPeer = '127.9.0.2:29998';
         $Anchor = $Manager->accept($anchorPeer);
         if ($Anchor instanceof Connection === false) {
            throw new RuntimeException('Could not warm commit rollback fixture.');
         }
         $Anchor->used = time() - 2;
         $OriginalManager = $CurrentManager->getValue();
         $OriginalConnections = $CurrentConnections->getValue();
         $InvalidManager = new stdClass;
         $InvalidConnections = WeakReference::create($Manager);
         $authorityPairValid = $OriginalConnections->get() === $Manager;
         $Report('rollback:anchor');

         $PreviousAsync = pcntl_async_signals();
         $PreviousUSR1 = pcntl_signal_get_handler(SIGUSR1);
         $active = false;
         $injected = false;
         $signals = 0;
         $activeSignals = 0;
         $iterations = 0;
         $outerError = '';
         $teardownError = '';
         $continuationError = '';
         $reuseError = '';
         $closeBlocked = false;
         $sweepAnchorIntact = false;
         $managerInvalidated = false;
         $managerRestored = false;
         $childPID = 0;
         $childReaped = false;
         $Outer = null;
         $peer = '';
         $IP = '';
         $Close = static function (null|Connection $Connection) use (
            &$teardownError,
         ): void {
            try {
               $Connection?->close();
            }
            catch (Throwable $Throwable) {
               if ($teardownError === '') {
                  $class = $Throwable::class;
                  $message = $Throwable->getMessage();
                  $teardownError = "{$class}: {$message}";
               }
            }
         };
         $Attempt = static function (
            Connections $Manager,
            string $peer,
            bool &$active,
            bool &$injected,
            string &$outerError,
         ) use ($Report): null|Connection {
            $Connection = null;
            try {
               $active = true;
               $Connection = $Manager->accept($peer);
               if ($injected) {
                  $Report('rollback:accept-return');
               }
            }
            catch (Throwable $Throwable) {
               $Report('rollback:outer-catch');
               $class = $Throwable::class;
               $message = $Throwable->getMessage();
               $outerError = "{$class}: {$message}";
            }
            finally {
               $active = false;
            }

            return $Connection;
         };
         try {
            pcntl_async_signals(true);
            pcntl_signal(
               SIGUSR1,
               static function () use (
                  &$active,
                  &$activeSignals,
                  $Anchor,
                  $anchorPeer,
                  &$childPID,
                  &$closeBlocked,
                  $CurrentConnections,
                  $CurrentManager,
                  &$injected,
                  $InvalidConnections,
                  $InvalidManager,
                  &$IP,
                  &$managerInvalidated,
                  &$peer,
                  &$signals,
                  &$sweepAnchorIntact,
                  $Committing,
                  $IPConnections,
                  $Manager,
                  $Peers,
                  $Sweep,
               ): void {
                  $signals++;
                  if (
                     $active
                     && $injected === false
                     && (bool) $Committing->getValue()
                     && isSet($Peers->getValue()[$peer])
                     && (($IPConnections->getValue()[$IP] ?? 0) > 0)
                  ) {
                     $activeSignals++;
                     $injected = true;
                     $closeBlocked = $Manager->close($anchorPeer) === false;
                     $Sweep->invoke(null);
                     $AnchorRow = $Peers->getValue()[$anchorPeer] ?? null;
                     $sweepAnchorIntact = ConnectionAuthority::check($Anchor)
                        && $AnchorRow !== null
                        && $AnchorRow[1]->get() === $Anchor
                        && (Connections::$Connections[$anchorPeer] ?? null)
                           === $Anchor;
                     $CurrentManager->setValue(null, $InvalidManager);
                     $CurrentConnections->setValue(null, $InvalidConnections);
                     $managerInvalidated = $CurrentManager->getValue()
                           === $InvalidManager
                        && $CurrentConnections->getValue()
                           === $InvalidConnections;
                     if ($childPID > 0) {
                        posix_kill($childPID, SIGTERM);
                     }
                  }
               },
               false,
            );
            $parentPID = getmypid();
            $childPID = pcntl_fork();
            if ($childPID === 0) {
               pcntl_signal(SIGTERM, SIG_DFL, false);
               pcntl_async_signals(true);
               $deadline = hrtime(true) + 10_000_000_000;
               while (hrtime(true) < $deadline) {
                  posix_kill($parentPID, SIGUSR1);
                  usleep(20);
               }
               posix_kill(getmypid(), SIGKILL);
               exit(0);
            }
            if ($childPID < 0) {
               throw new RuntimeException('Could not fork commit rollback fixture.');
            }
            $deadline = hrtime(true) + 500_000_000;
            while ($signals === 0 && hrtime(true) < $deadline) {
               usleep(100);
            }

            $Report('rollback:admission');
            for ($index = 0; $index < 10_000 && $injected === false; $index++) {
               $third = intdiv($index, 250) % 250;
               $fourth = ($index % 250) + 1;
               $port = 30_000 + ($index % 30_000);
               $IP = "127.14.{$third}.{$fourth}";
               $peer = "{$IP}:{$port}";
               $Outer = $Attempt(
                  $Manager,
                  $peer,
                  $active,
                  $injected,
                  $outerError,
               );
               $iterations++;
               if ($injected === false) {
                  $Close($Outer);
                  unset($Outer);
                  $Outer = null;
                  gc_collect_cycles();
                  Lease::drain();
               }
            }
            $Report('rollback:loop-done');
         }
         finally {
            $active = false;
            $Report('rollback:stopping-signal');
            if ($childPID > 0) {
               posix_kill($childPID, SIGTERM);
               do {
                  $waited = pcntl_waitpid($childPID, $status);
               }
               while (
                  $waited === -1
                  && pcntl_get_last_error() === PCNTL_EINTR
               );
               $childReaped = $waited === $childPID;
            }
            $Report('rollback:signal-reaped');
            pcntl_signal_dispatch();
            pcntl_signal(SIGUSR1, SIG_IGN, false);
            pcntl_signal_dispatch();
            pcntl_signal(
               SIGUSR1,
               $PreviousUSR1 === false ? SIG_DFL : $PreviousUSR1,
               false,
            );
            pcntl_async_signals($PreviousAsync);
            $Report('rollback:signal-restored');
         }
         $Report('rollback:signal-stopped');
         $pairStayedInvalid = $CurrentManager->getValue() === $InvalidManager
            && $CurrentConnections->getValue() === $InvalidConnections;
         $CurrentManager->setValue(null, $OriginalManager);
         $CurrentConnections->setValue(null, $OriginalConnections);
         $managerRestored = $CurrentManager->getValue() === $OriginalManager
            && $CurrentConnections->getValue() === $OriginalConnections
            && $OriginalConnections->get() === $Manager;
         unset($Attempt);

         // @ Freeze every ledger before closing the anchor or running any
         //   global continuation. A failed commit may add only one supervised
         //   closed quarantine alongside that intact pre-existing peer.
         $Rows = $Peers->getValue();
         $IPs = $IPConnections->getValue();
         $anchorIP = $Anchor->ip;
         $AnchorRow = $Rows[$anchorPeer] ?? null;
         $RollbackRow = $Rows[$peer] ?? null;
         $RollbackReference = $RollbackRow === null
            ? null
            : $RollbackRow[1];
         $RollbackConnection = $RollbackReference?->get();
         $ManagedQuarantines = $Quarantines->getValue();
         $Direct = $DirectQuarantines->getValue();
         $Tokens = $QuarantineTokens->getValue();
         $timer = (int) $ManagerTimer->getValue();
         $resetObserver = (int) $ManagerResetObserver->getValue();
         $timeoutCounts = $TimeoutCounts->getValue();
         $minimumTimeout = (int) $MinimumTimeout->getValue();
         $taskIDs = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach (array_keys($tasks) as $id) {
               $taskIDs[] = $id;
            }
         }
         sort($taskIDs);
         $timerIDs = TimerRegistry::snapshot();
         sort($timerIDs);
         $supervised = $timer > 0
            && $resetObserver > 0
            && TimerRegistry::check($timer)
            && $taskIDs === [$timer]
            && $timerIDs === [$timer]
            && array_keys($ResetObservers->getValue()) === [$resetObserver]
            && array_keys($ResetRecoveries->getValue()) === [$resetObserver];
         $AuthorityMap = $Authorities->getValue();
         $indexedAuthorities = 0;
         $anchorIndexed = false;
         foreach ($GenerationBuckets->getValue() as $GenerationBucket) {
            $indexedAuthorities += count($GenerationBucket);
            if (isSet($GenerationBucket[$Anchor])) {
               $anchorIndexed = true;
            }
         }
         unset($GenerationBucket);
         $anchorIntactAfter = ConnectionAuthority::check($Anchor)
            && $AnchorRow !== null
            && $Anchor->id === $anchorPeer
            && $Anchor->ip === $anchorIP
            && $AnchorRow[0] === $anchorIP
            && $AnchorRow[1]->get() === $Anchor
            && is_object($AnchorRow[2])
            && $AnchorRow[3] === 1
            && ($IPs[$anchorIP] ?? 0) === 1
            && (Connections::$Connections[$anchorPeer] ?? null) === $Anchor;
         $rollbackClean = count($Rows) === 1
            && $anchorIntactAfter
            && count($IPs) === 1
            && count(Connections::$Connections) === 1
            && count($AuthorityMap) === 1
            && isSet($AuthorityMap[$Anchor])
            && $indexedAuthorities === 1
            && $anchorIndexed
            && $Tokens === []
            && $ManagedQuarantines === []
            && $Direct === []
            && $timeoutCounts === [1 => 1]
            && $minimumTimeout === 1
            && $supervised;
         $rollbackQuarantined = count($Rows) === 2
            && $anchorIntactAfter
            && $RollbackRow !== null
            && $RollbackConnection instanceof Connection
            && $RollbackConnection->id === $peer
            && $RollbackConnection->ip === $IP
            && $RollbackRow[0] === $IP
            && is_object($RollbackRow[2])
            && $RollbackRow[3] === 1
            && count($IPs) === 2
            && ($IPs[$IP] ?? 0) === 1
            && count(Connections::$Connections) === 1
            && ConnectionAuthority::check($RollbackConnection) === false
            && (bool) $Closed->getValue($RollbackConnection)
            && isSet($Tokens[spl_object_id($RollbackRow[2])])
            && ($ManagedQuarantines[spl_object_id($RollbackConnection)] ?? null)
               === $RollbackConnection
            && isSet($Direct[spl_object_id($RollbackConnection)]) === false
            && count($Tokens) === 1
            && count($ManagedQuarantines) === 1
            && count($Direct) === 0
            && count($AuthorityMap) === 1
            && isSet($AuthorityMap[$Anchor])
            && $indexedAuthorities === 1
            && $anchorIndexed
            && $timeoutCounts === [1 => 2]
            && $minimumTimeout === 1
            && $supervised;
         $beforeCleanup = [
            'outer' => $Outer instanceof Connection,
            'outer_authority' => $Outer instanceof Connection
               && ConnectionAuthority::check($Outer),
            'committing' => (bool) $Committing->getValue(),
            'anchor_intact' => $anchorIntactAfter,
            'clean' => $rollbackClean,
            'quarantined' => $rollbackQuarantined,
            'peers' => $Rows,
            'IPs' => $IPs,
            'public' => array_keys(Connections::$Connections),
            'authorities' => count($AuthorityMap),
            'anchor_indexed' => $anchorIndexed,
            'indexed_authorities' => $indexedAuthorities,
            'quarantine_tokens' => count($Tokens),
            'managed_quarantines' => count($ManagedQuarantines),
            'direct_quarantines' => count($Direct),
            'timeout_counts' => $timeoutCounts,
            'minimum_timeout' => $minimumTimeout,
            'timer' => $timer,
            'timer_registered' => $timer > 0
               && TimerRegistry::check($timer),
            'timer_tasks' => $taskIDs,
            'timer_status' => $timerIDs,
            'reset_observer' => $resetObserver,
         ];
         $Report('rollback:snapshot');
         $Close($Anchor);
         $Close($Outer);
         unset(
            $Anchor,
            $AnchorRow,
            $AuthorityMap,
            $Direct,
            $ManagedQuarantines,
            $Outer,
            $RollbackConnection,
            $RollbackRow,
            $Rows,
            $Tokens,
         );
         gc_collect_cycles();
         Lease::drain();

         $Clean = static function () use (
            $Authorities,
            $ConnectionQuarantineTimer,
            $ConnectionResetObserver,
            $DirectQuarantines,
            $GenerationBuckets,
            $IPConnections,
            $ManagerResetObserver,
            $ManagerTimer,
            $MinimumTimeout,
            $Peers,
            $Quarantines,
            $QuarantineTokens,
            $ResetObservers,
            $ResetRecoveries,
            $RollbackReference,
            $Tasks,
            $TimeoutCounts,
         ): bool {
            $indexed = 0;
            foreach ($GenerationBuckets->getValue() as $GenerationBucket) {
               $indexed += count($GenerationBucket);
            }

            return $Peers->getValue() === []
               && $IPConnections->getValue() === []
               && Connections::$Connections === []
               && count($Authorities->getValue()) === 0
               && $indexed === 0
               && $GenerationBuckets->getValue() === []
               && $QuarantineTokens->getValue() === []
               && $Quarantines->getValue() === []
               && $DirectQuarantines->getValue() === []
               && (int) $ManagerTimer->getValue() === 0
               && (int) $ManagerResetObserver->getValue() === 0
               && $TimeoutCounts->getValue() === []
               && (int) $MinimumTimeout->getValue() === 0
               && (int) $ConnectionQuarantineTimer->getValue() === 0
               && (int) $ConnectionResetObserver->getValue() === 0
               && $Tasks->getValue() === []
               && TimerRegistry::snapshot() === []
               && $ResetObservers->getValue() === []
               && $ResetRecoveries->getValue() === []
               && (
                  $RollbackReference === null
                  || $RollbackReference->get() === null
               );
         };
         $continuations = 0;
         for ($pass = 0; $pass < 16 && $Clean() === false; $pass++) {
            $continuations++;
            try {
               $Report("rollback:close-{$pass}");
               $Manager->close($peer);
               $Report("rollback:sweep-{$pass}");
               $Sweep->invoke(null);
               $Report("rollback:swept-{$pass}");
               $due = [];
               foreach ($Tasks->getValue() as $tasks) {
                  foreach ($tasks as $id => $task) {
                     $due[$id] = $task;
                  }
               }
               if ($due !== []) {
                  $Report("rollback:tick-{$pass}");
                  $Tasks->setValue(null, [time() - 1 => $due]);
                  Timer::tick();
                  $Report("rollback:ticked-{$pass}");
               }
            }
            catch (Throwable $Throwable) {
               if ($teardownError === '') {
                  $class = $Throwable::class;
                  $message = $Throwable->getMessage();
                  $teardownError = "{$class}: {$message}";
               }
            }
            $Report("rollback:retry-{$pass}");
            $Retry = null;
            try {
               $Retry = $Manager->accept($peer);
            }
            catch (Throwable $Throwable) {
               if ($continuationError === '') {
                  $class = $Throwable::class;
                  $message = $Throwable->getMessage();
                  $continuationError = "{$class}: {$message}";
               }
            }
            $Close($Retry);
            unset($Retry);
            gc_collect_cycles();
            Lease::drain();
            $Report("rollback:continued-{$pass}");
         }
         $converged = $Clean();
         $Report('rollback:reuse');
         $Reuse = null;
         try {
            $Reuse = $Manager->accept($peer);
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $reuseError = "{$class}: {$message}";
         }
         $reuse = $Reuse instanceof Connection
            && ConnectionAuthority::check($Reuse);
         $ReuseReference = $Reuse instanceof Connection
            ? WeakReference::create($Reuse)
            : null;
         $Close($Reuse);
         unset($Reuse);
         gc_collect_cycles();
         Lease::drain();
         $reuseReleased = $ReuseReference === null
            || $ReuseReference->get() === null;
         $afterCleanup = [
            'committing' => (bool) $Committing->getValue(),
            'converged' => $converged,
            'peers' => $Peers->getValue(),
            'IPs' => $IPConnections->getValue(),
            'public' => array_keys(Connections::$Connections),
            'authorities' => count($Authorities->getValue()),
            'quarantine_tokens' => count($QuarantineTokens->getValue()),
            'managed_quarantines' => count($Quarantines->getValue()),
            'direct_quarantines' => count($DirectQuarantines->getValue()),
            'timer' => (int) $ManagerTimer->getValue(),
            'reset_observer' => (int) $ManagerResetObserver->getValue(),
            'timeout_counts' => $TimeoutCounts->getValue(),
            'minimum_timeout' => (int) $MinimumTimeout->getValue(),
            'connection_timer' => (int) $ConnectionQuarantineTimer->getValue(),
            'connection_reset_observer' => (int) $ConnectionResetObserver->getValue(),
            'timer_tasks' => count($Tasks->getValue()),
            'timer_status' => TimerRegistry::snapshot(),
            'reset_observers' => count($ResetObservers->getValue()),
            'reset_recoveries' => count($ResetRecoveries->getValue()),
            'generation_buckets' => count($GenerationBuckets->getValue()),
         ];
         $Report('rollback:done');

         $Evidence = [
            'supported' => true,
            'signals' => $signals,
            'active_signals' => $activeSignals,
            'injected' => $injected,
            'iterations' => $iterations,
            'outer_error' => $outerError,
            'teardown_error' => $teardownError,
            'continuation_error' => $continuationError,
            'reuse_error' => $reuseError,
            'close_blocked' => $closeBlocked,
            'sweep_anchor_intact' => $sweepAnchorIntact,
            'authority_pair_valid' => $authorityPairValid,
            'manager_invalidated' => $managerInvalidated,
            'pair_stayed_invalid' => $pairStayedInvalid,
            'manager_restored' => $managerRestored,
            'anchor_intact_after_rollback' => $anchorIntactAfter,
            'child_reaped' => $childReaped,
            'before_cleanup' => $beforeCleanup,
            'continuations' => $continuations,
            'converged' => $converged,
            'reuse' => $reuse,
            'reuse_released' => $reuseReleased,
            'after_cleanup' => $afterCleanup,
         ];
         return $Evidence;
      };

      /**
       * Bound the whole adversarial exercise and its signal-sender children.
       *
       * @return array{reaped:bool,timed_out:bool,error:string,stage:string,evidence:mixed}
       */
      $Supervise = static function (Closure $Exercise): array {
         $Channels = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
         );
         if ($Channels === false) {
            return [
               'reaped' => false,
               'timed_out' => false,
               'error' => 'Could not create worker control channel.',
               'stage' => '',
               'evidence' => null,
            ];
         }
         [$Reader, $Writer] = $Channels;
         $workerPID = pcntl_fork();
         if ($workerPID === 0) {
            fclose($Reader);
            posix_setpgid(0, 0);
            $Report = static function (string $stage) use ($Writer): void {
               fwrite($Writer, "P:{$stage}\n");
               fflush($Writer);
            };
            try {
               $evidence = $Exercise($Report);
               $encoded = (string) json_encode([
                  'error' => '',
                  'evidence' => $evidence,
               ], JSON_THROW_ON_ERROR);
               $payload = "J:{$encoded}\n";
            }
            catch (Throwable $Throwable) {
               $class = $Throwable::class;
               $message = $Throwable->getMessage();
               $encoded = (string) json_encode([
                  'error' => "{$class}: {$message}",
                  'evidence' => null,
               ]);
               $payload = "J:{$encoded}\n";
            }

            $offset = 0;
            $length = strlen($payload);
            while ($offset < $length) {
               $written = fwrite($Writer, substr($payload, $offset));
               if ($written === false || $written === 0) {
                  break;
               }
               $offset += $written;
            }
            fflush($Writer);
            stream_socket_shutdown($Writer, STREAM_SHUT_WR);
            fclose($Writer);
            posix_kill(getmypid(), SIGKILL);
            exit(255);
         }
         fclose($Writer);
         if ($workerPID < 0) {
            fclose($Reader);

            return [
               'reaped' => false,
               'timed_out' => false,
               'error' => 'Could not fork bounded test worker.',
               'stage' => '',
               'evidence' => null,
            ];
         }

         posix_setpgid($workerPID, $workerPID);
         stream_set_blocking($Reader, false);
         $payload = '';
         $deadline = hrtime(true) + 15_000_000_000;
         $timedOut = false;
         $waitError = '';
         $waited = 0;
         while (true) {
            $chunk = stream_get_contents($Reader);
            if ($chunk !== false && $chunk !== '') {
               $payload .= $chunk;
            }
            $waited = pcntl_waitpid($workerPID, $status, WNOHANG);
            if ($waited === $workerPID) {
               break;
            }
            if ($waited === -1) {
               if (pcntl_get_last_error() === PCNTL_EINTR) {
                  continue;
               }
               $waitError = 'Could not reap adversarial worker.';
               posix_kill(-$workerPID, SIGKILL);
               posix_kill($workerPID, SIGKILL);
               break;
            }
            if (hrtime(true) >= $deadline) {
               $timedOut = true;
               posix_kill(-$workerPID, SIGKILL);
               posix_kill($workerPID, SIGKILL);
               do {
                  $waited = pcntl_waitpid($workerPID, $status);
               }
               while (
                  $waited === -1
                  && pcntl_get_last_error() === PCNTL_EINTR
               );
               break;
            }
            usleep(1_000);
         }
         // @ A failed worker may leave its signal-sender grandchild alive.
         //   Both inherit the dedicated process group, so reap it best-effort.
         posix_kill(-$workerPID, SIGKILL);
         $drainDeadline = hrtime(true) + 100_000_000;
         do {
            $chunk = stream_get_contents($Reader);
            if ($chunk !== false && $chunk !== '') {
               $payload .= $chunk;
            }
            if (feof($Reader)) {
               break;
            }
            usleep(1_000);
         }
         while (hrtime(true) < $drainDeadline);
         fclose($Reader);

         $stage = '';
         $encoded = '';
         foreach (explode("\n", $payload) as $frame) {
            if (str_starts_with($frame, 'P:')) {
               $stage = substr($frame, 2);
            }
            else if (str_starts_with($frame, 'J:')) {
               $encoded = substr($frame, 2);
            }
         }
         $Decoded = json_decode($encoded, true);
         $error = $timedOut
            ? 'Adversarial worker exceeded its 15-second deadline.'
            : $waitError;
         if ($error === '' && is_array($Decoded) === false) {
            $error = 'Adversarial worker returned an invalid control payload.';
         }
         if ($error === '' && ($Decoded['error'] ?? '') !== '') {
            $error = (string) $Decoded['error'];
         }

         return [
            'reaped' => $waited === $workerPID,
            'timed_out' => $timedOut,
            'error' => $error,
            'stage' => $stage,
            'evidence' => is_array($Decoded)
               ? ($Decoded['evidence'] ?? null)
               : null,
         ];
      };

      try {
         $SameRun = $Supervise(
            static function (Closure $Report) use ($Probe): array {
               $Report('same:start');
               $Evidence = $Probe(true);
               $Report('same:done');

               return $Evidence;
            },
         );
         $CrossRun = $Supervise(
            static function (Closure $Report) use ($Probe): array {
               $Report('cross:start');
               $Evidence = $Probe(false);
               $Report('cross:done');

               return $Evidence;
            },
         );
         $RollbackRun = $Supervise(
            static function (Closure $Report) use ($Rollback): array {
               return $Rollback($Report);
            },
         );
         $Runs = [
            'same_key_per_IP' => $SameRun,
            'cross_key_global' => $CrossRun,
            'rollback' => $RollbackRun,
         ];
         $workerControl = true;
         foreach ($Runs as $Run) {
            $workerControl = $Run['reaped']
               && $Run['timed_out'] === false
               && $Run['error'] === ''
               && is_array($Run['evidence'])
               && $workerControl;
         }
         $workerJSON = (string) json_encode($Runs);
         yield new Assertion(
            description: 'commit-race worker is bounded and returns evidence',
            fallback: "H7 commit-race worker/control failed: {$workerJSON}",
         )
            ->expect($workerControl, Op::Identical, true)
            ->assert();
         if ($workerControl === false) {
            return;
         }

         $Same = $SameRun['evidence'];
         $Cross = $CrossRun['evidence'];
         $RollbackState = $RollbackRun['evidence'];
         $Evidence = [
            'same_key_per_IP' => $Same,
            'cross_key_global' => $Cross,
            'rollback' => $RollbackState,
         ];
         $JSON = (string) json_encode($Evidence);

         $rollbackControls = ($RollbackState['supported'] ?? false) === false
            || (
               ($RollbackState['signals'] ?? 0) > 0
               && ($RollbackState['active_signals'] ?? 0) > 0
               && ($RollbackState['injected'] ?? false)
               && ($RollbackState['child_reaped'] ?? false)
            );
         $controls = ($Same['signals'] ?? 0) > 0
            && ($Same['active_signals'] ?? 0) > 0
            && ($Same['nested_attempts'] ?? 0) > 0
            && ($Same['child_reaped'] ?? false)
            && ($Cross['signals'] ?? 0) > 0
            && ($Cross['active_signals'] ?? 0) > 0
            && ($Cross['nested_attempts'] ?? 0) > 0
            && ($Cross['child_reaped'] ?? false)
            && $rollbackControls;
         yield new Assertion(
            description: 'commit-race harness executes nested admissions and reaps children',
            fallback: "H7 commit-race harness/control failed: {$JSON}",
         )
            ->expect($controls, Op::Identical, true)
            ->assert();

         $rollbackSecure = ($RollbackState['supported'] ?? false) === false
            || (
               ($RollbackState['signals'] ?? 0) > 0
               && ($RollbackState['active_signals'] ?? 0) > 0
               && ($RollbackState['injected'] ?? false)
               && ($RollbackState['outer_error'] ?? 'missing') === ''
               && ($RollbackState['teardown_error'] ?? 'missing') === ''
               && ($RollbackState['continuation_error'] ?? 'missing') === ''
               && ($RollbackState['reuse_error'] ?? 'missing') === ''
               && ($RollbackState['close_blocked'] ?? false)
               && ($RollbackState['sweep_anchor_intact'] ?? false)
               && ($RollbackState['authority_pair_valid'] ?? false)
               && ($RollbackState['manager_invalidated'] ?? false)
               && ($RollbackState['pair_stayed_invalid'] ?? false)
               && ($RollbackState['manager_restored'] ?? false)
               && ($RollbackState['anchor_intact_after_rollback'] ?? false)
               && ($RollbackState['child_reaped'] ?? false)
               && ($RollbackState['before_cleanup']['outer'] ?? true) === false
               && ($RollbackState['before_cleanup']['outer_authority'] ?? true)
                  === false
               && ($RollbackState['before_cleanup']['committing'] ?? true)
                  === false
               && ($RollbackState['before_cleanup']['anchor_intact'] ?? false)
               && (
                  ($RollbackState['before_cleanup']['clean'] ?? false)
                  || ($RollbackState['before_cleanup']['quarantined'] ?? false)
               )
               && ($RollbackState['converged'] ?? false)
               && ($RollbackState['reuse'] ?? false)
               && ($RollbackState['reuse_released'] ?? false)
               && ($RollbackState['after_cleanup'] ?? null) === [
                  'committing' => false,
                  'converged' => true,
                  'peers' => [],
                  'IPs' => [],
                  'public' => [],
                  'authorities' => 0,
                  'quarantine_tokens' => 0,
                  'managed_quarantines' => 0,
                  'direct_quarantines' => 0,
                  'timer' => 0,
                  'reset_observer' => 0,
                  'timeout_counts' => [],
                  'minimum_timeout' => 0,
                  'connection_timer' => 0,
                  'connection_reset_observer' => 0,
                  'timer_tasks' => 0,
                  'timer_status' => [],
                  'reset_observers' => 0,
                  'reset_recoveries' => 0,
                  'generation_buckets' => 0,
               ]
            );
         $secure = $Same['race'] === null
            && ($Same['iterations'] ?? 0) === 10_000
            && ($Same['signal_error'] ?? 'missing') === ''
            && ($Same['outer_error'] ?? 'missing') === ''
            && ($Same['teardown_error'] ?? 'missing') === ''
            && ($Same['cleanup'] ?? null) === [
               'peers' => [],
               'IPs' => [],
               'public' => [],
               'live' => 0,
               'authorities' => 0,
               'global_authorities' => 0,
               'indexed_authorities' => 0,
               'generation_buckets' => 0,
               'quarantine_tokens' => 0,
               'managed_quarantines' => 0,
               'direct_quarantines' => 0,
               'manager_timer' => 0,
               'manager_reset_observer' => 0,
               'timeout_counts' => [],
               'minimum_timeout' => 0,
               'connection_timer' => 0,
               'connection_reset_observer' => 0,
               'timer_tasks' => 0,
               'timer_status' => [],
               'reset_observers' => 0,
               'reset_recoveries' => 0,
            ]
            && ($Same['reuse_admitted'] ?? false)
            && ($Same['reuse_authorized'] ?? false)
            && ($Same['reuse_error'] ?? 'missing') === ''
            && ($Same['reuse_released'] ?? false)
            && ($Same['reuse_clean'] ?? false)
            && ($Same['generation_buckets'] ?? -1) === 0
            && $Cross['race'] === null
            && ($Cross['iterations'] ?? 0) === 10_000
            && ($Cross['signal_error'] ?? 'missing') === ''
            && ($Cross['outer_error'] ?? 'missing') === ''
            && ($Cross['teardown_error'] ?? 'missing') === ''
            && ($Cross['cleanup'] ?? null) === [
               'peers' => [],
               'IPs' => [],
               'public' => [],
               'live' => 0,
               'authorities' => 0,
               'global_authorities' => 0,
               'indexed_authorities' => 0,
               'generation_buckets' => 0,
               'quarantine_tokens' => 0,
               'managed_quarantines' => 0,
               'direct_quarantines' => 0,
               'manager_timer' => 0,
               'manager_reset_observer' => 0,
               'timeout_counts' => [],
               'minimum_timeout' => 0,
               'connection_timer' => 0,
               'connection_reset_observer' => 0,
               'timer_tasks' => 0,
               'timer_status' => [],
               'reset_observers' => 0,
               'reset_recoveries' => 0,
            ]
            && ($Cross['reuse_admitted'] ?? false)
            && ($Cross['reuse_authorized'] ?? false)
            && ($Cross['reuse_error'] ?? 'missing') === ''
            && ($Cross['reuse_released'] ?? false)
            && ($Cross['reuse_swept'] ?? false)
            && ($Cross['reuse_clean'] ?? false)
            && ($Cross['generation_buckets'] ?? -1) === 0
            && $rollbackSecure;
         yield new Assertion(
            description: 'admission commit is atomic across keys and ledgers',
            fallback: "CONFIRMED H7: UDP admission commit race bypassed a ceiling or corrupted rollback; evidence={$JSON}",
         )
            ->expect($secure, Op::Identical, true)
            ->assert();
      }
      finally {
         $Reset();
         pcntl_signal(SIGALRM, $PreviousAlarm === false ? SIG_DFL : $PreviousAlarm);
         fclose($Socket);
      }
   }),
);
