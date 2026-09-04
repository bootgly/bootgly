<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\CLI;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Reset as TimerReset;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Authority as ConnectionAuthority;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Packages as UDP_Packages;


final class H7ReentrantMirror
{
   /** Connections returned by the destructor's nested admission. */
   public static array $Escaped = [];

   // * Data
   private Connections $Connections;
   private Connection $Target;
   private string $peer;


   /**
    * @param Connections $Connections Admission controller re-entered on destruction.
    * @param Connection $Target Peer closed before nested admission.
    * @param string $peer Admission key reused by the nested call.
    */
   public function __construct (
      Connections $Connections, Connection $Target, string $peer
   )
   {
      $this->Connections = $Connections;
      $this->Target = $Target;
      $this->peer = $peer;
   }

   /** Close and replace the peer while an outer mirror() call is active. */
   public function __destruct ()
   {
      $this->Target->close();
      self::$Escaped[] = $this->Connections->accept($this->peer);

      throw new RuntimeException('reentrant mirror replacement');
   }
}

final class H7TimerMirror
{
   /** Clear the process timer wheel while mirror() releases this value. */
   public function __destruct ()
   {
      Timer::del();
   }
}

final class H7MirrorReplacementOwner
{
   /** Replacement published during owner destruction. */
   public static null|Connection $Replacement = null;
   /** Number of successor generations still published by destructors. */
   public static int $remaining = 0;

   // * Data
   /** @var resource */
   private $Socket;
   private string $peer;


   /** @param resource $Socket Shared UDP socket. */
   public function __construct ($Socket, string $peer)
   {
      $this->Socket = $Socket;
      $this->peer = $peer;
   }

   /** Publish another direct mirror generation under the same key. */
   public function __destruct ()
   {
      if (self::$remaining <= 0) {
         return;
      }
      self::$remaining--;
      self::$Replacement = new Connection($this->Socket, $this->peer);
      if (self::$remaining > 0) {
         self::$Replacement->decoded = new self($this->Socket, $this->peer);
      }
      Connections::$Connections[$this->peer] = self::$Replacement;
   }
}


final class H7ReentrantCloseMirror extends Connection
{
   /** Manager re-entered by each released mirror generation. */
   public static null|Connections $Manager = null;
   /** Successor generations still published by destructors. */
   public static int $remaining = 0;
   /** Current nested manager-close depth. */
   public static int $depth = 0;
   /** Maximum nested manager-close depth observed. */
   public static int $maxDepth = 0;


   /** Publish one successor and re-enter manager close under the same budget. */
   public function __destruct ()
   {
      if (self::$remaining <= 0) {
         return;
      }
      self::$remaining--;
      $Next = new self($this->Socket, $this->id, 0);
      unset($Next->Connection); // @phpstan-ignore unset.possiblyHookedProperty
      Connections::$Connections[$this->id] = $Next;
      unset($Next);
      self::$depth++;
      self::$maxDepth = max(self::$maxDepth, self::$depth);
      try {
         self::$Manager?->close($this->id);
      }
      finally {
         self::$depth--;
      }
   }
}


final class H7CloseManagerOwner
{
   /** Nested server created if the lifecycle guard is bypassed. */
   public static null|UDP_Server_CLI $Nested = null;
   /** Reentrant construction rejection. */
   public static string $error = '';


   /** Attempt to replace manager authority from terminal owner cleanup. */
   public function __destruct ()
   {
      try {
         self::$Nested = new UDP_Server_CLI(Modes::Test);
      }
      catch (Throwable $Throwable) {
         self::$error = $Throwable->getMessage();
      }
   }
}

final class H7GetterStatusConnection extends Connection
{
   // * Data
   /** Number of reads through the stale compatibility hook. */
   public int $reads = 0;
   /** Last status committed through the compatibility setter. */
   public int $state = Connections::STATUS_INITIAL;

   /** Close on first read while returning a stale established status. */
   public int $status {
      get {
         $this->reads++;
         if ($this->reads === 1) {
            $this->close();
         }

         return Connections::STATUS_ESTABLISHED;
      }
      set (int $status) {
         $this->state = $status;
      }
   }
}

final class H7VirtualTimerConnection extends Connection
{
   // * Data
   /** Number of executions of the adversarial virtual getter. */
   public int $reads = 0;

   /** Virtual compatibility property that attempts to hide a new timer. */
   public array $timers {
      get {
         $this->reads++;
         Timer::add(30, function (): void {
            $this->check();
         });

         return [];
      }
      set (array $timers) {
         // Deliberately ignore terminal writes.
      }
   }
}

final class H7UnlistedTimerOwner
{
   // * Data
   private Connection $Connection;


   /** @param Connection $Connection Peer retained by the attempted timer. */
   public function __construct (Connection $Connection)
   {
      $this->Connection = $Connection;
   }

   /** Create a retaining timer without publishing its identifier to the peer. */
   public function __destruct ()
   {
      $Connection = $this->Connection;
      Timer::add(30, static function () use ($Connection): void {
         $Connection->check();
      });
   }
}

class H7LazyTimerBox
{
   /** Connection installed only if the lazy initializer executes. */
   public null|Connection $Connection = null;
}

final class H7IndirectTimerOwner
{
   // * Data
   private Closure $Handler;
   /** @var array<mixed> */
   private array $args = [];


   /**
    * @param Connection $Connection Peer hidden in a callback-owned graph.
    * @param string $mode Static-local, internal-container or resource graph.
    */
   public function __construct (Connection $Connection, string $mode)
   {
      if ($mode === 'static') {
         $this->Handler = static function (
            null|Connection $Set = null
         ): void {
            static $Held = null;
            if ($Set !== null) {
               $Held = $Set;
            }
         };
         ($this->Handler)($Connection);
      }
      else if ($mode === 'internal') {
         $Container = new class([$Connection]) extends ArrayObject {};
         $this->Handler = static function () use ($Container): void {
            $Container->count();
         };
      }
      else if ($mode === 'resource') {
         $Context = stream_context_create([
            'h7' => ['Connection' => $Connection],
         ]);
         $this->Handler = static function () use ($Context): void {
            get_resource_type($Context);
         };
      }
      else if ($mode === 'lazy') {
         $Class = new ReflectionClass(H7LazyTimerBox::class);
         $Proxy = $Class->newLazyGhost(
            static function (H7LazyTimerBox $Box) use ($Connection): void {
               $Box->Connection = $Connection;
            },
         );
         $this->Handler = static function () use ($Proxy): void {
            get_class($Proxy);
         };
      }
      else if ($mode === 'weak') {
         $this->Handler = static function (WeakReference $Weak): void {
            $Retained = $Weak->get();
            if ($Retained instanceof Connection) {
               Timer::add(30, static function () use ($Retained): void {});
            }
         };
         $this->args = [WeakReference::create($Connection)];
      }
      else {
         $Holder = new Connection(
            $Connection->Socket, '127.0.0.99:53199'
         );
         $Holder->Socket = stream_context_create([
            'h7' => ['Connection' => $Connection],
         ]);
         $this->Handler = static function () use ($Holder): void {
            $Holder->check();
         };
      }
   }

   /** Attempt to schedule the callback graph during owner destruction. */
   public function __destruct ()
   {
      Timer::add(30, $this->Handler, $this->args);
   }
}

final class H7NestedCloseOwner
{
   // * Data
   private Connection $Connection;


   /** @param Connection $Connection Nested peer closed by this destructor. */
   public function __construct (Connection $Connection)
   {
      $this->Connection = $Connection;
   }

   /** Re-enter another Connection cleanup while the outer capture is active. */
   public function __destruct ()
   {
      $this->Connection->close();
   }
}

final class H7MutableTimerOwner
{
   // * Data
   private Connection $Connection;
   private bool $args;


   /** @param Connection $Connection Peer attached after Timer::add() returns. */
   public function __construct (Connection $Connection, bool $args)
   {
      $this->Connection = $Connection;
      $this->args = $args;
   }

   /** Mutate a pending handler/argument graph before the cleanup trap exits. */
   public function __destruct ()
   {
      if ($this->args) {
         $Slot = null;
         Timer::add(
            30,
            static function (mixed $Value): void {},
            args: [&$Slot],
         );
         $Slot = $this->Connection;
         return;
      }

      $Holder = new stdClass;
      $Holder->Target = null;
      Timer::add(30, static function () use ($Holder): void {
         isSet($Holder->Target);
      });
      $Holder->Target = $this->Connection;
   }
}


return new Test(
   description: 'It should release central timer and per-IP ownership on exact UDP peer close',
   test: new Assertions(Case: function (): Generator {
      $segments = Display::$segments;
      $Socket = null;
      $RebindClientA = null;
      $RebindClientB = null;
      $GCEnabled = gc_enabled();
      $PreviousAlarm = pcntl_signal_get_handler(SIGALRM);
      Timer::init(static function (): void {});
      gc_disable();
      Display::show(Display::NONE);
      Timer::del();
      H7MirrorReplacementOwner::$Replacement = null;
      H7MirrorReplacementOwner::$remaining = 0;
      H7ReentrantCloseMirror::$Manager = null;
      H7ReentrantCloseMirror::$remaining = 0;
      H7ReentrantCloseMirror::$depth = 0;
      H7ReentrantCloseMirror::$maxDepth = 0;
      H7CloseManagerOwner::$Nested = null;
      H7CloseManagerOwner::$error = '';

      try {
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new Configs(
            host: '127.0.0.1',
            port: 19999,
            workers: 1,
            maxConnections: 2,
            maxConnectionsPerIP: 1,
         ));
         Connections::$stats = false;

         $Socket = stream_socket_server(
            'udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND
         );
         yield new Assertion(description: 'the shared UDP socket is bound')
            ->expect($Socket !== false)
            ->to->be(true)
            ->assert();
         if ($Socket === false) {
            return;
         }

         $SocketProperty = new ReflectionProperty($Server, 'Socket');
         $SocketProperty->setValue($Server, $Socket);
         $Connections = $Server->Connections;

         $TasksProperty = new ReflectionProperty(Timer::class, 'tasks');
         $IPProperty = new ReflectionProperty(Connections::class, 'IPConnections');
         $PeersProperty = new ReflectionProperty(Connections::class, 'Peers');
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
            return $IPProperty->getValue();
         };
         $Peers = static function () use ($PeersProperty): array {
            return $PeersProperty->getValue();
         };

         $peer = '127.0.0.1:53000';
         $Connection = $Connections->accept($peer);
         yield new Assertion(
            description: 'first admitted peer owns one IP slot behind one central timer'
         )
            ->expect(
               [
                  $Connection instanceof Connection,
                  count(Connections::$Connections),
                  $IPs(),
                  $Connection?->timers,
                  $Count(),
               ],
               Op::Identical,
               [true, 1, ['127.0.0.1' => 1], [], 1],
            )
            ->assert();
         if ($Connection instanceof Connection === false) {
            return;
         }

         $serializationThrown = '';
         $serialized = '';
         $Copy = null;
         $copyClosed = false;
         try {
            $serialized = serialize($Connection);
            $Copy = unserialize($serialized);
            if ($Copy instanceof Connection) {
               $copyClosed = $Copy->close();
            }
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $serializationThrown = "{$class}: {$message}";
         }
         yield new Assertion(description: 'admission callback stays outside serialization')
            ->expect(
               [
                  $serializationThrown,
                  $serialized !== '',
                  $Copy instanceof Connection,
                  $copyClosed,
                  Connections::$Connections[$peer] === $Connection,
               ],
               Op::Identical,
               ['', true, true, true, true],
            )
            ->assert();
         unset($Copy);
         gc_collect_cycles();

         $beforeDirectTimer = $Count();
         Connections::$stats = true;
         $LegacyDirect = new Connection($Socket, '127.0.0.12:53109');
         Connections::$stats = false;
         yield new Assertion(description: 'direct extension keeps its legacy expiration timer')
            ->expect(
               [count($LegacyDirect->timers), $Count() - $beforeDirectTimer],
               Op::Identical,
               [1, 1],
            )
            ->assert();
         $LegacyDirect->close();
         yield new Assertion(description: 'direct legacy timer releases on close')
            ->expect($Count())
            ->to->be($beforeDirectTimer)
            ->assert();
         unset($LegacyDirect);

         Connections::$stats = true;
         $HookedStatus = new class($Socket, '127.0.0.12:53110') extends Connection {
            // * Data
            private int $state = Connections::STATUS_INITIAL;

            /** Public legacy status hook that rejects terminal writes. */
            public int $status {
               get {
                  return $this->state;
               }
               set (int $status) {
                  if ($status > Connections::STATUS_ESTABLISHED) {
                     throw new RuntimeException('terminal status denied');
                  }
                  $this->state = $status;
               }
            }
         };
         Connections::$stats = false;
         $hookedStatusThrown = '';
         $hookedStatusClosed = false;
         try {
            $hookedStatusClosed = $HookedStatus->close();
         }
         catch (Throwable $Throwable) {
            $hookedStatusThrown = $Throwable->getMessage();
         }
         yield new Assertion(description: 'throwing public status hook cannot corrupt terminal authority')
            ->expect(
               [
                  $hookedStatusThrown,
                  $hookedStatusClosed,
                  ConnectionAuthority::check($HookedStatus),
                  $HookedStatus->status,
                  $HookedStatus->timers,
                  $Count(),
               ],
               Op::Identical,
               ['', true, false, Connections::STATUS_ESTABLISHED, [], $beforeDirectTimer],
            )
            ->assert();
         unset($HookedStatus);

         $ReentrantStatus = new class($Socket, '127.0.0.12:53113') extends Connection {
            // * Data
            public int $calls = 0;
            private int $state = Connections::STATUS_INITIAL;

            /** Re-enter close whenever terminal status is published. */
            public int $status {
               get {
                  return $this->state;
               }
               set (int $status) {
                  if ($status > Connections::STATUS_ESTABLISHED) {
                     $this->calls++;
                     $this->close();
                  }
                  $this->state = $status;
               }
            }
         };
         $reentrantStatusClosed = $ReentrantStatus->close();
         yield new Assertion(description: 'reentrant status hook cannot recurse through close guard')
            ->expect(
               [
                  $reentrantStatusClosed,
                  $ReentrantStatus->calls,
                  $ReentrantStatus->status,
                  ConnectionAuthority::check($ReentrantStatus),
               ],
               Op::Identical,
               [true, 0, Connections::STATUS_ESTABLISHED, false],
            )
            ->assert();
         unset($ReentrantStatus);

         $LateStatus = new class($Socket, '127.0.0.12:53114') extends Connection {
            // * Data
            public int $terminalWrites = 0;
            private int $state = Connections::STATUS_INITIAL;

            /** Mutate transport state on a second terminal publication. */
            public int $status {
               get {
                  return $this->state;
               }
               set (int $status) {
                  if ($status === Connections::STATUS_CLOSED) {
                     $this->terminalWrites++;
                     if ($this->terminalWrites === 2) {
                        $this->input = str_repeat('late-hook', 10_000);
                        Connections::$Connections[$this->id] = $this;
                        $timer = Timer::add(30, static function (): void {});
                        if ($timer !== false) {
                           $this->timers[] = $timer;
                        }
                     }
                  }
                  $this->state = $status;
               }
            }
         };
         $lateStatusClosed = $LateStatus->close();
         yield new Assertion(description: 'no public hook runs after terminal scrub stability')
            ->expect(
               [
                  $lateStatusClosed,
                  $LateStatus->terminalWrites,
                  $LateStatus->input,
                  $LateStatus->timers,
                  isSet(Connections::$Connections[$LateStatus->id]),
                  ConnectionAuthority::check($LateStatus),
                  $Count(),
               ],
               Op::Identical,
               [true, 0, '', [], false, false, $beforeDirectTimer],
            )
            ->assert();
         unset($LateStatus);

         $GetterCheck = new H7GetterStatusConnection(
            $Socket, '127.0.0.12:53115'
         );
         $GetterExpire = new H7GetterStatusConnection(
            $Socket, '127.0.0.13:53115'
         );
         $GetterLimit = new H7GetterStatusConnection(
            $Socket, '127.0.0.14:53115'
         );
         $GetterExpire->used = time() - 30;
         $GetterLimit->writes = 100;
         unset(Connections::$blacklist[$GetterLimit->ip]);
         $getterCheck = $GetterCheck->check();
         $getterExpire = $GetterExpire->expire(1);
         $getterLimit = $GetterLimit->limit(1);
         yield new Assertion(description: 'status getter cannot revive public peer operations')
            ->expect(
               [
                  $getterCheck,
                  $getterExpire,
                  $getterLimit,
                  $GetterCheck->reads,
                  $GetterExpire->reads,
                  $GetterLimit->reads,
                  $GetterCheck->state,
                  $GetterExpire->state,
                  $GetterLimit->state,
                  isSet(Connections::$blacklist[$GetterLimit->ip]),
                  $Count(),
               ],
               Op::Identical,
               [
                  false,
                  true,
                  true,
                  1,
                  1,
                  1,
                  Connections::STATUS_ESTABLISHED,
                  Connections::STATUS_ESTABLISHED,
                  Connections::STATUS_ESTABLISHED,
                  false,
                  $beforeDirectTimer,
               ],
            )
            ->assert();
         unset($GetterCheck, $GetterExpire, $GetterLimit);

         $TemplateHook = new class($Socket, '127.0.0.12:53116') extends Connection {
            // * Data
            public int $reads = 0;

            /** Mutate an already-read owner on a second hooked stability read. */
            public null|object $Template = null {
               get {
                  $this->reads++;
                  if ($this->reads === 2) {
                     $this->decoded = new stdClass;
                  }

                  return $this->Template;
               }
               set (null|object $Template) {
                  $this->Template = $Template;
               }
            }
         };
         $templateHookClosed = $TemplateHook->close();
         yield new Assertion(description: 'raw stability snapshot cannot trigger owner TOCTOU')
            ->expect(
               [
                  $templateHookClosed,
                  $TemplateHook->reads,
                  $TemplateHook->decoded,
                  ConnectionAuthority::check($TemplateHook),
               ],
               Op::Identical,
               [true, 0, null, false],
            )
            ->assert();
         unset($TemplateHook);

         $UninitializedHook = new class($Socket, '127.0.0.12:53118') extends Connection {
            // * Data
            public int $reads = 0;
            private null|object $Hidden = null;

            /** Attempt a retaining timer while exposing external decoded state. */
            public null|object $decoded {
               get {
                  $this->reads++;
                  Timer::add(30, function (): void {
                     $this->check();
                  });

                  return $this->Hidden;
               }
               set (null|object $decoded) {
                  $this->Hidden = $decoded;
               }
            }
         };
         $UninitializedHook->decoded = new stdClass;
         $uninitializedHookClosed = $UninitializedHook->close();
         $DirectQuarantines = new ReflectionProperty(Connection::class, 'DirectQuarantines');
         yield new Assertion(description: 'virtual decoded hook is opaque to terminal cleanup')
            ->expect(
               [
                  $uninitializedHookClosed,
                  $UninitializedHook->reads,
                  count($DirectQuarantines->getValue()),
                  $Count(),
               ],
               Op::Identical,
               [true, 0, 0, $beforeDirectTimer],
            )
            ->assert();
         unset($UninitializedHook);

         $RefusingHook = new class($Socket, '127.0.0.12:53119') extends Connection {
            // * Config
            public bool $deny = true;

            // * Data
            private null|object $Hidden = null;

            /** Refuse terminal detach while the fixture is armed. */
            public null|object $decoded {
               get {
                  return $this->Hidden;
               }
               set (null|object $decoded) {
                  if ($decoded === null && $this->deny) {
                     throw new RuntimeException('detach denied');
                  }
                  $this->Hidden = $decoded;
               }
            }
         };
         $RefusingHook->decoded = new stdClass;
         $refusingClosed = $RefusingHook->close();
         yield new Assertion(description: 'throwing virtual detach hook is never executed')
            ->expect(
               [
                  $refusingClosed,
                  $RefusingHook->deny,
                  count($DirectQuarantines->getValue()),
                  $Count(),
               ],
               Op::Identical,
               [true, true, 0, $beforeDirectTimer],
            )
            ->assert();
         unset($RefusingHook);

         $NoopHook = new class($Socket, '127.0.0.12:53122') extends Connection {
            // * Config
            public bool $deny = true;

            // * Data
            private null|object $Hidden = null;

            /** Silently ignore terminal detach while armed. */
            public null|object $decoded {
               get {
                  return $this->Hidden;
               }
               set (null|object $decoded) {
                  if ($decoded !== null || $this->deny === false) {
                     $this->Hidden = $decoded;
                  }
               }
            }
         };
         $NoopHook->decoded = new stdClass;
         $noopClosed = $NoopHook->close();
         yield new Assertion(description: 'silent virtual detach hook is never trusted')
            ->expect(
               [
                  $noopClosed,
                  $NoopHook->deny,
                  count($DirectQuarantines->getValue()),
                  $Count(),
               ],
               Op::Identical,
               [true, true, 0, $beforeDirectTimer],
            )
            ->assert();
         unset($NoopHook);

         $VirtualTimers = new H7VirtualTimerConnection(
            $Socket, '127.0.0.12:53123'
         );
         $virtualTimerCounts = [];
         for ($attempt = 0; $attempt < 4; $attempt++) {
            $VirtualTimers->close();
            $virtualTimerCounts[] = $Count();
         }
         yield new Assertion(description: 'virtual timer getter cannot grow terminal retention')
            ->expect(
               [
                  $virtualTimerCounts,
                  $VirtualTimers->reads,
                  count($DirectQuarantines->getValue()),
                  $Count(),
               ],
               Op::Identical,
               [
                  array_fill(0, 4, $beforeDirectTimer),
                  0,
                  0,
                  $beforeDirectTimer,
               ],
            )
            ->assert();
         unset($VirtualTimers);

         $TimerOwner = new Connection($Socket, '127.0.0.12:53120');
         $TimerBomb = new class($TimerOwner) {
            // * Data
            private Connection $Connection;

            /** @param Connection $Connection Peer restored after timer-value destruction. */
            public function __construct (Connection $Connection)
            {
               $this->Connection = $Connection;
            }

            /** Restore payload and a real timer when released. */
            public function __destruct ()
            {
               $this->Connection->input = str_repeat('timer-owner', 6_000);
               $this->Connection->decoded = new stdClass;
               $timer = Timer::add(30, static function (): void {});
               if ($timer !== false) {
                  $this->Connection->timers[] = $timer;
               }
            }
         };
         $TimerOwner->timers = [$TimerBomb]; // @phpstan-ignore assign.propertyType
         unset($TimerBomb);
         $timerOwnerClosed = $TimerOwner->close();
         yield new Assertion(description: 'timer values destruct before terminal verification')
            ->expect(
               [
                  $timerOwnerClosed,
                  $TimerOwner->input,
                  $TimerOwner->decoded,
                  $TimerOwner->timers,
                  $Count(),
               ],
               Op::Identical,
               [true, '', null, [], $beforeDirectTimer],
            )
            ->assert();
         unset($TimerOwner);

         $releaseCalls = 0;
         $CustomRelease = new Connection(
            $Socket,
            '127.0.0.12:53117',
            30,
            static function (Connection $Connection) use (&$releaseCalls): void {
               $releaseCalls++;
               $Connection->input = str_repeat('release-hook', 6_000);
               $Connection->decoded = new stdClass;
               Connections::$Connections[$Connection->id] = $Connection;
               $timer = Timer::add(30, static function (): void {});
               if ($timer !== false) {
                  $Connection->timers[] = $timer;
               }
            },
         );
         $customReleaseClosed = $CustomRelease->close();
         yield new Assertion(description: 'custom release callback is followed by stabilization')
            ->expect(
               [
                  $customReleaseClosed,
                  $releaseCalls,
                  $CustomRelease->input,
                  $CustomRelease->decoded,
                  $CustomRelease->timers,
                  isSet(Connections::$Connections[$CustomRelease->id]),
                  ConnectionAuthority::check($CustomRelease),
                  $Count(),
               ],
               Op::Identical,
               [true, 1, '', null, [], false, false, $beforeDirectTimer],
            )
            ->assert();
         unset($CustomRelease);

         $ReleaseHolder = new class {
            // * Data
            public null|Connection $Target = null;

            /** Mutate and throw when the Release closure drops its last capture. */
            public function __destruct ()
            {
               if ($this->Target instanceof Connection === false) {
                  return;
               }
               $this->Target->input = str_repeat('release-capture', 6_000);
               $this->Target->decoded = new stdClass;
               $timer = Timer::add(30, static function (): void {});
               if ($timer !== false) {
                  $this->Target->timers[] = $timer;
               }
               throw new RuntimeException('release capture destructor');
            }
         };
         $CapturedRelease = static function (Connection $Connection) use ($ReleaseHolder): void {
            $ReleaseHolder->Target = $Connection;
         };
         $CapturedConnection = new Connection(
            $Socket,
            '127.0.0.12:53121',
            30,
            $CapturedRelease,
         );
         unset($ReleaseHolder, $CapturedRelease);
         $capturedThrown = '';
         $capturedClosed = false;
         try {
            $capturedClosed = $CapturedConnection->close();
         }
         catch (Throwable $Throwable) {
            $capturedThrown = $Throwable->getMessage();
         }
         $capturedState = [
            $capturedThrown,
            $capturedClosed,
            $CapturedConnection->input,
            $CapturedConnection->decoded,
            $CapturedConnection->timers,
            count($DirectQuarantines->getValue()),
            $Count(),
         ];
         $Backdate();
         Timer::tick();
         yield new Assertion(description: 'release captures destruct before post-callback scrub')
            ->expect(
               [
                  $capturedState,
                  count($DirectQuarantines->getValue()),
                  $Count(),
               ],
               Op::Identical,
               [['', true, '', null, [], 1, $beforeDirectTimer + 1], 0, $beforeDirectTimer],
            )
            ->assert();
         unset($CapturedConnection);

         $Wrapped = new class($Connection) extends UDP_Packages {};
         $Other = new Connection($Socket, '127.0.0.2:53001');
         $originalOwner = $Wrapped->Connection === $Connection;
         $Clone = clone $Wrapped;
         $cloneOwner = $Clone->Connection === $Connection;
         unset($Clone->Connection);
         $cloneUnset = isSet($Clone->Connection) === false;
         $unsetFastPaths = [
            $Clone->writing($Socket),
            $Clone->fail($Socket, 'read'),
         ];
         $Reference = $Other;
         $Wrapped->Connection =& $Reference;
         $Reference = $Connection;
         $referenceWrite = $Wrapped->Connection === $Connection;
         $Wrapped->Connection = $Other;
         yield new Assertion(description: 'external Package wrappers retain read/write BC')
            ->expect(
               [
                  $originalOwner,
                  $cloneOwner,
                  $cloneUnset,
                  $unsetFastPaths,
                  $referenceWrite,
                  $Wrapped->Connection === $Other,
               ],
               Op::Identical,
               [true, true, true, [true, false], true, true],
            )
            ->assert();
         $Other->close();
         unset($Clone, $Wrapped, $Other, $Reference);

         $Owned = new Connection($Socket, '127.0.0.5:53101');
         $ConcreteClone = clone $Owned;
         $ConcreteClone->fail(null, 'read');
         $A = new Connection($Socket, '127.0.0.6:53102');
         $B = new Connection($Socket, '127.0.0.7:53103');
         $OwnerReference = $B;
         $A->Connection =& $OwnerReference;
         $A->fail(null, 'read');
         yield new Assertion(description: 'concrete fail closes self without following a rebound owner')
            ->expect(
               [$Owned->status, $ConcreteClone->status, $A->status, $B->status],
               Op::Identical,
               [
                  Connections::STATUS_ESTABLISHED,
                  Connections::STATUS_ESTABLISHED,
                  Connections::STATUS_CLOSED,
                  Connections::STATUS_ESTABLISHED,
               ],
            )
            ->assert();
         $ConcreteClone->close();
         $A->close();
         unset($Owned, $ConcreteClone, $A, $B, $OwnerReference);

         // # A concrete Connection owns its immutable transport authority.
         //   Rebinding the public Package owner must never redirect network
         //   output or terminal operations to another authorized peer.
         gc_collect_cycles();
         Lease::drain();
         $Authorities = new ReflectionProperty(Connection::class, 'Authorities');
         $Instances = new ReflectionProperty(Connection::class, 'Instances');
         $GenerationBuckets = new ReflectionProperty(
            Connection::class,
            'GenerationBuckets',
         );
         $rebindBaseline = [
            count($Authorities->getValue()),
            count($Instances->getValue()),
            count($GenerationBuckets->getValue()),
            count($DirectQuarantines->getValue()),
            array_keys(Connections::$Connections),
            array_keys($Peers()),
            $IPs(),
            $Count(),
         ];
         $RebindClientA = stream_socket_server(
            'udp://127.0.0.1:0',
            $rebindCodeA,
            $rebindMessageA,
            STREAM_SERVER_BIND,
         );
         $RebindClientB = stream_socket_server(
            'udp://127.0.0.1:0',
            $rebindCodeB,
            $rebindMessageB,
            STREAM_SERVER_BIND,
         );
         if ($RebindClientA === false || $RebindClientB === false) {
            throw new RuntimeException('Could not bind concrete-owner rebind controls.');
         }
         $rebindPeerA = (string) stream_socket_get_name($RebindClientA, false);
         $rebindPeerB = (string) stream_socket_get_name($RebindClientB, false);
         /**
          * @param resource $Client UDP endpoint drained by this assertion.
          *
          * @return array<string>
          */
         $Receive = static function ($Client): array {
            $payloads = [];
            while (count($payloads) < 8) {
               $read = [$Client];
               $write = null;
               $except = null;
               $selected = stream_select(
                  $read,
                  $write,
                  $except,
                  0,
                  $payloads === [] ? 200_000 : 50_000,
               );
               if ($selected !== 1) {
                  break;
               }
               $payload = stream_socket_recvfrom($Client, 65_535);
               if ($payload === false) {
                  break;
               }
               $payloads[] = $payload;
            }

            sort($payloads);

            return $payloads;
         };

         $RebindB = new Connection($Socket, $rebindPeerB, 0);
         $RebindA = new Connection($Socket, $rebindPeerA, 0);
         $RebindA->Connection = $RebindB;
         $RebindA->Encoder = new class implements ServerEncoder {
            /** Encode the concrete-owner write probe. */
            public static function encode (
               ServerPackages $Package, null|int &$length
            ): string
            {
               $length = 7;

               return 'write-A';
            }
         };
         $rebindWrite = $RebindA->write($Socket);
         $rebindWriting = $RebindA->writing($Socket, 9, 'writing-A');
         $RebindA->reject('reject-A');
         $rebindPayloadsA = $Receive($RebindClientA);
         $rebindPayloadsB = $Receive($RebindClientB);
         yield new Assertion(description: 'concrete write and reject stay bound to self after owner rebind')
            ->expect(
               [
                  $rebindWrite,
                  $rebindWriting,
                  $rebindPayloadsA,
                  $rebindPayloadsB,
                  $RebindA->status,
                  $RebindB->status,
                  ConnectionAuthority::check($RebindA),
                  ConnectionAuthority::check($RebindB),
                  array_keys(Connections::$Connections),
                  array_keys($Peers()),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [
                  true,
                  true,
                  ['reject-A', 'write-A', 'writing-A'],
                  [],
                  Connections::STATUS_CLOSED,
                  Connections::STATUS_ESTABLISHED,
                  false,
                  true,
                  $rebindBaseline[4],
                  $rebindBaseline[5],
                  $rebindBaseline[6],
                  $rebindBaseline[7],
               ],
            )
            ->assert();
         unset($RebindA);

         $FailA = new Connection($Socket, $rebindPeerA, 0);
         $FailA->Connection = $RebindB;
         $errorsBeforeRebindFail = Connections::$errors;
         $rebindFail = $FailA->fail(null, 'read');
         yield new Assertion(description: 'concrete invalid-socket fail closes self after owner rebind')
            ->expect(
               [
                  $rebindFail,
                  $FailA->status,
                  $RebindB->status,
                  ConnectionAuthority::check($FailA),
                  ConnectionAuthority::check($RebindB),
                  Connections::$errors['read'] - $errorsBeforeRebindFail['read'],
                  array_keys($Peers()),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [
                  true,
                  Connections::STATUS_CLOSED,
                  Connections::STATUS_ESTABLISHED,
                  false,
                  true,
                  1,
                  $rebindBaseline[5],
                  $rebindBaseline[6],
                  $rebindBaseline[7],
               ],
            )
            ->assert();
         unset($FailA);

         $CloseA = new Connection($Socket, $rebindPeerA, 0);
         $CloseA->Connection = $RebindB;
         $rebindClose = $CloseA->close();
         yield new Assertion(description: 'concrete close preserves a rebound authorized peer')
            ->expect(
               [
                  $rebindClose,
                  $CloseA->status,
                  $RebindB->status,
                  ConnectionAuthority::check($CloseA),
                  ConnectionAuthority::check($RebindB),
                  array_keys($Peers()),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [
                  true,
                  Connections::STATUS_CLOSED,
                  Connections::STATUS_ESTABLISHED,
                  false,
                  true,
                  $rebindBaseline[5],
                  $rebindBaseline[6],
                  $rebindBaseline[7],
               ],
            )
            ->assert();
         $RebindB->close();
         unset($CloseA, $RebindB);
         gc_collect_cycles();
         Lease::drain();
         yield new Assertion(description: 'concrete owner-rebind probes leave authority and ledgers balanced')
            ->expect(
               [
                  count($Authorities->getValue()),
                  count($Instances->getValue()),
                  count($GenerationBuckets->getValue()),
                  count($DirectQuarantines->getValue()),
                  array_keys(Connections::$Connections),
                  array_keys($Peers()),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               $rebindBaseline,
            )
            ->assert();
         fclose($RebindClientA);
         fclose($RebindClientB);
         $RebindClientA = null;
         $RebindClientB = null;

         $Original = new Connection($Socket, '127.0.0.9:53108');
         $ClosedClone = clone $Original;
         $SerializedLive = unserialize(serialize($Original));
         $ClosedClone->close();
         $cloneWrite = $ClosedClone->writing($Socket, 1, 'C');
         $cloneFail = $ClosedClone->fail(null, 'read');
         $ClosedClone->reject('R');
         $serializedWrite = $SerializedLive instanceof Connection
            ? $SerializedLive->writing($Socket, 1, 'S')
            : null;
         yield new Assertion(description: 'terminal clone cannot act through its live owner')
            ->expect(
               [
                  $cloneWrite,
                  $cloneFail,
                  $serializedWrite,
                  ConnectionAuthority::check($ClosedClone),
                  $SerializedLive instanceof Connection
                     ? ConnectionAuthority::check($SerializedLive)
                     : null,
                  $ClosedClone->status,
                  $Original->status,
               ],
               Op::Identical,
               [
                  false,
                  true,
                  false,
                  false,
                  false,
                  Connections::STATUS_CLOSED,
                  Connections::STATUS_ESTABLISHED,
               ],
         )
            ->assert();
         $Original->close();
         if ($SerializedLive instanceof Connection) {
            $SerializedLive->close();
         }
         unset($Original, $ClosedClone, $SerializedLive);

         $Hooked = new class($Socket, '127.0.0.4:53005') extends Connection {
            // * Data
            private Connection $Owner;

            /** Hooked ownership fixture. */
            public Connection $Connection {
               get {
                  return $this->Owner;
               }
               set (Connection $Connection) {
                  $this->Owner = $Connection;
               }
            }
         };
         $Hooked->input = str_repeat('H7-hook', 8_000);
         yield new Assertion(description: 'a hooked Connection extension closes safely')
            ->expect(
               [$Hooked->close(), $Hooked->status, $Hooked->input],
               Op::Identical,
               [true, Connections::STATUS_CLOSED, ''],
            )
            ->assert();
         $Hooked->Connection = $Connection;
         unset($Hooked);

         // ? The public registry remains a compatibility view. Removing its
         //   entry cannot release the private cap/IP ownership or create a
         //   duplicate peer; the next datagram heals the view.
         unset(Connections::$Connections[$peer]);
         $BlockedAfterMirrorUnset = $Connections->accept('127.0.0.1:53999');
         $Healed = $Connections->accept($peer);
         yield new Assertion(description: 'private admission ownership heals registry mutation')
            ->expect(
               [
                  $BlockedAfterMirrorUnset,
                  $Healed === $Connection,
                  Connections::$Connections[$peer] === $Connection,
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [null, true, true, ['127.0.0.1' => 1], 1],
            )
            ->assert();
         unset($BlockedAfterMirrorUnset, $Healed);

         $EvilMirror = new class {
            /** Throw when the registry releases this adversarial mirror. */
            public function __destruct ()
            {
               throw new RuntimeException('adversarial mirror destructor');
            }
         };
         Connections::$Connections[$peer] = $EvilMirror; // @phpstan-ignore assign.propertyType
         unset($EvilMirror);
         $mirrorThrown = '';
         $Healed = null;
         try {
            $Healed = $Connections->accept($peer);
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $mirrorThrown = "{$class}: {$message}";
         }
         yield new Assertion(description: 'mirror destructor cannot abort registry healing')
            ->expect(
               [
                  $mirrorThrown,
                  $Healed === $Connection,
                  Connections::$Connections[$peer] === $Connection,
               ],
               Op::Identical,
               ['', true, true],
            )
            ->assert();
         unset($Healed);

         $TimerMirror = new H7TimerMirror;
         Connections::$Connections[$peer] = $TimerMirror; // @phpstan-ignore assign.propertyType
         unset($TimerMirror);
         $TimerHealed = $Connections->accept($peer);
         yield new Assertion(description: 'mirror timer reset is rearmed before accept returns')
            ->expect(
               [$TimerHealed === $Connection, $Count()],
               Op::Identical,
               [true, 1],
            )
            ->assert();
         unset($TimerHealed);

         $closerPeer = '127.0.0.2:53009';
         $Victim = $Connections->accept($closerPeer);
         yield new Assertion(description: 'reentrant-mirror victim is admitted')
            ->expect($Victim instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Victim instanceof Connection === false) {
            return;
         }
         $ClosingMirror = new class($Victim) {
            // * Data
            private Connection $Connection;


            /** @param Connection $Connection Peer closed during destruction. */
            public function __construct (Connection $Connection)
            {
               $this->Connection = $Connection;
            }

            /** Close the admitted peer while its mirror is being replaced. */
            public function __destruct ()
            {
               $this->Connection->close();
               throw new RuntimeException('reentrant mirror destructor');
            }
         };
         Connections::$Connections[$closerPeer] = $ClosingMirror; // @phpstan-ignore assign.propertyType
         unset($ClosingMirror);
         $BlockedByHandle = $Connections->accept($closerPeer);
         yield new Assertion(description: 'retained closed handle keeps its exact admission charge')
            ->expect(
               [
                  $Victim->status,
                  $BlockedByHandle,
                  isSet(Connections::$Connections[$closerPeer]),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [
                  Connections::STATUS_CLOSED,
                  null,
                  false,
                  ['127.0.0.1' => 1, '127.0.0.2' => 1],
                  1,
               ],
            )
            ->assert();
         unset($Victim, $BlockedByHandle);
         gc_collect_cycles();
         $Recovered = $Connections->accept($closerPeer);
         yield new Assertion(description: 'same key recovers after retained handle finalization')
            ->expect(
               [
                  $Recovered instanceof Connection,
                  (Connections::$Connections[$closerPeer] ?? null) === $Recovered,
               ],
               Op::Identical,
               [true, true],
            )
            ->assert();
         $Recovered?->close();
         unset($Recovered);

         $stalePeer = '127.0.0.2:53100';
         $Stale = $Connections->accept($stalePeer);
         yield new Assertion(description: 'stale-destructor control peer is admitted')
            ->expect($Stale instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Stale instanceof Connection === false) {
            return;
         }
         $StaleWeak = WeakReference::create($Stale);
         unset($Stale->Connection); // @phpstan-ignore unset.possiblyHookedProperty
         $Replacement = new Connection($Socket, $stalePeer);
         $replacementAuthorized = ConnectionAuthority::check($Replacement);
         Connections::$Connections[$stalePeer] = $Replacement;
         unset($Stale);
         $StaleReplacement = $Connections->accept('127.0.0.2:53106');
         yield new Assertion(description: 'same-key replacement is inert until stale token release')
            ->expect(
               [
                  $StaleWeak->get(),
                  $replacementAuthorized,
                  ConnectionAuthority::check($Replacement),
                  $Replacement->status,
                  isSet(Connections::$Connections[$stalePeer]),
                  $StaleReplacement instanceof Connection,
                  $Count(),
               ],
               Op::Identical,
               [null, false, false, Connections::STATUS_CLOSED, false, true, 1],
            )
            ->assert();
         $StaleReplacement?->close();
         $Replacement->close();
         unset(
            Connections::$Connections[$stalePeer],
            $StaleReplacement,
            $Replacement,
         );

         $mutablePeer = '127.0.0.2:53104';
         $Mutable = $Connections->accept($mutablePeer);
         yield new Assertion(description: 'mutable-peer control is admitted')
            ->expect($Mutable instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Mutable instanceof Connection === false) {
            return;
         }
         $MutableWeak = WeakReference::create($Mutable);
         $Mutable->peer = '127.0.0.9:65500';
         $Mutable->status = Connections::STATUS_CLOSED;
         $mutableClosed = $Mutable->close();
         $BlockedMutable = $Connections->accept('127.0.0.2:53107');
         yield new Assertion(description: 'readonly identity keeps a live mutated peer charged')
            ->expect(
               [
                  $mutableClosed,
                  ConnectionAuthority::check($Mutable),
                  isSet(Connections::$Connections[$mutablePeer]),
                  isSet($Peers()[$mutablePeer]),
                  $BlockedMutable instanceof Connection,
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [
                  true,
                  false,
                  false,
                  true,
                  false,
                  ['127.0.0.1' => 1, '127.0.0.2' => 1],
                  1,
               ],
            )
            ->assert();
         unset($Mutable, $BlockedMutable);
         gc_collect_cycles();
         Lease::drain();
         $MutableReplacement = $Connections->accept('127.0.0.2:53107');
         yield new Assertion(description: 'destroyed mutated peer releases its immutable admission key')
            ->expect(
               [
                  $MutableWeak->get(),
                  isSet($Peers()[$mutablePeer]),
                  $MutableReplacement instanceof Connection,
                  isSet($Peers()['127.0.0.2:53107']),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [null, false, true, true, ['127.0.0.1' => 1, '127.0.0.2' => 1], 1],
            )
            ->assert();
         $MutableReplacement?->close();
         unset($MutableReplacement);

         $directPeer = '127.0.0.8:53105';
         $Direct = new Connection($Socket, $directPeer);
         H7MirrorReplacementOwner::$remaining = 40;
         $Direct->decoded = new H7MirrorReplacementOwner($Socket, $directPeer);
         Connections::$Connections[$directPeer] = $Direct;
         $firstDirectClose = $Connections->close($directPeer);
         $PendingDirect = Connections::$Connections[$directPeer] ?? null;
         $pendingAuthorized = $PendingDirect instanceof Connection
            && ConnectionAuthority::check($PendingDirect);
         $remainingAfterBudget = H7MirrorReplacementOwner::$remaining;
         $directClosed = $Connections->close($directPeer);
         $DirectReplacement = H7MirrorReplacementOwner::$Replacement;
         yield new Assertion(description: 'manager bounds and resumes direct mirror generations')
            ->expect(
               [
                  $firstDirectClose,
                  $pendingAuthorized,
                  $remainingAfterBudget,
                  $directClosed,
                  $Direct->status,
                  $DirectReplacement instanceof Connection,
                  $DirectReplacement?->status,
                  isSet(Connections::$Connections[$directPeer]),
               ],
               Op::Identical,
               [
                  false,
                  false,
                  8,
                  true,
                  Connections::STATUS_CLOSED,
                  true,
                  Connections::STATUS_CLOSED,
                  false,
               ],
            )
            ->assert();
         H7MirrorReplacementOwner::$Replacement = null;
         H7MirrorReplacementOwner::$remaining = 0;
         unset($Direct, $DirectReplacement, $PendingDirect);

         $reentrantPeer = '127.0.0.9:53108';
         H7ReentrantCloseMirror::$Manager = $Connections;
         H7ReentrantCloseMirror::$remaining = 100;
         H7ReentrantCloseMirror::$depth = 0;
         H7ReentrantCloseMirror::$maxDepth = 0;
         $ReentrantClose = new H7ReentrantCloseMirror(
            $Socket,
            $reentrantPeer,
            0,
         );
         Connections::$Connections[$reentrantPeer] = $ReentrantClose;
         unset($ReentrantClose);
         $firstReentrantClose = $Connections->close($reentrantPeer);
         $ReentrantPending = Connections::$Connections[$reentrantPeer] ?? null;
         $firstReentrantState = [
            $firstReentrantClose,
            H7ReentrantCloseMirror::$remaining,
            H7ReentrantCloseMirror::$maxDepth,
            $ReentrantPending instanceof Connection,
            $ReentrantPending instanceof Connection
               && ConnectionAuthority::check($ReentrantPending),
         ];
         unset($ReentrantPending);
         $reentrantCalls = 1;
         $lastReentrantClose = $firstReentrantClose;
         while (
            isSet(Connections::$Connections[$reentrantPeer])
            && $reentrantCalls < 8
         ) {
            $lastReentrantClose = $Connections->close($reentrantPeer);
            $reentrantCalls++;
         }
         yield new Assertion(description: 'nested manager closes share one process budget')
            ->expect(
               [
                  $firstReentrantState,
                  $reentrantCalls,
                  $lastReentrantClose,
                  H7ReentrantCloseMirror::$remaining,
                  H7ReentrantCloseMirror::$depth,
                  H7ReentrantCloseMirror::$maxDepth <= 32,
                  isSet(Connections::$Connections[$reentrantPeer]),
               ],
               Op::Identical,
               [[false, 68, 32, true, false], 4, true, 0, 0, true, false],
            )
            ->assert();
         H7ReentrantCloseMirror::$Manager = null;
         H7ReentrantCloseMirror::$remaining = 0;

         $CurrentManager = new ReflectionProperty(
            Connections::class,
            'CurrentManager',
         );
         $managerBeforeCloseOwner = $CurrentManager->getValue();
         H7CloseManagerOwner::$Nested = null;
         H7CloseManagerOwner::$error = '';
         $managerOwnerPeer = '127.0.0.10:53109';
         $ManagerOwner = new Connection($Socket, $managerOwnerPeer, 0);
         $ManagerOwner->decoded = new H7CloseManagerOwner;
         Connections::$Connections[$managerOwnerPeer] = $ManagerOwner;
         unset($ManagerOwner);
         $managerOwnerClosed = $Connections->close($managerOwnerPeer);
         $ManagerControl = $Connections->accept('127.0.0.10:53110');
         yield new Assertion(description: 'terminal owner cannot replace manager authority')
            ->expect(
               [
                  $managerOwnerClosed,
                  str_contains(H7CloseManagerOwner::$error, 'lifecycle mutation'),
                  H7CloseManagerOwner::$Nested,
                  $CurrentManager->getValue() === $managerBeforeCloseOwner,
                  $ManagerControl instanceof Connection,
               ],
               Op::Identical,
               [true, true, null, true, true],
            )
            ->assert();
         $ManagerControl?->close();
         unset($ManagerControl);
         gc_collect_cycles();
         Lease::drain();

         $managerBeforeDirectOwner = $CurrentManager->getValue();
         H7CloseManagerOwner::$Nested = null;
         H7CloseManagerOwner::$error = '';
         $DirectManagerOwner = $Connections->accept('127.0.0.11:53111');
         yield new Assertion(description: 'direct-close manager-owner control is admitted')
            ->expect($DirectManagerOwner instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($DirectManagerOwner instanceof Connection === false) {
            return;
         }
         $DirectManagerOwnerWeak = WeakReference::create($DirectManagerOwner);
         $DirectManagerOwner->decoded = new H7CloseManagerOwner;
         $directManagerOwnerClosed = $DirectManagerOwner->close();
         $directManagerStable = $CurrentManager->getValue()
            === $managerBeforeDirectOwner;
         unset($DirectManagerOwner);
         gc_collect_cycles();
         Lease::drain();
         $DirectManagerControl = $Connections->accept('127.0.0.11:53112');
         yield new Assertion(description: 'direct terminal scrub cannot replace manager authority')
            ->expect(
               [
                  $directManagerOwnerClosed,
                  str_contains(H7CloseManagerOwner::$error, 'lifecycle mutation'),
                  H7CloseManagerOwner::$Nested,
                  $directManagerStable,
                  $DirectManagerOwnerWeak->get(),
                  $DirectManagerControl instanceof Connection,
               ],
               Op::Identical,
               [true, true, null, true, null, true],
            )
            ->assert();
         $DirectManagerControl?->close();
         unset($DirectManagerControl);
         gc_collect_cycles();
         Lease::drain();

         $managerBeforeLease = $CurrentManager->getValue();
         $LeaseNested = null;
         $leaseError = '';
         $LeaseBoundary = new Connection(
            $Socket,
            '127.0.0.12:53113',
            0,
            static function () use (&$LeaseNested, &$leaseError): void {
               try {
                  $LeaseNested = new UDP_Server_CLI(Modes::Test);
               }
               catch (Throwable $Throwable) {
                  $leaseError = $Throwable->getMessage();
               }
            },
            true,
         );
         $LeaseBoundaryWeak = WeakReference::create($LeaseBoundary);
         $LeaseBoundary->close();
         unset($LeaseBoundary);
         gc_collect_cycles();
         Lease::drain();
         $LeaseControl = $Connections->accept('127.0.0.12:53114');
         yield new Assertion(description: 'lease drain cannot replace manager during admission')
            ->expect(
               [
                  str_contains($leaseError, 'lifecycle mutation'),
                  $LeaseNested,
                  $CurrentManager->getValue() === $managerBeforeLease,
                  $LeaseBoundaryWeak->get(),
                  $LeaseControl instanceof Connection,
               ],
               Op::Identical,
               [true, null, true, null, true],
            )
            ->assert();
         $LeaseControl?->close();
         unset($LeaseControl);
         gc_collect_cycles();
         Lease::drain();

         $managedPeer = '127.0.0.8:53106';
         $Managed = $Connections->accept($managedPeer);
         yield new Assertion(description: 'manager replacement control is admitted')
            ->expect($Managed instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Managed instanceof Connection === false) {
            return;
         }
         $ManagedWeak = WeakReference::create($Managed);
         $ManagedPublic = new Connection($Socket, $managedPeer);
         Connections::$Connections[$managedPeer] = $ManagedPublic;
         $managedClosed = $Connections->close($managedPeer);
         yield new Assertion(description: 'manager closes private and public replacement')
            ->expect(
               [
                  $managedClosed,
                  $Managed->status,
                  $ManagedPublic->status,
                  isSet(Connections::$Connections[$managedPeer]),
                  isSet($Peers()[$managedPeer]),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [
                  true,
                  Connections::STATUS_CLOSED,
                  Connections::STATUS_CLOSED,
                  false,
                  true,
                  ['127.0.0.1' => 1, '127.0.0.8' => 1],
                  1,
               ],
            )
            ->assert();
         unset($Managed, $ManagedPublic);
         gc_collect_cycles();
         Lease::drain();
         yield new Assertion(description: 'destroyed manager replacement releases its admission charge')
            ->expect(
               [
                  $ManagedWeak->get(),
                  isSet($Peers()[$managedPeer]),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [null, false, ['127.0.0.1' => 1], 1],
            )
            ->assert();

         $manualPeer = '127.0.0.14:53111';
         $Manual = $Connections->accept($manualPeer);
         yield new Assertion(description: 'manual-destructor control peer is admitted')
            ->expect($Manual instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Manual instanceof Connection === false) {
            return;
         }
         $ManualWeak = WeakReference::create($Manual);
         $Manual->__destruct();
         $Manual->status = Connections::STATUS_ESTABLISHED;
         $manualWrite = $Manual->writing($Socket, 1, 'M');
         $BlockedManual = $Connections->accept('127.0.0.14:53112');
         yield new Assertion(description: 'manual destructor revokes authority before slot release')
            ->expect(
               [
                  ConnectionAuthority::check($Manual),
                  $manualWrite,
                  $BlockedManual instanceof Connection,
                  isSet($Peers()[$manualPeer]),
                  $IPs(),
               ],
               Op::Identical,
               [false, false, false, true, ['127.0.0.1' => 1, '127.0.0.14' => 1]],
            )
            ->assert();
         unset($Manual, $BlockedManual);
         gc_collect_cycles();
         Lease::drain();
         $ManualReplacement = $Connections->accept('127.0.0.14:53112');
         yield new Assertion(description: 'manual destructor charge releases after real object death')
            ->expect(
               [
                  $ManualWeak->get(),
                  isSet($Peers()[$manualPeer]),
                  $ManualReplacement instanceof Connection,
                  $IPs(),
               ],
               Op::Identical,
               [null, false, true, ['127.0.0.1' => 1, '127.0.0.14' => 1]],
            )
            ->assert();
         $ManualReplacement?->close();
         unset($ManualReplacement);

         $Command = CLI->Commands->find('connections', From: $Server);
         yield new Assertion(
            description: 'the live peer remains serializable by the connections command'
         )
            ->expect($Command?->run())
            ->to->be(true)
            ->assert();

         // ? A global timer reset is public API. Its stale identifier cannot
         //   suppress the supervisor when another peer is later admitted.
         $Second = $Connections->accept('127.0.0.2:53002');
         Timer::del();
         $RejectedAfterReset = $Connections->accept('127.0.0.3:53003');
         yield new Assertion(description: 'full ceiling rejection rearms a reset supervisor')
            ->expect(
               [
                  $Second instanceof Connection,
                  $RejectedAfterReset,
                  count(Connections::$Connections),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [
                  true,
                  null,
                  2,
                  ['127.0.0.1' => 1, '127.0.0.2' => 1],
                  1,
               ],
            )
            ->assert();
         $Second?->close();
         unset($Second, $RejectedAfterReset);

         $ephemeral = '127.0.0.3:53004';
         $Ephemeral = $Connections->accept($ephemeral);
         yield new Assertion(description: 'removed-mirror control peer is admitted')
            ->expect($Ephemeral instanceof Connection)
            ->to->be(true)
            ->assert();
         if ($Ephemeral instanceof Connection === false) {
            return;
         }
         unset(Connections::$Connections[$ephemeral]);
         $Ephemeral->used = time() - 31;
         unset($Ephemeral);
         $Backdate();
         Timer::tick();
         $AfterSweep = $Connections->accept('127.0.0.3:53008');
         $afterSweepAdmitted = $AfterSweep instanceof Connection;
         $AfterSweepWeak = $AfterSweep instanceof Connection
            ? WeakReference::create($AfterSweep)
            : null;
         $AfterSweep?->close();
         yield new Assertion(description: 'closed removed-mirror peer remains charged while retained')
            ->expect(
               [
                  $afterSweepAdmitted,
                  isSet($Peers()['127.0.0.3:53008']),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [true, true, ['127.0.0.1' => 1, '127.0.0.3' => 1], 1],
            )
            ->assert();
         unset($AfterSweep);
         gc_collect_cycles();
         Lease::drain();
         yield new Assertion(description: 'supervisor balances a destroyed removed-mirror peer')
            ->expect(
               [
                  $AfterSweepWeak?->get(),
                  isSet($Peers()['127.0.0.3:53008']),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [null, false, ['127.0.0.1' => 1], 1],
            )
            ->assert();

         $unrelated = Timer::add(
            interval: 5,
            handler: static function (): void {},
         );
         yield new Assertion(
            description: 'the central supervisor coexists with an unrelated timer'
         )
            ->expect([$unrelated !== false, $Count()], Op::Identical, [true, 2])
            ->assert();
         if ($unrelated === false) {
            return;
         }

         // ? An object with the same peer text but no registry identity cannot
         //   remove the admitted object or decrement its IP ownership.
         $Impostor = new Connection($Socket, $peer);
         $Impostor->close();
         yield new Assertion(description: 'an impostor cannot remove the admitted peer')
            ->expect(
               [
                  Connections::$Connections[$peer] === $Connection,
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [true, ['127.0.0.1' => 1], 2],
            )
            ->assert();

         $CycleWeak = null;
         for ($index = 0; $index < 300; $index++) {
            $port = 54_000 + $index;
            $Cycle = new Connection($Socket, "127.0.1.1:{$port}");
            $Cycle->input = str_repeat('cycle', 13_000);
            if ($index === 0) {
               $CycleWeak = WeakReference::create($Cycle);
            }
            $Cycle->close();
            unset($Cycle);
         }
         yield new Assertion(description: 'Connection construction restores required cyclic GC')
            ->expect(gc_enabled())
            ->to->be(true)
            ->assert();
         gc_collect_cycles();
         yield new Assertion(description: 'restored GC collects cleared churn shells')
            ->expect($CycleWeak?->get())
            ->to->be(null)
            ->assert();

         $Weak = WeakReference::create($Connection);
         $Connection->input = str_repeat('H7', 32_000);
         $closed = $Connection->close();
         $errorsBeforeTerminalCalls = Connections::$errors;
         $blacklistBeforeTerminalCalls = Connections::$blacklist;
         $Connection->status = Connections::STATUS_ESTABLISHED;
         $Connection->input = str_repeat('resurrected', 8_000);
         $Connection->output = 'resurrected';
         $Connection->known = 'resurrected';
         Connections::$Connections[$peer] = $Connection;
         $resurrectedTimer = Timer::add(30, static function (): void {});
         if ($resurrectedTimer !== false) {
            $Connection->timers[] = $resurrectedTimer;
         }
         $TerminalWrapper = new class($Connection) extends UDP_Packages {};
         $terminalCalls = [
            $TerminalWrapper->fail($Socket, 'read'),
            $Connection->fail(null, 'read'),
            $Connection->expire(1),
            $Connection->limit(0),
         ];
         unset($TerminalWrapper);
         $reclosed = $Connection->close();
         yield new Assertion(description: 'private terminal authority survives public resurrection')
            ->expect(
               [
                  $resurrectedTimer !== false,
                  $terminalCalls,
                  Connections::$errors === $errorsBeforeTerminalCalls,
                  Connections::$blacklist === $blacklistBeforeTerminalCalls,
                  $reclosed,
                  $Connection->status,
                  $Connection->input,
                  $Connection->output,
                  $Connection->known,
                  $Connection->timers,
                  isSet(Connections::$Connections[$peer]),
                  $Count(),
               ],
               Op::Identical,
               [
                  true,
                  [true, true, true, true],
                  true,
                  true,
                  true,
                  Connections::STATUS_CLOSED,
                  '',
                  '',
                  '',
                  [],
                  false,
                  2,
               ],
            )
            ->assert();

         $Clone = clone $Connection;
         $Clone->Connection = $Clone;
         $Clone->status = Connections::STATUS_ESTABLISHED;
         $Serialized = unserialize(serialize($Connection));
         $serializedTerminal = $Serialized instanceof Connection;
         if ($Serialized instanceof Connection) {
            $Serialized->Connection = $Serialized;
            $Serialized->status = Connections::STATUS_ESTABLISHED;
         }
         $cloneWrite = $Clone->writing($Socket, 1, 'C');
         $serializedWrite = $Serialized instanceof Connection
            ? $Serialized->writing($Socket, 1, 'S')
            : null;
         $ClosingSnapshot = clone $Connection;
         $ClosingSnapshot->Connection = $ClosingSnapshot;
         $ClosingProperty = new ReflectionProperty(Connection::class, 'closing');
         $ClosedProperty = new ReflectionProperty(Connection::class, 'closed');
         $ClosingProperty->setValue($ClosingSnapshot, true);
         $ClosedProperty->setValue($ClosingSnapshot, false);
         $ClosingSnapshot->status = Connections::STATUS_ESTABLISHED;
         $snapshotTimer = Timer::add(30, static function (): void {});
         if ($snapshotTimer !== false) {
            $ClosingSnapshot->timers[] = $snapshotTimer;
         }
         $snapshotClosed = $ClosingSnapshot->close();
         $Clone->close();
         if ($Serialized instanceof Connection) {
            $Serialized->close();
         }
         yield new Assertion(description: 'terminal authority survives clone and serialization snapshots')
            ->expect(
               [
                  $serializedTerminal,
                  $cloneWrite,
                  $serializedWrite,
                  $snapshotTimer !== false,
                  $snapshotClosed,
                  $ClosingSnapshot->status,
                  $ClosingSnapshot->timers,
                  $Count(),
               ],
               Op::Identical,
               [
                  true,
                  false,
                  false,
                  true,
                  true,
                  Connections::STATUS_CLOSED,
                  [$snapshotTimer],
                  3,
               ],
            )
            ->assert();
         if ($snapshotTimer !== false) {
            Timer::del($snapshotTimer);
         }
         unset($Clone, $Serialized, $ClosingSnapshot);

         yield new Assertion(
            description: 'exact close revokes the registry while a live handle stays charged'
         )
            ->expect(
               [
                  $closed,
                  $reclosed,
                  Connections::$Connections,
                  isSet($Peers()[$peer]),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [true, true, [], true, ['127.0.0.1' => 1], 2],
            )
            ->assert();

         Timer::del($unrelated);
         yield new Assertion(
            description: 'the preserved unrelated timer remains independently removable'
         )
            ->expect($Count())
            ->to->be(1)
            ->assert();

         $Impostor->close();
         yield new Assertion(description: 'retained terminal shell stays scrubbed and inert')
            ->expect(
               [
                  $Weak->get() === $Connection,
                  $Connection->input,
                  $Connection->status,
                  ConnectionAuthority::check($Connection),
               ],
               Op::Identical,
               [true, '', Connections::STATUS_CLOSED, false],
            )
            ->assert();
         unset($Impostor, $Connection);
         gc_collect_cycles();
         Lease::drain();
         yield new Assertion(description: 'terminal charge releases only after real object death')
            ->expect(
               [
                  $Weak->get(),
                  isSet($Peers()[$peer]),
                  $IPs(),
                  $Count(),
               ],
               Op::Identical,
               [null, false, [], 0],
            )
            ->assert();

         gc_enable();
         if ($GCEnabled === false) {
            gc_disable();
         }
      }
      finally {
         H7MirrorReplacementOwner::$Replacement = null;
         H7MirrorReplacementOwner::$remaining = 0;
         H7ReentrantCloseMirror::$Manager = null;
         H7ReentrantCloseMirror::$remaining = 0;
         H7ReentrantCloseMirror::$depth = 0;
         H7ReentrantCloseMirror::$maxDepth = 0;
         H7CloseManagerOwner::$Nested = null;
         H7CloseManagerOwner::$error = '';
         foreach (array_values(Connections::$Connections) as $Connection) {
            $Connection->close();
         }
         Connections::$Connections = [];
         unset($Connection);
         $Quarantines = new ReflectionProperty(Connection::class, 'Quarantines');
         $DirectQuarantines = new ReflectionProperty(
            Connection::class,
            'DirectQuarantines',
         );
         $GenerationBuckets = new ReflectionProperty(
            Connection::class,
            'GenerationBuckets',
         );
         gc_collect_cycles();
         Lease::drain();
         Timer::del();
         gc_collect_cycles();
         Lease::drain();
         $remainingAlarm = pcntl_alarm(0);

         $IPProperty = new ReflectionProperty(Connections::class, 'IPConnections');
         $PeersProperty = new ReflectionProperty(Connections::class, 'Peers');
         $PendingProperty = new ReflectionProperty(Lease::class, 'Pending');
         $TasksProperty = new ReflectionProperty(Timer::class, 'tasks');
         $ManagerReset = new ReflectionProperty(Connections::class, 'resetObserver');
         $DirectReset = new ReflectionProperty(Connection::class, 'resetObserver');
         $ResetObservers = new ReflectionProperty(TimerReset::class, 'Observers');
         $ResetRecoveries = new ReflectionProperty(TimerReset::class, 'Recoveries');
         $clean = $IPProperty->getValue() === []
            && $PeersProperty->getValue() === []
            && $PendingProperty->getValue() === []
            && $TasksProperty->getValue() === []
            && $Quarantines->getValue() === []
            && $DirectQuarantines->getValue() === []
            && $GenerationBuckets->getValue() === []
            && $ManagerReset->getValue() === 0
            && $DirectReset->getValue() === 0
            && $ResetObservers->getValue() === []
            && $ResetRecoveries->getValue() === []
            && $remainingAlarm === 0;
         Connections::$blacklist = [];
         Connections::$stats = false;
         pcntl_signal(SIGALRM, $PreviousAlarm === false ? SIG_DFL : $PreviousAlarm);

         if (is_resource($Socket)) {
            fclose($Socket);
         }
         if (is_resource($RebindClientA)) {
            fclose($RebindClientA);
         }
         if (is_resource($RebindClientB)) {
            fclose($RebindClientB);
         }
         if ($GCEnabled) {
            gc_enable();
         }
         Display::show($segments);
         if ($clean === false) {
            $CleanupJSON = json_encode([
               'IPs' => $IPProperty->getValue(),
               'peers' => count($PeersProperty->getValue()),
               'pending' => count($PendingProperty->getValue()),
               'tasks' => count($TasksProperty->getValue()),
               'quarantines' => count($Quarantines->getValue()),
               'direct' => count($DirectQuarantines->getValue()),
               'generation_buckets' => count($GenerationBuckets->getValue()),
               'manager_reset' => $ManagerReset->getValue(),
               'direct_reset' => $DirectReset->getValue(),
               'reset_observers' => count($ResetObservers->getValue()),
               'reset_recoveries' => count($ResetRecoveries->getValue()),
               'alarm' => $remainingAlarm,
            ]);
            throw new RuntimeException(
               "UDP connection test teardown left process state: {$CleanupJSON}"
            );
         }
      }
   })
);
