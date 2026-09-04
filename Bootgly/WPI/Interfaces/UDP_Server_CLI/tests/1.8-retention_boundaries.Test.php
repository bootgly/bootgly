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


final class H7MirrorEscapeOwner
{
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

   /** Publish an active untracked successor while the admitted peer closes. */
   public function __destruct ()
   {
      $Replacement = new Connection($this->Socket, $this->peer, 0);
      $Replacement->input = str_repeat('successor', 1_024);
      Connections::$Connections[$this->peer] = $Replacement;
   }
}


/** Publish one mirror successor while keeping the first terminal scrub unstable. */
final class H7UnstableMirrorEscapeOwner
{
   // * Data
   /** @var resource */
   private $Socket;
   private string $peer;
   private Connection $Connection;
   private int $remaining;
   private bool $publish;


   /**
    * @param resource $Socket Shared UDP socket.
    * @param Connection $Connection Managed peer kept unstable.
    * @param int $remaining Successor owners left after this generation.
    * @param bool $publish Whether this generation publishes the public successor.
    */
   public function __construct (
      $Socket,
      string $peer,
      Connection $Connection,
      int $remaining,
      bool $publish = true,
   )
   {
      $this->Socket = $Socket;
      $this->peer = $peer;
      $this->Connection = $Connection;
      $this->remaining = $remaining;
      $this->publish = $publish;
   }

   /** Publish once, then leave a finite owner chain for a later close pass. */
   public function __destruct ()
   {
      if ($this->publish) {
         $Replacement = new Connection($this->Socket, $this->peer);
         $Replacement->input = str_repeat('unstable-successor', 3_640);
         Connections::$Connections[$this->peer] = $Replacement;
      }
      if ($this->remaining > 0) {
         $this->Connection->decoded = new self(
            $this->Socket,
            $this->peer,
            $this->Connection,
            $this->remaining - 1,
            false,
         );
      }
   }
}


/** Publish a successor when coordinate() releases a transient timer callback. */
final class H7CoordinateMirrorOwner
{
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

   /** Replace the public mirror after the pre-coordinate cleanup snapshot. */
   public function __destruct ()
   {
      $Replacement = new Connection($this->Socket, $this->peer, 0);
      $Replacement->input = 'coordinate-successor';
      Connections::$Connections[$this->peer] = $Replacement;
   }
}


/** Publish a same-key shell after revoking all of its private I/O authority. */
final class H7ClosedMirrorEscapeOwner
{
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

   /** Publish an inert shell that must not pin the managed token. */
   public function __destruct ()
   {
      $Closed = new Connection($this->Socket, $this->peer, 0);
      $Closed->close();
      Connections::$Connections[$this->peer] = $Closed;
   }
}


/** Publish and externally retain two active same-key successor identities. */
final class H7MultipleMirrorEscapeOwner
{
   /** @var array<int,Connection> */
   public static array $Successors = [];

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

   /** Publish two generations while retaining both outside the public mirror. */
   public function __destruct ()
   {
      $First = new Connection($this->Socket, $this->peer, 0);
      Connections::$Connections[$this->peer] = $First;
      self::$Successors[] = $First;

      $Second = new Connection($this->Socket, $this->peer, 0);
      Connections::$Connections[$this->peer] = $Second;
      self::$Successors[] = $Second;
   }
}


/** Publish direct generations that were authorized before managed admission. */
final class H7PreauthorizedMirrorOwner
{
   // * Data
   /** @var array<int,Connection> */
   private array $Connections;
   private string $peer;


   /** @param array<int,Connection> $Connections Preexisting direct objects. */
   public function __construct (array $Connections, string $peer)
   {
      $this->Connections = $Connections;
      $this->peer = $peer;
   }

   /** Publish every retained generation while the managed peer closes. */
   public function __destruct ()
   {
      foreach ($this->Connections as $Connection) {
         Connections::$Connections[$this->peer] = $Connection;
      }
   }
}


/** Direct extension whose virtual close refuses framework cleanup. */
final class H7VirtualCloseConnection extends Connection
{
   // * Data
   /** @var resource */
   private $FixtureSocket;
   private bool $raises;

   // * Metadata
   private int $calls = 0;
   /** @var array<int,array{bool,bool}> */
   private array $observations = [];


   /**
    * @param resource $Socket Shared UDP socket.
    * @param string $peer Immutable direct peer key.
    * @param bool $raises Whether virtual cleanup throws instead of returning.
    */
   public function __construct ($Socket, string $peer, bool $raises)
   {
      $this->FixtureSocket = $Socket;
      $this->raises = $raises;

      parent::__construct($Socket, $peer, 0);
   }

   /** Refuse cleanup after observing the virtual call. */
   public function close (): true
   {
      $this->calls++;
      $this->observations[] = [
         ConnectionAuthority::check($this),
         $this->writing($this->FixtureSocket, 1, 'V'),
      ];
      if ($this->raises) {
         throw new RuntimeException('expected virtual close refusal');
      }

      return true;
   }

   /** Return the number of virtual cleanup attempts. */
   public function count (): int
   {
      return $this->calls;
   }

   /**
    * Return authority and I/O observed inside every virtual cleanup call.
    *
    * @return array<int,array{bool,bool}>
    */
   public function observe (): array
   {
      return $this->observations;
   }

   /** Invoke the concrete parent cleanup during fixture teardown. */
   public function reset (): true
   {
      return parent::close();
   }
}


/** Re-enter same-key admission when permit() revokes a direct object. */
final class H7PermitReentryOwner
{
   /** Manager re-entered by direct-object cleanup. */
   public static null|Connections $Manager = null;
   /** Nested managed generation admitted during outer permit(). */
   public static null|Connection $Nested = null;
   /** Manager-close result observed while the outer commit is guarded. */
   public static bool $closed = true;

   // * Data
   private string $peer;


   public function __construct (string $peer)
   {
      $this->peer = $peer;
   }

   /** Admit the same peer before the outer permit transaction resumes. */
   public function __destruct ()
   {
      self::$closed = self::$Manager?->close($this->peer) ?? true;
      self::$Nested = self::$Manager?->accept($this->peer);
   }
}


/** Re-enter admission from an inherited peer setter during construction. */
final class H7ConstructorRaceConnection extends Connection
{
   /** Manager attacked by the direct constructor hook. */
   public static null|Connections $Manager = null;
   /** Managed same-key object admitted inside the constructor. */
   public static null|Connection $Managed = null;

   // * Data
   private string $target = '';


   public string $peer {
      get {
         return $this->target;
      }
      set (string $peer) {
         $this->target = $peer;
         if (self::$Manager !== null && self::$Managed === null) {
            self::$Managed = self::$Manager->accept($peer);
         }
      }
   }
}


/** Release an existing reservation from a direct constructor peer setter. */
final class H7ConstructorReleaseConnection extends Connection
{
   /** Managed same-key object released by the constructor hook. */
   public static null|Connection $Managed = null;

   // * Data
   private string $target = '';


   public string $peer {
      get {
         return $this->target;
      }
      set (string $peer) {
         $this->target = $peer;
         self::$Managed?->close();
         self::$Managed = null;
         gc_collect_cycles();
         Lease::drain();
      }
   }
}


/** Attempt same-key admission from a virtual expiration getter. */
final class H7ExpirationRaceConnection extends Connection
{
   /** Manager attacked if scheduling reads the public expiration getter. */
   public static null|Connections $Manager = null;
   /** Managed object admitted by an unsafe post-authority getter read. */
   public static null|Connection $Managed = null;
   /** Immutable peer used by the getter attack. */
   public static string $peerTarget = '';
   /** Number of unsafe getter reads. */
   public static int $gets = 0;

   // * Data
   private int $expirationTarget = 0;


   public int $expiration {
      get {
         self::$gets++;
         if (self::$Manager !== null && self::$Managed === null) {
            self::$Managed = self::$Manager->accept(self::$peerTarget);
         }

         return $this->expirationTarget;
      }
      set (int $expiration) {
         $this->expirationTarget = $expiration;
      }
   }
}


/** Direct extension with no framework-owned backing storage for timer IDs. */
final class H7VirtualTimersConnection extends Connection
{
   public array $timers {
      get {
         return [];
      }
      set (array $timers) {
      }
   }
}


/** Publish one direct object only after manager clear() leaves terminal depth. */
final class H7ConstructionDirectMirror extends Connection
{
   /** @var resource|null Shared socket for the destructor-created object. */
   public static $SharedSocket = null;
   /** Direct object created while a replacement manager is constructing. */
   public static null|Connection $Direct = null;


   public function __destruct ()
   {
      if (self::$SharedSocket !== null) {
         self::$Direct = new Connection(
            self::$SharedSocket,
            '127.0.0.45:56045',
            30,
         );
      }
   }
}

final class H7ExistingTimerOwner
{
   // * Data
   private Connection $Connection;
   private stdClass $Holder;


   /** @param stdClass $Holder Object already owned by a live Timer callback. */
   public function __construct (Connection $Connection, stdClass $Holder)
   {
      $this->Connection = $Connection;
      $this->Holder = $Holder;
   }

   /** Attach the closing peer to the pre-existing Timer graph. */
   public function __destruct ()
   {
      $this->Holder->Connection = $this->Connection;
   }
}

final class H7ManagedTimerOwner
{
   /** Timer identifier created during destruction. */
   public static int|false $timer = false;

   // * Data
   private Connection $Connection;


   /** @param Connection $Connection Managed peer retained by the timer. */
   public function __construct (Connection $Connection)
   {
      $this->Connection = $Connection;
   }

   /** Move managed peer ownership into a new Timer callback. */
   public function __destruct ()
   {
      $Connection = $this->Connection;
      self::$timer = Timer::add(30, static function () use ($Connection): void {
         $Connection->check();
      });
   }
}

final class H7ManagedResetOwner
{
   /** Observer identifier created during destruction. */
   public static int $observer = 0;
   /** Number of retained observer executions. */
   public static int $calls = 0;

   // * Data
   private Connection $Connection;


   /** @param Connection $Connection Managed peer retained by the observer. */
   public function __construct (Connection $Connection)
   {
      $this->Connection = $Connection;
   }

   /** Move managed ownership into the Reset observer registry. */
   public function __destruct ()
   {
      $Connection = $this->Connection;
      self::$observer = TimerReset::add(
         static function () use ($Connection): void {
            self::$calls++;
            $Connection->check();
         },
      );
   }
}

final class H7ResurrectionOwner
{
   /** Connection resurrected by cyclic destruction. */
   public static null|Connection $Resurrected = null;

   // * Data
   private Connection $Connection;
   private self $Cycle;


   /** @param Connection $Connection Peer resurrected during cyclic GC. */
   public function __construct (Connection $Connection)
   {
      $this->Connection = $Connection;
      $this->Cycle = $this;
   }

   /** Preserve the peer after its first finalization attempt. */
   public function __destruct ()
   {
      self::$Resurrected = $this->Connection;
   }
}


/** Cyclic application owner whose destructor attacks framework GC boundaries. */
final class H7ThrowingGCCycle
{
   /** Number of destructor executions observed by the regression. */
   public static int $destructions = 0;

   // * Data
   private null|Connection $Connection;
   private self $Cycle;


   /** @param Connection $Connection Terminal peer released during cyclic collection. */
   public function __construct (Connection $Connection)
   {
      $this->Connection = $Connection;
      $this->Cycle = $this;
   }

   /** Release the last peer owner and then attack the framework GC boundary. */
   public function __destruct ()
   {
      self::$destructions++;
      $this->Connection = null;

      throw new RuntimeException('adversarial H7 GC destructor');
   }
}


/** Cyclic Lease capture that attempts manager replacement at finalization. */
final class H7LeaseCaptureCycle
{
   /** Whether destruction remained inside the Lease lifecycle guard. */
   public static bool $guarded = false;
   /** Reentrant manager construction result when the guard fails. */
   public static null|UDP_Server_CLI $Nested = null;
   /** Reentrant manager construction error. */
   public static string $error = '';
   /** @var resource|null Shared socket for direct-construction attack. */
   public static $Socket = null;
   /** Direct object constructed by the capture finalizer. */
   public static null|Connection $Direct = null;

   // * Data
   private self $Cycle;


   public function __construct ()
   {
      $this->Cycle = $this;
   }

   /** Attempt manager replacement when the released closure capture dies. */
   public function __destruct ()
   {
      self::$guarded = Lease::guard();
      if (self::$Socket !== null) {
         self::$Direct = new Connection(
            self::$Socket,
            '127.0.0.47:56047',
            30,
         );
      }
      try {
         self::$Nested = new UDP_Server_CLI(Modes::Test);
      }
      catch (Throwable $Throwable) {
         self::$error = $Throwable->getMessage();
      }
   }
}


/** Acyclic Lease callback capture whose finalizer throws under the guard. */
final class H7LeaseThrowCapture
{
   /** Number of throwing capture finalizations. */
   public static int $destructions = 0;
   /** Whether capture finalization observed the Lease guard. */
   public static bool $guarded = false;


   public function __destruct ()
   {
      self::$destructions++;
      self::$guarded = Lease::guard();

      throw new RuntimeException('expected acyclic Lease capture failure');
   }
}


/** Slow signal-injected cycle used to saturate one finite Lease GC drain. */
final class H7LeaseSignalCycle
{
   /** Number of injected cyclic destructors that actually ran. */
   public static int $destructions = 0;
   /** Every injected cycle must be collected while Lease remains guarded. */
   public static bool $allGuarded = true;

   // * Data
   private self $Cycle;


   public function __construct ()
   {
      $this->Cycle = $this;
   }

   /** Keep each explicit collection pass busy long enough for another signal. */
   public function __destruct ()
   {
      self::$destructions++;
      self::$allGuarded = self::$allGuarded && Lease::guard();
      usleep(5_000);
   }
}


return new Test(
   description: 'Managed UDP leases follow actual Connection lifetime',
   test: new Assertions(Case: function (): Generator {
      $PreviousAlarm = pcntl_signal_get_handler(SIGALRM);
      Timer::init(static function (): void {});
      Timer::del();
      H7MultipleMirrorEscapeOwner::$Successors = [];
      $Socket = stream_socket_server(
         'udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND
      );
      yield new Assertion(description: 'lifetime-boundary UDP socket is bound')
         ->expect($Socket !== false)
         ->to->be(true)
         ->assert();
      if ($Socket === false) {
         return;
      }

      $Peers = new ReflectionProperty(Connections::class, 'Peers');
      $IPConnections = new ReflectionProperty(Connections::class, 'IPConnections');
      $QuarantineTokens = new ReflectionProperty(Connections::class, 'quarantineTokens');
      $LeasePending = new ReflectionProperty(Lease::class, 'Pending');
      $LeasePendingGC = new ReflectionProperty(Lease::class, 'pendingGC');
      $GenerationBuckets = new ReflectionProperty(
         Connection::class,
         'GenerationBuckets',
      );
      $Quarantines = new ReflectionProperty(Connection::class, 'Quarantines');
      $DirectQuarantines = new ReflectionProperty(
         Connection::class,
         'DirectQuarantines',
      );
      $Tasks = new ReflectionProperty(Timer::class, 'tasks');
      $ManagerReset = new ReflectionProperty(Connections::class, 'resetObserver');
      $Committing = new ReflectionProperty(Connections::class, 'committing');
      $DirectReset = new ReflectionProperty(Connection::class, 'resetObserver');
      $ResetObservers = new ReflectionProperty(TimerReset::class, 'Observers');
      $ResetRecoveries = new ReflectionProperty(TimerReset::class, 'Recoveries');
      $SocketProperty = new ReflectionProperty(UDP_Server_CLI::class, 'Socket');

      $Build = static function () use ($Socket, $SocketProperty): Connections {
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 1,
         ));
         $SocketProperty->setValue($Server, $Socket);

         return $Server->Connections;
      };
      $Due = static function () use ($Tasks): void {
         $due = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }
         $Tasks->setValue(null, $due === [] ? [] : [time() - 1 => $due]);
      };
      $Clear = static function (bool $strict = true) use (
         $IPConnections,
         $LeasePending,
         $LeasePendingGC,
         $GenerationBuckets,
         $Quarantines,
         $DirectQuarantines,
         $ManagerReset,
         $Committing,
         $Peers,
         $QuarantineTokens,
         $DirectReset,
         $ResetObservers,
         $ResetRecoveries,
      ): void {
         if (H7ManagedResetOwner::$observer > 0) {
            TimerReset::del(H7ManagedResetOwner::$observer);
            H7ManagedResetOwner::$observer = 0;
         }
         H7PermitReentryOwner::$Manager = null;
         H7PermitReentryOwner::$Nested?->close();
         H7PermitReentryOwner::$Nested = null;
         H7PermitReentryOwner::$closed = true;
         H7ConstructorRaceConnection::$Manager = null;
         H7ConstructorRaceConnection::$Managed?->close();
         H7ConstructorRaceConnection::$Managed = null;
         H7ConstructorReleaseConnection::$Managed?->close();
         H7ConstructorReleaseConnection::$Managed = null;
         H7ExpirationRaceConnection::$Manager = null;
         H7ExpirationRaceConnection::$Managed?->close();
         H7ExpirationRaceConnection::$Managed = null;
         H7ConstructionDirectMirror::$Direct?->close();
         H7ConstructionDirectMirror::$Direct = null;
         H7ConstructionDirectMirror::$SharedSocket = null;
         H7ResurrectionOwner::$Resurrected = null;
         H7LeaseCaptureCycle::$Direct?->close();
         H7LeaseCaptureCycle::$Direct = null;
         H7LeaseCaptureCycle::$Nested = null;
         H7LeaseCaptureCycle::$Socket = null;
         foreach (array_values(Connections::$Connections) as $Connection) {
            $Connection->close();
         }
         Connections::$Connections = [];
         unset($Connection);
         H7MultipleMirrorEscapeOwner::$Successors = [];
         Timer::del();
         gc_collect_cycles();
         Lease::drain();
         $remainingAlarm = pcntl_alarm(0);
         $dirty = $remainingAlarm !== 0
            || $Peers->getValue() !== []
            || $IPConnections->getValue() !== []
            || $QuarantineTokens->getValue() !== []
            || $LeasePending->getValue() !== []
            || $LeasePendingGC->getValue()
            || $GenerationBuckets->getValue() !== []
            || $Quarantines->getValue() !== []
            || $DirectQuarantines->getValue() !== []
            || $ManagerReset->getValue() !== 0
            || $Committing->getValue()
            || $DirectReset->getValue() !== 0
            || $ResetObservers->getValue() !== []
            || $ResetRecoveries->getValue() !== [];
         if ($dirty && $strict) {
            $CleanupJSON = json_encode([
               'alarm' => $remainingAlarm,
               'peers' => count($Peers->getValue()),
               'IPs' => $IPConnections->getValue(),
               'quarantine_tokens' => count($QuarantineTokens->getValue()),
               'pending' => count($LeasePending->getValue()),
               'pending_gc' => $LeasePendingGC->getValue(),
               'generation_buckets' => count($GenerationBuckets->getValue()),
               'quarantines' => count($Quarantines->getValue()),
               'direct_quarantines' => count($DirectQuarantines->getValue()),
               'manager_reset' => $ManagerReset->getValue(),
               'committing' => $Committing->getValue(),
               'direct_reset' => $DirectReset->getValue(),
               'observers' => count($ResetObservers->getValue()),
               'recoveries' => count($ResetRecoveries->getValue()),
            ]);
            throw new RuntimeException(
               "H7 retention teardown left timer state: {$CleanupJSON}"
            );
         }
         if ($dirty) {
            $Peers->setValue(null, []);
            $IPConnections->setValue(null, []);
            $QuarantineTokens->setValue(null, []);
            $LeasePending->setValue(null, []);
            $LeasePendingGC->setValue(null, false);
            $GenerationBuckets->setValue(null, []);
            $Quarantines->setValue(null, []);
            $DirectQuarantines->setValue(null, []);
            $ManagerReset->setValue(null, 0);
            $Committing->setValue(null, false);
            $DirectReset->setValue(null, 0);
            $ResetObservers->setValue(null, []);
            $ResetRecoveries->setValue(null, []);
            Timer::del();
            pcntl_alarm(0);
         }
      };

      try {
         // # Direct successors constructed by a terminal owner start inert,
         //   are withdrawn, and cannot pin or escape the admitted slot.
         $Connections = $Build();
         $mirrorPeer = '127.0.0.1:56001';
         $Mirror = $Connections->accept($mirrorPeer);
         if ($Mirror instanceof Connection === false) {
            throw new RuntimeException('Could not admit mirror-boundary peer.');
         }
         $MirrorReference = WeakReference::create($Mirror);
         $Mirror->decoded = new H7MirrorEscapeOwner($Socket, $mirrorPeer);
         $mirrorClosed = $Mirror->close();
         unset($Mirror);
         gc_collect_cycles();
         Lease::drain();
         $Replacement = Connections::$Connections[$mirrorPeer] ?? null;
         $AfterMirror = $Connections->accept('127.0.0.2:56002');
         yield new Assertion(description: 'terminal successor is inert before slot reuse')
            ->expect(
               [
                  $mirrorClosed,
                  $MirrorReference->get(),
                  $Replacement instanceof Connection,
                  $Replacement instanceof Connection
                     && ConnectionAuthority::check($Replacement),
                  $AfterMirror instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [true, null, false, false, true, 1, ['127.0.0.2' => 1]],
            )
            ->assert();
         $AfterMirror?->close();
         unset($Replacement, $AfterMirror, $Connections);
         $Clear();

         // # Direct objects authorized before the first datagram are revoked
         //   before the manager commits the same immutable peer key.
         $Connections = $Build();
         $preauthorizedPeer = '127.0.0.29:56029';
         $PreauthorizedA = new Connection($Socket, $preauthorizedPeer, 0);
         $PreauthorizedB = new Connection($Socket, $preauthorizedPeer, 0);
         $preauthorizedBefore = [
            ConnectionAuthority::check($PreauthorizedA),
            ConnectionAuthority::check($PreauthorizedB),
         ];
         $PreauthorizedRoot = $Connections->accept($preauthorizedPeer);
         if ($PreauthorizedRoot instanceof Connection === false) {
            throw new RuntimeException('Could not admit preauthorized-key peer.');
         }
         $preauthorizedAfterPermit = [
            ConnectionAuthority::check($PreauthorizedA),
            $PreauthorizedA->status,
            ConnectionAuthority::check($PreauthorizedB),
            $PreauthorizedB->status,
         ];
         $PreauthorizedRootReference = WeakReference::create($PreauthorizedRoot);
         $PreauthorizedRoot->decoded = new H7PreauthorizedMirrorOwner(
            [$PreauthorizedA, $PreauthorizedB],
            $preauthorizedPeer,
         );
         $preauthorizedClosed = $PreauthorizedRoot->close();
         unset($PreauthorizedRoot);
         gc_collect_cycles();
         Lease::drain();
         $AfterPreauthorized = $Connections->accept('127.0.0.30:56030');
         yield new Assertion(description: 'preauthorized hidden generations are revoked at permit')
            ->expect(
               [
                  $preauthorizedBefore,
                  $preauthorizedAfterPermit,
                  $preauthorizedClosed,
                  $PreauthorizedRootReference->get(),
                  ConnectionAuthority::check($PreauthorizedA),
                  ConnectionAuthority::check($PreauthorizedB),
                  $PreauthorizedA->writing($Socket, 1, 'A'),
                  $PreauthorizedB->writing($Socket, 1, 'B'),
                  $AfterPreauthorized instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  [true, true],
                  [false, Connections::STATUS_CLOSED, false, Connections::STATUS_CLOSED],
                  true,
                  null,
                  false,
                  false,
                  false,
                  false,
                  true,
                  1,
                  ['127.0.0.30' => 1],
               ],
            )
            ->assert();
         $AfterPreauthorized?->close();
         unset(
            $PreauthorizedA,
            $PreauthorizedB,
            $AfterPreauthorized,
            $Connections,
         );
         $Clear();

         // # Private authority must be revoked before extension-controlled
         //   virtual cleanup, even when close() refuses or throws.
         $Connections = $Build();
         $virtualPeer = '127.0.0.48:56048';
         $VirtualThrow = new H7VirtualCloseConnection(
            $Socket,
            $virtualPeer,
            true,
         );
         $VirtualNoop = new H7VirtualCloseConnection(
            $Socket,
            $virtualPeer,
            false,
         );
         $virtualBefore = [
            ConnectionAuthority::check($VirtualThrow),
            ConnectionAuthority::check($VirtualNoop),
         ];
         $VirtualManaged = $Connections->accept($virtualPeer);
         $virtualState = [
            $virtualBefore,
            $VirtualManaged instanceof Connection,
            (Connections::$Connections[$virtualPeer] ?? null) === $VirtualManaged,
            $VirtualThrow->count(),
            $VirtualNoop->count(),
            $VirtualThrow->observe(),
            $VirtualNoop->observe(),
            ConnectionAuthority::check($VirtualThrow),
            ConnectionAuthority::check($VirtualNoop),
            $VirtualThrow->writing($Socket, 1, 'T'),
            $VirtualNoop->writing($Socket, 1, 'N'),
            count($Peers->getValue()),
            $IPConnections->getValue(),
         ];
         $VirtualManaged?->close();
         $VirtualThrow->reset();
         $VirtualNoop->reset();
         unset(
            $VirtualManaged,
            $VirtualThrow,
            $VirtualNoop,
            $Connections,
         );
         $Clear();
         yield new Assertion(description: 'permit denies before virtual direct cleanup')
            ->expect(
               $virtualState,
               Op::Identical,
               [
                  [true, true],
                  true,
                  true,
                  1,
                  1,
                  [[false, false]],
                  [[false, false]],
                  false,
                  false,
                  false,
                  false,
                  1,
                  ['127.0.0.48' => 1],
               ],
            )
            ->assert();

         // # Mutating the compatibility peer field cannot retarget network
         //   I/O away from the immutable constructor/admission destination.
         $ImmutableReceiver = stream_socket_server(
            'udp://127.0.0.1:0',
            $immutableCode,
            $immutableMessage,
            STREAM_SERVER_BIND,
         );
         $MutableReceiver = stream_socket_server(
            'udp://127.0.0.1:0',
            $mutableCode,
            $mutableMessage,
            STREAM_SERVER_BIND,
         );
         if ($ImmutableReceiver === false || $MutableReceiver === false) {
            throw new RuntimeException('Could not bind immutable-destination controls.');
         }
         $immutablePeer = (string) stream_socket_get_name(
            $ImmutableReceiver,
            false,
         );
         $mutablePeer = (string) stream_socket_get_name(
            $MutableReceiver,
            false,
         );
         $ImmutableTarget = new Connection($Socket, $immutablePeer, 0);
         $ImmutableTarget->peer = $mutablePeer;
         $immutableWrite = $ImmutableTarget->writing($Socket, 1, 'I');
         $immutableRead = [$ImmutableReceiver];
         $write = null;
         $except = null;
         $immutableSelected = stream_select(
            $immutableRead,
            $write,
            $except,
            0,
            200_000,
         );
         $immutablePayload = $immutableSelected === 1
            ? stream_socket_recvfrom($ImmutableReceiver, 1)
            : false;
         $mutableRead = [$MutableReceiver];
         $write = null;
         $except = null;
         $mutableSelected = stream_select(
            $mutableRead,
            $write,
            $except,
            0,
            50_000,
         );
         $ImmutableTarget->close();
         unset($ImmutableTarget);
         fclose($ImmutableReceiver);
         fclose($MutableReceiver);
         yield new Assertion(description: 'mutable peer field cannot retarget datagram sink')
            ->expect(
               [
                  $immutableWrite,
                  $immutableSelected,
                  $immutablePayload,
                  $mutableSelected,
               ],
               Op::Identical,
               [true, 1, 'I', 0],
            )
            ->assert();
         $Clear();

         // # More preauthorized generations than the remaining admission
         //   budget must reject the first attempt instead of granting early.
         $Connections = $Build();
         $AdmissionBudget = new ReflectionClassConstant(
            Connections::class,
            'ADMISSION_BUDGET',
         );
         $admissionBudget = (int) $AdmissionBudget->getValue();
         $budgetPeer = '127.0.0.35:56035';
         $BudgetGenerations = [];
         for ($index = 0; $index < $admissionBudget; $index++) {
            $BudgetGenerations[] = new Connection($Socket, $budgetPeer, 0);
         }
         $FirstBudgetAdmission = $Connections->accept($budgetPeer);
         $authorizedAfterFirst = 0;
         foreach ($BudgetGenerations as $Generation) {
            $authorizedAfterFirst += (int) ConnectionAuthority::check($Generation);
         }
         $peersAfterFirstBudget = count($Peers->getValue());
         $IPsAfterFirstBudget = $IPConnections->getValue();
         $SecondBudgetAdmission = $Connections->accept($budgetPeer);
         $authorizedAfterSecond = 0;
         foreach ($BudgetGenerations as $Generation) {
            $authorizedAfterSecond += (int) ConnectionAuthority::check($Generation);
         }
         unset($Generation);
         yield new Assertion(description: 'same-key revocation exhaustion rejects before grant')
            ->expect(
               [
                  $admissionBudget,
                  $FirstBudgetAdmission,
                  $authorizedAfterFirst,
                  $peersAfterFirstBudget,
                  $IPsAfterFirstBudget,
                  $SecondBudgetAdmission instanceof Connection,
                  $authorizedAfterSecond,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [8, null, 1, 0, [], true, 0, 1, ['127.0.0.35' => 1]],
            )
            ->assert();
         $SecondBudgetAdmission?->close();
         foreach ($BudgetGenerations as $Generation) {
            $Generation->close();
         }
         unset(
            $Generation,
            $BudgetGenerations,
            $FirstBudgetAdmission,
            $SecondBudgetAdmission,
            $Connections,
         );
         $Clear();

         // # Direct cleanup inside permit() can re-enter the same key. The
         //   nested admission fails closed while the outer commit is guarded.
         $Connections = $Build();
         $permitPeer = '127.0.0.39:56039';
         H7PermitReentryOwner::$Manager = $Connections;
         H7PermitReentryOwner::$Nested = null;
         H7PermitReentryOwner::$closed = true;
         $PermitDirect = new Connection($Socket, $permitPeer, 0);
         $PermitDirect->decoded = new H7PermitReentryOwner($permitPeer);
         $OuterPermit = $Connections->accept($permitPeer);
         $NestedPermit = H7PermitReentryOwner::$Nested;
         $permitState = [
            $OuterPermit instanceof Connection,
            $OuterPermit instanceof Connection
               && ConnectionAuthority::check($OuterPermit),
            (Connections::$Connections[$permitPeer] ?? null) === $OuterPermit,
            ConnectionAuthority::check($PermitDirect),
            H7PermitReentryOwner::$closed,
            $NestedPermit instanceof Connection,
            $NestedPermit instanceof Connection
               && ConnectionAuthority::check($NestedPermit),
            $NestedPermit?->status,
            count($Peers->getValue()),
            $IPConnections->getValue(),
            $Connections->connections,
         ];
         H7PermitReentryOwner::$Manager = null;
         H7PermitReentryOwner::$Nested = null;
         $OuterPermit?->close();
         unset($NestedPermit, $OuterPermit);
         gc_collect_cycles();
         Lease::drain();
         $AfterPermit = $Connections->accept('127.0.0.40:56040');
         yield new Assertion(description: 'permit reentry cannot overwrite admission ledger')
            ->expect(
               [
                  $permitState,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
                  $AfterPermit instanceof Connection,
               ],
               Op::Identical,
               [
                  [
                     true,
                     true,
                     true,
                     false,
                     false,
                     false,
                     false,
                     null,
                     1,
                     ['127.0.0.39' => 1],
                     1,
                  ],
                  1,
                  ['127.0.0.40' => 1],
                  true,
               ],
            )
            ->assert();
         $PermitDirect->close();
         $AfterPermit?->close();
         unset($PermitDirect, $AfterPermit, $Connections);
         $Clear();

         // # A hook can admit the key while a direct constructor is in
         //   flight. The final guard recheck denies authority and its timer.
         $Connections = $Build();
         H7ConstructorRaceConnection::$Manager = $Connections;
         H7ConstructorRaceConnection::$Managed = null;
         $tasksBeforeRace = count(TimerRegistry::snapshot());
         $Race = new H7ConstructorRaceConnection(
            $Socket,
            '127.0.0.31:56031',
            30,
         );
         $RaceManaged = H7ConstructorRaceConnection::$Managed;
         $raceState = [
            $RaceManaged instanceof Connection,
            $RaceManaged instanceof Connection
               && ConnectionAuthority::check($RaceManaged),
            ConnectionAuthority::check($Race),
            $Race->timers,
            count(TimerRegistry::snapshot()) - $tasksBeforeRace,
            count($Peers->getValue()),
            $IPConnections->getValue(),
         ];
         $RaceManaged?->close();
         H7ConstructorRaceConnection::$Managed = null;
         H7ConstructorRaceConnection::$Manager = null;
         unset($RaceManaged);
         gc_collect_cycles();
         Lease::drain();
         $AfterRace = $Connections->accept('127.0.0.32:56032');
         yield new Assertion(description: 'constructor reentry cannot commit stale authority')
            ->expect(
               [
                  $raceState,
                  ConnectionAuthority::check($Race),
                  $Race->writing($Socket, 1, 'R'),
                  $AfterRace instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
                  count(TimerRegistry::snapshot()),
               ],
               Op::Identical,
               [
                  [true, true, false, [], 1, 1, ['127.0.0.31' => 1]],
                  false,
                  false,
                  true,
                  1,
                  ['127.0.0.32' => 1],
                  1,
               ],
            )
            ->assert();
         $Race->close();
         $AfterRace?->close();
         unset($Race, $AfterRace, $Connections);
         $Clear();

         // # Async application work can enter the manager between the direct
         //   constructor's final precheck and private grant. The post-grant
         //   guard must never leave both generations authorized.
         if (
            function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('pcntl_async_signals')
            && function_exists('posix_kill')
         ) {
            $GrantServer = new UDP_Server_CLI(Modes::Test);
            $GrantServer->configure(new Configs(
               host: '127.0.0.1',
               port: 0,
               workers: 1,
               maxConnections: 1,
               maxConnectionsPerIP: 0,
               connectionIdleTimeout: 0,
            ));
            $SocketProperty->setValue($GrantServer, $Socket);
            $Manager = $GrantServer->Connections;
            $Warm = $Manager->accept('127.9.0.1:29999');
            if ($Warm instanceof Connection === false) {
               throw new RuntimeException('Could not warm grant-race fixture.');
            }
            $Warm->close();
            unset($Warm);
            gc_collect_cycles();
            Lease::drain();

            $PreviousAsync = pcntl_async_signals();
            $PreviousUSR1 = pcntl_signal_get_handler(SIGUSR1);
            $active = false;
            $peer = '';
            $Nested = null;
            $Direct = null;
            $signals = 0;
            $activeSignals = 0;
            $nestedAdmissions = 0;
            $dualAuthorities = 0;
            $iterations = 0;
            $signalError = '';
            $childPID = 0;
            $childReaped = false;
            $signalClean = false;
            try {
               pcntl_async_signals(true);
               pcntl_signal(
                  SIGUSR1,
                  static function () use (
                     &$Nested,
                     &$active,
                     &$activeSignals,
                     &$peer,
                     &$signalError,
                     &$signals,
                     $Manager,
                  ): void {
                     $signals++;
                     if ($active === false || $Nested !== null || $signalError !== '') {
                        return;
                     }

                     $activeSignals++;
                     try {
                        $Nested = $Manager->accept($peer);
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
                  while (hrtime(true) < $deadline) {
                     posix_kill($parentPID, SIGUSR1);
                     usleep(20);
                  }
                  posix_kill(getmypid(), SIGKILL);
                  exit(0);
               }
               if ($childPID < 0) {
                  throw new RuntimeException('Could not fork authority grant fixture.');
               }

               $deadline = hrtime(true) + 500_000_000;
               while ($signals === 0 && hrtime(true) < $deadline) {
                  usleep(100);
               }
               if ($signals === 0) {
                  throw new RuntimeException('Authority grant signal fixture did not start.');
               }

               for ($index = 0; $index < 20_000; $index++) {
                  $third = intdiv($index, 250) % 250;
                  $fourth = ($index % 250) + 1;
                  $port = 20_000 + ($index % 40_000);
                  $peer = "127.6.{$third}.{$fourth}:{$port}";
                  $Nested = null;
                  $active = true;
                  $Direct = new Connection($Socket, $peer, 0);
                  $active = false;
                  if ($Nested instanceof Connection) {
                     $nestedAdmissions++;
                     if (
                        ConnectionAuthority::check($Nested)
                        && ConnectionAuthority::check($Direct)
                     ) {
                        $dualAuthorities++;
                     }
                  }

                  $Nested?->close();
                  $Nested = null;
                  $Direct->close();
                  unset($Direct);
                  $Direct = null;
                  gc_collect_cycles();
                  Lease::drain();
                  if (
                     (Connections::$Connections[$peer] ?? null) !== null
                     || isSet($Peers->getValue()[$peer])
                     || $IPConnections->getValue() !== []
                  ) {
                     $diagnostic = json_encode([
                        'public' => isSet(Connections::$Connections[$peer]),
                        'peers' => count($Peers->getValue()),
                        'IPs' => $IPConnections->getValue(),
                     ]);
                     $signalError = "Authority grant cleanup leaked state: {$diagnostic}";
                  }
                  $iterations++;
                  if ($dualAuthorities > 0 || $signalError !== '') {
                     break;
                  }
               }
               if ($activeSignals === 0) {
                  $signalError = "No active signal among {$signals} deliveries.";
               }
               else if ($nestedAdmissions === 0) {
                  $diagnostic = json_encode([
                     'active_signals' => $activeSignals,
                     'errors' => Connections::$errors,
                     'peers' => $Peers->getValue(),
                     'IPs' => $IPConnections->getValue(),
                  ]);
                  $signalError = "No nested admission: {$diagnostic}";
               }
            }
            finally {
               $active = false;
               $Nested?->close();
               $Nested = null;
               $Direct?->close();
               unset($Direct);
               $Direct = null;
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
               unset($Manager, $GrantServer);
               $Clear();
               $signalClean = true;
            }

            yield new Assertion(description: 'post-grant guard prevents async dual authority')
               ->expect(
                  [
                     $signals > 0,
                     $activeSignals > 0,
                     $nestedAdmissions > 0,
                     $dualAuthorities,
                     $iterations,
                     $signalError,
                     $childReaped,
                     $signalClean,
                  ],
                  Op::Identical,
                  [true, true, true, 0, 20_000, '', true, true],
               )
               ->assert();
         }

         // # The initial guard remains authoritative even if a constructor
         //   hook releases the reservation before the final recheck.
         $Connections = $Build();
         $releaseRacePeer = '127.0.0.41:56041';
         $ReleaseRaceManaged = $Connections->accept($releaseRacePeer);
         if ($ReleaseRaceManaged instanceof Connection === false) {
            throw new RuntimeException('Could not admit release-race peer.');
         }
         $ReleaseRaceReference = WeakReference::create($ReleaseRaceManaged);
         H7ConstructorReleaseConnection::$Managed = $ReleaseRaceManaged;
         unset($ReleaseRaceManaged);
         $ReleaseRace = new H7ConstructorReleaseConnection(
            $Socket,
            $releaseRacePeer,
            30,
         );
         $releaseRaceState = [
            H7ConstructorReleaseConnection::$Managed,
            $ReleaseRaceReference->get(),
            ConnectionAuthority::check($ReleaseRace),
            $ReleaseRace->timers,
            count($Peers->getValue()),
            $IPConnections->getValue(),
            count(TimerRegistry::snapshot()),
         ];
         $AfterReleaseRace = $Connections->accept('127.0.0.42:56042');
         yield new Assertion(description: 'initial constructor guard survives released reservation')
            ->expect(
               [
                  $releaseRaceState,
                  $AfterReleaseRace instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  [null, null, false, [], 0, [], 0],
                  true,
                  1,
                  ['127.0.0.42' => 1],
               ],
            )
            ->assert();
         $ReleaseRace->close();
         $AfterReleaseRace?->close();
         unset($ReleaseRace, $AfterReleaseRace, $Connections);
         $Clear();

         // # Legacy direct timer setup uses immutable constructor input and
         //   raw backed storage; virtual hooks cannot re-enter or orphan IDs.
         $Connections = $Build();
         $expirationRacePeer = '127.0.0.43:56043';
         H7ExpirationRaceConnection::$Manager = $Connections;
         H7ExpirationRaceConnection::$Managed = null;
         H7ExpirationRaceConnection::$peerTarget = $expirationRacePeer;
         H7ExpirationRaceConnection::$gets = 0;
         $ExpirationRace = new H7ExpirationRaceConnection(
            $Socket,
            $expirationRacePeer,
            30,
         );
         $expirationRaceState = [
            H7ExpirationRaceConnection::$gets,
            H7ExpirationRaceConnection::$Managed,
            ConnectionAuthority::check($ExpirationRace),
            count($ExpirationRace->timers),
            count(TimerRegistry::snapshot()),
         ];
         $ExpirationRace->close();
         H7ExpirationRaceConnection::$Manager = null;
         unset($ExpirationRace);
         gc_collect_cycles();
         $tasksAfterExpirationRace = count(TimerRegistry::snapshot());
         $VirtualTimers = new H7VirtualTimersConnection(
            $Socket,
            '127.0.0.44:56044',
            30,
         );
         $virtualTimerState = [
            ConnectionAuthority::check($VirtualTimers),
            $VirtualTimers->timers,
            count(TimerRegistry::snapshot()),
         ];
         $VirtualTimers->close();
         unset($VirtualTimers, $Connections);
         yield new Assertion(description: 'direct timer setup bypasses extension hooks safely')
            ->expect(
               [
                  $expirationRaceState,
                  $tasksAfterExpirationRace,
                  $virtualTimerState,
                  count(TimerRegistry::snapshot()),
               ],
               Op::Identical,
               [[0, null, true, 1, 1], 0, [true, [], 1], 0],
            )
            ->assert();
         $Clear();

         // # clear() releases the closed public object after terminal depth
         //   returns to zero, while manager Construction must still deny it.
         $Connections = $Build();
         H7ConstructionDirectMirror::$SharedSocket = $Socket;
         H7ConstructionDirectMirror::$Direct = null;
         $ConstructionMirror = new H7ConstructionDirectMirror(
            $Socket,
            '127.0.0.46:56046',
            0,
         );
         $ConstructionMirrorReference = WeakReference::create($ConstructionMirror);
         Connections::$Connections[$ConstructionMirror->id] = $ConstructionMirror;
         unset($ConstructionMirror);
         $ReplacementServer = new UDP_Server_CLI(Modes::Test);
         $SocketProperty->setValue($ReplacementServer, $Socket);
         $ConstructionDirect = H7ConstructionDirectMirror::$Direct;
         yield new Assertion(description: 'manager clear destructor cannot authorize direct object')
            ->expect(
               [
                  $ConstructionMirrorReference->get(),
                  $ConstructionDirect instanceof Connection,
                  $ConstructionDirect instanceof Connection
                     && ConnectionAuthority::check($ConstructionDirect),
                  $ConstructionDirect?->timers,
                  count(TimerRegistry::snapshot()),
               ],
               Op::Identical,
               [null, true, false, [], 0],
            )
            ->assert();
         $ConstructionDirect?->close();
         H7ConstructionDirectMirror::$Direct = null;
         H7ConstructionDirectMirror::$SharedSocket = null;
         unset($ConstructionDirect, $Connections, $ReplacementServer);
         $Clear();

         // # A public alias under peer A must never revoke managed peer B.
         $CrossServer = new UDP_Server_CLI(Modes::Test);
         $CrossServer->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 2,
            maxConnectionsPerIP: 2,
            connectionIdleTimeout: 1,
         ));
         $SocketProperty->setValue($CrossServer, $Socket);
         $Connections = $CrossServer->Connections;
         $crossPeerA = '127.0.0.36:56036';
         $crossPeerB = '127.0.0.37:56037';
         $CrossA = $Connections->accept($crossPeerA);
         $CrossB = $Connections->accept($crossPeerB);
         if (
            $CrossA instanceof Connection === false
            || $CrossB instanceof Connection === false
         ) {
            throw new RuntimeException('Could not admit cross-key peers.');
         }
         Connections::$Connections[$crossPeerA] = $CrossB;
         $crossAClosed = $CrossA->close();
         $afterDirectAlias = [
            $crossAClosed,
            ConnectionAuthority::check($CrossA),
            $CrossA->status,
            ConnectionAuthority::check($CrossB),
            $CrossB->status,
            (Connections::$Connections[$crossPeerB] ?? null) === $CrossB,
            isSet(Connections::$Connections[$crossPeerA]),
            count($Peers->getValue()),
         ];
         unset($CrossA);
         gc_collect_cycles();
         Lease::drain();
         $foreignAlias = '127.0.0.38:56038';
         Connections::$Connections[$foreignAlias] = $CrossB;
         $foreignClosed = $Connections->close($foreignAlias);
         yield new Assertion(description: 'foreign mirror aliases preserve exact peer authority')
            ->expect(
               [
                  $afterDirectAlias,
                  $foreignClosed,
                  isSet(Connections::$Connections[$foreignAlias]),
                  ConnectionAuthority::check($CrossB),
                  $CrossB->status,
                  (Connections::$Connections[$crossPeerB] ?? null) === $CrossB,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  [
                     true,
                     false,
                     Connections::STATUS_CLOSED,
                     true,
                     Connections::STATUS_ESTABLISHED,
                     true,
                     false,
                     2,
                  ],
                  false,
                  false,
                  true,
                  Connections::STATUS_ESTABLISHED,
                  true,
                  1,
                  ['127.0.0.37' => 1],
               ],
            )
            ->assert();
         $CrossB->close();
         unset($CrossB, $Connections, $CrossServer);
         $Clear();

         // # Keyed buckets may collide, but exact immutable IDs must keep
         //   authority and close operations isolated inside that bucket.
         $Locate = new ReflectionMethod(Connection::class, 'locate');
         $GenerationBucketCount = new ReflectionClassConstant(
            Connection::class,
            'GENERATION_BUCKETS',
         );
         $generationBucketCount = (int) $GenerationBucketCount->getValue();
         $SeenBuckets = [];
         $LocatedBuckets = [];
         $collisionPeerA = '';
         $collisionPeerB = '';
         for ($index = 0; $index <= $generationBucketCount; $index++) {
            $port = 20_000 + $index;
            $candidatePeer = "127.0.0.50:{$port}";
            $bucket = (int) $Locate->invoke(null, $candidatePeer);
            $LocatedBuckets[$bucket] = true;
            if (
               $collisionPeerA === ''
               && isSet($SeenBuckets[$bucket])
            ) {
               $collisionPeerA = $SeenBuckets[$bucket];
               $collisionPeerB = $candidatePeer;
            }
            else if (isSet($SeenBuckets[$bucket]) === false) {
               $SeenBuckets[$bucket] = $candidatePeer;
            }
         }
         if ($collisionPeerA === '' || $collisionPeerB === '') {
            throw new RuntimeException('Could not find keyed authority collision.');
         }
         $CollisionServer = new UDP_Server_CLI(Modes::Test);
         $CollisionServer->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 2,
            maxConnectionsPerIP: 2,
            connectionIdleTimeout: 1,
         ));
         $SocketProperty->setValue($CollisionServer, $Socket);
         $Connections = $CollisionServer->Connections;
         $CollisionB = $Connections->accept($collisionPeerB);
         if ($CollisionB instanceof Connection === false) {
            throw new RuntimeException('Could not admit collision control peer.');
         }
         $CollisionDirectA = new Connection($Socket, $collisionPeerA, 0);
         $CollisionA = $Connections->accept($collisionPeerA);
         yield new Assertion(description: 'keyed bucket collision preserves exact peer isolation')
            ->expect(
               [
                  $generationBucketCount,
                  count($LocatedBuckets) > 1,
                  $collisionPeerA !== $collisionPeerB,
                  $CollisionA instanceof Connection,
                  ConnectionAuthority::check($CollisionDirectA),
                  $CollisionDirectA->status,
                  ConnectionAuthority::check($CollisionB),
                  $CollisionB->status,
                  (Connections::$Connections[$collisionPeerB] ?? null)
                     === $CollisionB,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  1024,
                  true,
                  true,
                  true,
                  false,
                  Connections::STATUS_CLOSED,
                  true,
                  Connections::STATUS_ESTABLISHED,
                  true,
                  2,
                  ['127.0.0.50' => 2],
               ],
            )
            ->assert();
         $CollisionA?->close();
         $CollisionB->close();
         $CollisionDirectA->close();
         unset(
            $CollisionA,
            $CollisionB,
            $CollisionDirectA,
            $Connections,
            $CollisionServer,
         );
         $Clear();

         // # The immutable admission key also denies hidden direct objects
         //   created before close or between close and actual Lease release.
         $Connections = $Build();
         $reservedPeer = '127.0.0.25:56025';
         $ReservedRoot = $Connections->accept($reservedPeer);
         if ($ReservedRoot instanceof Connection === false) {
            throw new RuntimeException('Could not admit reserved-key peer.');
         }
         $HiddenBefore = new Connection($Socket, $reservedPeer, 0);
         $hiddenBeforeAuthorized = ConnectionAuthority::check($HiddenBefore);
         $ReservedRoot->close();
         $HiddenAfter = new Connection($Socket, $reservedPeer, 0);
         $hiddenAfterAuthorized = ConnectionAuthority::check($HiddenAfter);
         unset($ReservedRoot);
         gc_collect_cycles();
         Lease::drain();
         $AfterReserved = $Connections->accept('127.0.0.26:56026');
         yield new Assertion(description: 'reserved peer key denies hidden direct authority')
            ->expect(
               [
                  $hiddenBeforeAuthorized,
                  $hiddenAfterAuthorized,
                  $HiddenBefore->writing($Socket, 1, 'B'),
                  $HiddenAfter->writing($Socket, 1, 'A'),
                  $AfterReserved instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [false, false, false, false, true, 1, ['127.0.0.26' => 1]],
            )
            ->assert();
         $HiddenBefore->close();
         $HiddenAfter->close();
         $AfterReserved?->close();
         unset($HiddenBefore, $HiddenAfter, $AfterReserved, $Connections);
         $Clear();

         // # Every same-peer identity constructed during terminal owners is
         //   inert, including externally retained generations hidden later.
         $Connections = $Build();
         $multiplePeer = '127.0.0.2:56002';
         $MultipleRoot = $Connections->accept($multiplePeer);
         if ($MultipleRoot instanceof Connection === false) {
            throw new RuntimeException('Could not admit multiple-mirror peer.');
         }
         $MultipleRootReference = WeakReference::create($MultipleRoot);
         H7MultipleMirrorEscapeOwner::$Successors = [];
         $MultipleRoot->decoded = new H7MultipleMirrorEscapeOwner(
            $Socket,
            $multiplePeer,
         );
         $multipleRootClosed = $MultipleRoot->close();
         $multipleBefore = [];
         foreach (H7MultipleMirrorEscapeOwner::$Successors as $Successor) {
            $multipleBefore[] = ConnectionAuthority::check($Successor);
         }
         unset($Successor, $MultipleRoot);
         gc_collect_cycles();
         Lease::drain();
         $multipleManagerClosed = $Connections->close($multiplePeer);
         $multipleAfter = [];
         foreach (H7MultipleMirrorEscapeOwner::$Successors as $Successor) {
            $multipleAfter[] = [
               ConnectionAuthority::check($Successor),
               $Successor->status,
            ];
         }
         unset($Successor);
         $AfterMultiple = $Connections->accept('127.0.0.3:56003');
         yield new Assertion(description: 'hidden terminal successors cannot retain authority')
            ->expect(
               [
                  $multipleRootClosed,
                  $multipleBefore,
                  $multipleManagerClosed,
                  $multipleAfter,
                  $MultipleRootReference->get(),
                  $AfterMultiple instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  true,
                  [false, false],
                  false,
                  [
                     [false, Connections::STATUS_ESTABLISHED],
                     [false, Connections::STATUS_CLOSED],
                  ],
                  null,
                  true,
                  1,
                  ['127.0.0.3' => 1],
               ],
            )
            ->assert();
         $AfterMultiple?->close();
         H7MultipleMirrorEscapeOwner::$Successors = [];
         unset($AfterMultiple, $Connections);
         $Clear();

         // # A successor with revoked private authority is inert and must not
         //   convert one managed slot into a permanent false-positive charge.
         $Connections = $Build();
         $closedMirrorPeer = '127.0.0.2:56002';
         $ClosedMirror = $Connections->accept($closedMirrorPeer);
         if ($ClosedMirror instanceof Connection === false) {
            throw new RuntimeException('Could not admit closed-mirror peer.');
         }
         $ClosedMirrorReference = WeakReference::create($ClosedMirror);
         $ClosedMirror->decoded = new H7ClosedMirrorEscapeOwner(
            $Socket,
            $closedMirrorPeer,
         );
         $closedMirrorClosed = $ClosedMirror->close();
         $ClosedSuccessor = Connections::$Connections[$closedMirrorPeer] ?? null;
         unset($ClosedMirror);
         gc_collect_cycles();
         Lease::drain();
         $AfterClosedMirror = $Connections->accept($closedMirrorPeer);
         yield new Assertion(description: 'inert mirror successor cannot pin admission token')
            ->expect(
               [
                  $closedMirrorClosed,
                  $ClosedSuccessor instanceof Connection,
                  $ClosedSuccessor instanceof Connection
                     && ConnectionAuthority::check($ClosedSuccessor),
                  $ClosedMirrorReference->get(),
                  $AfterClosedMirror instanceof Connection,
                  (Connections::$Connections[$closedMirrorPeer] ?? null)
                     === $AfterClosedMirror,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [true, false, false, null, true, true, 1, ['127.0.0.2' => 1]],
            )
            ->assert();
         $AfterClosedMirror?->close();
         unset($ClosedSuccessor, $AfterClosedMirror, $Connections);
         $Clear();

         // # A successor published during an unstable first scrub remains
         //   tied to the original token across every later close attempt.
         $Connections = $Build();
         $unstableMirrorPeer = '127.0.0.2:56002';
         $UnstableMirror = $Connections->accept($unstableMirrorPeer);
         if ($UnstableMirror instanceof Connection === false) {
            throw new RuntimeException('Could not admit unstable-mirror peer.');
         }
         $UnstableMirrorReference = WeakReference::create($UnstableMirror);
         $UnstableMirror->decoded = new H7UnstableMirrorEscapeOwner(
            $Socket,
            $unstableMirrorPeer,
            $UnstableMirror,
            32,
         );
         $firstUnstableClose = $UnstableMirror->close();
         $UnstableSuccessor = Connections::$Connections[$unstableMirrorPeer] ?? null;
         $secondUnstableClose = $UnstableMirror->close();
         $unstableMirrorScrubbed = $UnstableMirror->decoded === null
            && $UnstableMirror->input === ''
            && $UnstableMirror->output === ''
            && $UnstableMirror->known === ''
            && $UnstableMirror->timers === [];
         unset($UnstableMirror);
         gc_collect_cycles();
         Lease::drain();
         $BlockedUnstableMirror = $Connections->accept('127.0.0.3:56003');
         $taskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $taskCount += count($tasks);
         }
         yield new Assertion(description: 'unstable terminal successor is inert before slot reuse')
            ->expect(
               [
                  $firstUnstableClose,
                  $secondUnstableClose,
                  $unstableMirrorScrubbed,
                  $UnstableSuccessor instanceof Connection,
                  $UnstableSuccessor instanceof Connection
                     && ConnectionAuthority::check($UnstableSuccessor),
                  strlen($UnstableSuccessor?->input ?? ''),
                  $UnstableMirrorReference->get() instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
                  $BlockedUnstableMirror instanceof Connection,
                  $taskCount,
               ],
               Op::Identical,
               [
                  true,
                  true,
                  true,
                  false,
                  false,
                  0,
                  false,
                  1,
                  ['127.0.0.3' => 1],
                  true,
                  1,
               ],
            )
            ->assert();
         unset($BlockedUnstableMirror, $UnstableSuccessor, $Connections);
         $Clear();

         // # coordinate() may release transient Timer callbacks. Mirror and
         //   baseline identities must be read after that destructor boundary.
         $Connections = $Build();
         $coordinatePeer = '127.0.0.3:56003';
         $Coordinate = $Connections->accept($coordinatePeer);
         if ($Coordinate instanceof Connection === false) {
            throw new RuntimeException('Could not admit coordinate-mirror peer.');
         }
         $CoordinateReference = WeakReference::create($Coordinate);
         $CoordinateOwner = new H7CoordinateMirrorOwner($Socket, $coordinatePeer);
         $coordinateTimer = Timer::add(
            30,
            static function () use ($CoordinateOwner): void {},
         );
         unset($CoordinateOwner);
         if ($coordinateTimer === false) {
            throw new RuntimeException('Could not arm coordinate-mirror timer.');
         }
         $QuarantineTimer = new ReflectionProperty(
            Connection::class,
            'quarantineTimer',
         );
         $QuarantineTimer->setValue(null, $coordinateTimer);
         unset(Connections::$Connections[$coordinatePeer]);
         unset($Coordinate);
         gc_collect_cycles();
         Lease::drain();
         $CoordinateSuccessor = Connections::$Connections[$coordinatePeer] ?? null;
         $AfterCoordinate = $Connections->accept('127.0.0.4:56004');
         yield new Assertion(description: 'destruction-only coordinate successor starts inert')
            ->expect(
               [
                  TimerRegistry::check($coordinateTimer),
                  $CoordinateSuccessor instanceof Connection,
                  $CoordinateSuccessor instanceof Connection
                     && ConnectionAuthority::check($CoordinateSuccessor),
                  $CoordinateSuccessor?->input,
                  $CoordinateReference->get(),
                  $AfterCoordinate instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  false,
                  false,
                  false,
                  null,
                  null,
                  true,
                  1,
                  ['127.0.0.4' => 1],
               ],
            )
            ->assert();
         $AfterCoordinate?->close();
         unset($AfterCoordinate, $CoordinateSuccessor, $Connections);
         $Clear();

         // # A stable terminal peer retained only by an unreachable cycle
         //   still needs one supervisor when configured idle expiry is zero.
         $ZeroServer = new UDP_Server_CLI(Modes::Test);
         $ZeroServer->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
         ));
         $SocketProperty->setValue($ZeroServer, $Socket);
         $Connections = $ZeroServer->Connections;
         $CycleOnly = $Connections->accept('127.0.0.5:56005');
         if ($CycleOnly instanceof Connection === false) {
            throw new RuntimeException('Could not admit zero-idle cycle peer.');
         }
         $CycleOnlyReference = WeakReference::create($CycleOnly);
         gc_disable();
         $CycleHolder = new stdClass;
         $CycleHolder->Self = $CycleHolder;
         $CycleHolder->Connection = $CycleOnly;
         $CycleOnly->close();
         $cycleOwnerControl = $CycleHolder->Connection === $CycleOnly;
         unset($CycleOnly, $CycleHolder);
         $cycleTaskCount = 0;
         foreach ($Tasks->getValue() as $tasks) {
            $cycleTaskCount += count($tasks);
         }
         $cycleRetained = [
            gc_enabled(),
            $cycleOwnerControl,
            count($Peers->getValue()),
            $IPConnections->getValue(),
            count($QuarantineTokens->getValue()),
            $cycleTaskCount,
         ];
         $Due();
         Timer::tick();
         gc_enable();
         $AfterCycle = $Connections->accept('127.0.0.6:56006');
         yield new Assertion(description: 'zero-idle cycle is reclaimed by terminal supervisor')
            ->expect(
               [
                  $cycleRetained,
                  $CycleOnlyReference->get(),
                  $AfterCycle instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
                  count(TimerRegistry::snapshot()),
               ],
               Op::Identical,
               [
                  [false, true, 1, ['127.0.0.5' => 1], 1, 1],
                  null,
                  true,
                  1,
                  ['127.0.0.6' => 1],
                  0,
               ],
            )
            ->assert();
         $AfterCycle?->close();
         unset($AfterCycle, $Connections, $ZeroServer);
         $Clear();

         // # A saturated reset notification still runs the infrastructure
         //   recovery tier after the last nested full-wheel deletion.
         $ZeroServer = new UDP_Server_CLI(Modes::Test);
         $ZeroServer->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
         ));
         $SocketProperty->setValue($ZeroServer, $Socket);
         $Connections = $ZeroServer->Connections;
         $ResetCycle = $Connections->accept('127.0.0.23:56023');
         if ($ResetCycle instanceof Connection === false) {
            throw new RuntimeException('Could not admit reset-recovery peer.');
         }
         $ResetCycleReference = WeakReference::create($ResetCycle);
         gc_disable();
         $ResetCycleHolder = new stdClass;
         $ResetCycleHolder->Self = $ResetCycleHolder;
         $ResetCycleHolder->Connection = $ResetCycle;
         $ResetCycle->close();
         unset($ResetCycle, $ResetCycleHolder);
         $NotifyBudget = new ReflectionClassConstant(
            TimerReset::class,
            'NOTIFY_BUDGET',
         );
         $notifyBudget = (int) $NotifyBudget->getValue();
         $FloodObservers = [];
         for ($index = 0; $index < $notifyBudget + 32; $index++) {
            $FloodObservers[] = TimerReset::add(static function (): void {});
         }
         $nestedResets = 0;
         $FloodObservers[] = TimerReset::add(
            static function () use (&$nestedResets): void {
               $nestedResets++;
               Timer::del();
            },
         );
         $sealedObserver = $ManagerReset->getValue();
         TimerReset::del($sealedObserver);
         $sealedRecoveryRetained = $sealedObserver > 0
            && isSet($ResetObservers->getValue()[$sealedObserver])
            && isSet($ResetRecoveries->getValue()[$sealedObserver]);
         Timer::del();
         $resetTaskCount = count(TimerRegistry::snapshot());
         $resetRecoveryState = [
            $nestedResets,
            $sealedRecoveryRetained,
            $ResetCycleReference->get() instanceof Connection,
            count($Peers->getValue()),
            $IPConnections->getValue(),
            count($QuarantineTokens->getValue()),
            $resetTaskCount,
         ];
         foreach ($FloodObservers as $observer) {
            TimerReset::del($observer);
         }
         unset($observer);
         $FloodObservers = [];
         gc_enable();
         $Due();
         Timer::tick();
         Lease::drain();
         $AfterResetCycle = $Connections->accept('127.0.0.24:56024');
         yield new Assertion(description: 'saturated reset restores and runs zero-idle supervisor')
            ->expect(
               [
                  $resetRecoveryState,
                  [
                     $ResetCycleReference->get(),
                     $AfterResetCycle instanceof Connection,
                     count($Peers->getValue()),
                     $IPConnections->getValue(),
                  ],
               ],
               Op::Identical,
               [
                  [1, true, false, 1, ['127.0.0.23' => 1], 1, 1],
                  [null, true, 1, ['127.0.0.24' => 1]],
               ],
            )
            ->assert();
         $AfterResetCycle?->close();
         unset($AfterResetCycle, $Connections, $ZeroServer);
         $Clear();

         // # A live Timer mutated during close remains the sole strong owner;
         //   removing it permits the queued lease to release on next admission.
         $Connections = $Build();
         $Timed = $Connections->accept('127.0.0.3:56003');
         if ($Timed instanceof Connection === false) {
            throw new RuntimeException('Could not admit Timer-boundary peer.');
         }
         $TimedReference = WeakReference::create($Timed);
         $Holder = new stdClass;
         $Holder->Connection = null;
         $evilTimer = Timer::add(
            30,
            static function () use ($Holder): void {
               if ($Holder->Connection instanceof Connection) {
                  $Holder->Connection->input = str_repeat('retained', 125_000);
               }
            },
         );
         $Timed->decoded = new H7ExistingTimerOwner($Timed, $Holder);
         $Timed->close();
         unset($Timed, $Holder);
         gc_collect_cycles();
         $TimerBlocked = $Connections->accept('127.0.0.4:56004');
         $Due();
         Timer::tick();
         $retainedBytes = strlen($TimedReference->get()?->input ?? '');
         if ($evilTimer !== false) {
            Timer::del($evilTimer);
         }
         gc_collect_cycles();
         $AfterTimer = $Connections->accept('127.0.0.4:56004');
         yield new Assertion(description: 'Timer ownership keeps and then releases exact lease')
            ->expect(
               [
                  $TimerBlocked,
                  $retainedBytes,
                  $TimedReference->get(),
                  $AfterTimer instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [null, 1_000_000, null, true, 1, ['127.0.0.4' => 1]],
            )
            ->assert();
         $AfterTimer?->close();
         unset($TimerBlocked, $AfterTimer, $Connections);
         $Clear();

         // # A mutable callback may close and retain its peer before rearm.
         $Connections = $Build();
         $Executing = $Connections->accept('127.0.0.5:56005');
         if ($Executing instanceof Connection === false) {
            throw new RuntimeException('Could not admit executing Timer peer.');
         }
         $ExecutingReference = WeakReference::create($Executing);
         $ExecutingHolder = new stdClass;
         $ExecutingHolder->Connection = null;
         $ExecutingWeak = WeakReference::create($Executing);
         $executingTimer = Timer::add(
            30,
            static function () use ($ExecutingHolder, $ExecutingWeak): void {
               $Connection = $ExecutingWeak->get();
               if ($Connection instanceof Connection === false) {
                  return;
               }
               $Connection->close();
               $ExecutingHolder->Connection = $Connection;
               $Connection->input = str_repeat('executing', 111);
            },
         );
         $Due();
         Timer::tick();
         unset($Executing, $ExecutingHolder, $ExecutingWeak);
         gc_collect_cycles();
         $ExecutingBlocked = $Connections->accept('127.0.0.6:56006');
         $executingBytes = strlen($ExecutingReference->get()?->input ?? '');
         if ($executingTimer !== false) {
            Timer::del($executingTimer);
         }
         gc_collect_cycles();
         $AfterExecuting = $Connections->accept('127.0.0.6:56006');
         yield new Assertion(description: 'executing Timer ownership follows actual lifetime')
            ->expect(
               [
                  $ExecutingBlocked,
                  $executingBytes,
                  $ExecutingReference->get(),
                  $AfterExecuting instanceof Connection,
               ],
               Op::Identical,
               [null, 999, null, true],
            )
            ->assert();
         $AfterExecuting?->close();
         unset($ExecutingBlocked, $AfterExecuting, $Connections);
         $Clear();

         // # An unrelated Timer graph does not own the closed peer and cannot
         //   pin its slot merely because it is large or contains an object.
         $Connections = $Build();
         $Unrelated = $Connections->accept('127.0.0.7:56007');
         if ($Unrelated instanceof Connection === false) {
            throw new RuntimeException('Could not admit unrelated control peer.');
         }
         $UnrelatedReference = WeakReference::create($Unrelated);
         $unrelatedTimer = Timer::add(
            30,
            static function (
               DateTimeImmutable $Date, stdClass $State, array $values
            ): void {},
            [new DateTimeImmutable, (object) ['counter' => 0], range(1, 4_200)],
         );
         $Unrelated->close();
         unset($Unrelated);
         gc_collect_cycles();
         $AfterUnrelated = $Connections->accept('127.0.0.8:56008');
         yield new Assertion(description: 'unrelated Timer does not retain admission lease')
            ->expect(
               [
                  $unrelatedTimer !== false,
                  $UnrelatedReference->get(),
                  $AfterUnrelated instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [true, null, true, 1, ['127.0.0.8' => 1]],
            )
            ->assert();
         $AfterUnrelated?->close();
         unset($AfterUnrelated, $Connections);
         $Clear();

         // # New Timer and Reset owners retain the object naturally; deleting
         //   the registry owner releases the same token without graph scans.
         $Connections = $Build();
         $ManagedTimer = $Connections->accept('127.0.0.9:56009');
         if ($ManagedTimer instanceof Connection === false) {
            throw new RuntimeException('Could not admit managed Timer peer.');
         }
         $ManagedTimerReference = WeakReference::create($ManagedTimer);
         H7ManagedTimerOwner::$timer = false;
         $ManagedTimer->decoded = new H7ManagedTimerOwner($ManagedTimer);
         $ManagedTimer->close();
         unset($ManagedTimer);
         gc_collect_cycles();
         $ManagedTimerBlocked = $Connections->accept('127.0.0.10:56010');
         if (H7ManagedTimerOwner::$timer !== false) {
            Timer::del(H7ManagedTimerOwner::$timer);
         }
         gc_collect_cycles();
         $AfterManagedTimer = $Connections->accept('127.0.0.10:56010');
         yield new Assertion(description: 'new Timer owner follows managed lease lifetime')
            ->expect(
               [
                  $ManagedTimerBlocked,
                  $ManagedTimerReference->get(),
                  $AfterManagedTimer instanceof Connection,
               ],
               Op::Identical,
               [null, null, true],
            )
            ->assert();
         $AfterManagedTimer?->close();
         unset($ManagedTimerBlocked, $AfterManagedTimer, $Connections);
         $Clear();

         $Connections = $Build();
         $ManagedReset = $Connections->accept('127.0.0.11:56011');
         if ($ManagedReset instanceof Connection === false) {
            throw new RuntimeException('Could not admit managed Reset peer.');
         }
         $ManagedResetReference = WeakReference::create($ManagedReset);
         H7ManagedResetOwner::$calls = 0;
         H7ManagedResetOwner::$observer = 0;
         $ManagedReset->decoded = new H7ManagedResetOwner($ManagedReset);
         $ManagedReset->close();
         unset($ManagedReset);
         gc_collect_cycles();
         $ResetBlocked = $Connections->accept('127.0.0.12:56012');
         TimerReset::notify();
         TimerReset::del(H7ManagedResetOwner::$observer);
         H7ManagedResetOwner::$observer = 0;
         gc_collect_cycles();
         $AfterReset = $Connections->accept('127.0.0.12:56012');
         yield new Assertion(description: 'Reset observer follows managed lease lifetime')
            ->expect(
               [
                  $ResetBlocked,
                  H7ManagedResetOwner::$calls,
                  $ManagedResetReference->get(),
                  $AfterReset instanceof Connection,
               ],
               Op::Identical,
               [null, 1, null, true],
            )
            ->assert();
         $AfterReset?->close();
         unset($ResetBlocked, $AfterReset, $Connections);
         $Clear();

         // # A release callback can finalize another deferred Connection.
         //   The newly queued Lease belongs to the next stable drain.
         $firstReleases = 0;
         $secondReleases = 0;
         $SecondRelease = static function () use (&$secondReleases): void {
            $secondReleases++;
         };
         $Second = new Connection(
            $Socket,
            '127.0.0.21:56021',
            0,
            $SecondRelease,
            true,
         );
         $SecondReference = WeakReference::create($Second);
         $Second->close();
         $Holder = new stdClass;
         $Holder->Connection = $Second;
         unset($Second, $SecondRelease);
         $FirstRelease = static function () use (
            &$firstReleases,
            $Holder,
         ): void {
            $firstReleases++;
            $Holder->Connection = null;
            Lease::drain();
         };
         $First = new Connection(
            $Socket,
            '127.0.0.20:56020',
            0,
            $FirstRelease,
            true,
         );
         $FirstReference = WeakReference::create($First);
         $First->close();
         unset($First, $FirstRelease, $Holder);
         $pendingBeforeDrain = count($LeasePending->getValue());
         Lease::drain();
         $pendingAfterFirst = count($LeasePending->getValue());
         $releasesAfterFirst = [$firstReleases, $secondReleases];
         Lease::drain();
         yield new Assertion(description: 'reentrant Lease queue survives for the next drain')
            ->expect(
               [
                  $pendingBeforeDrain,
                  $pendingAfterFirst,
                  $releasesAfterFirst,
                  $firstReleases,
                  $secondReleases,
                  $FirstReference->get(),
                  $SecondReference->get(),
                  count($LeasePending->getValue()),
               ],
               Op::Identical,
               [1, 1, [1, 0], 1, 1, null, null, 0],
            )
            ->assert();

         // # One throwing callback and its throwing acyclic capture cannot
         //   abort an independent tuple later in the same Lease snapshot.
         $safeReleaseCalls = 0;
         $throwReleaseCalls = 0;
         $SafeRelease = static function () use (&$safeReleaseCalls): void {
            $safeReleaseCalls++;
         };
         $SafeBoundary = new Connection(
            $Socket,
            '127.0.0.33:56033',
            0,
            $SafeRelease,
            true,
         );
         $SafeBoundary->close();
         unset($SafeRelease, $SafeBoundary);
         H7LeaseThrowCapture::$destructions = 0;
         H7LeaseThrowCapture::$guarded = false;
         $ThrowCapture = new H7LeaseThrowCapture;
         $ThrowRelease = static function () use (
            &$throwReleaseCalls,
            $ThrowCapture,
         ): void {
            $throwReleaseCalls++;
            throw new RuntimeException('expected Lease callback failure');
         };
         $ThrowBoundary = new Connection(
            $Socket,
            '127.0.0.34:56034',
            0,
            $ThrowRelease,
            true,
         );
         $ThrowBoundary->close();
         unset($ThrowCapture, $ThrowRelease, $ThrowBoundary);
         $throwPendingBefore = count($LeasePending->getValue());
         $throwDrainError = '';
         try {
            Lease::drain();
         }
         catch (Throwable $Throwable) {
            $throwDrainError = $Throwable->getMessage();
         }
         yield new Assertion(description: 'throwing Lease tuples remain isolated')
            ->expect(
               [
                  $throwPendingBefore,
                  $throwDrainError,
                  $throwReleaseCalls,
                  $safeReleaseCalls,
                  H7LeaseThrowCapture::$destructions,
                  H7LeaseThrowCapture::$guarded,
                  count($LeasePending->getValue()),
               ],
               Op::Identical,
               [2, '', 1, 1, 1, true, 0],
            )
            ->assert();

         // # Closure captures, including self-cycles, must destruct before
         //   Lease lowers its lifecycle guard.
         H7LeaseCaptureCycle::$guarded = false;
         H7LeaseCaptureCycle::$Nested = null;
         H7LeaseCaptureCycle::$error = '';
         H7LeaseCaptureCycle::$Socket = $Socket;
         H7LeaseCaptureCycle::$Direct = null;
         $Cycle = new H7LeaseCaptureCycle;
         $CycleRelease = static function () use ($Cycle): void {};
         $LeaseBoundary = new Connection(
            $Socket,
            '127.0.0.22:56022',
            0,
            $CycleRelease,
            true,
         );
         $LeaseBoundary->close();
         unset($Cycle, $CycleRelease, $LeaseBoundary);
         $cyclePendingBefore = count($LeasePending->getValue());
         Lease::drain();
         yield new Assertion(description: 'cyclic Lease captures destruct inside lifecycle guard')
            ->expect(
               [
                  $cyclePendingBefore,
                  H7LeaseCaptureCycle::$guarded,
                  H7LeaseCaptureCycle::$Nested,
                  str_contains(H7LeaseCaptureCycle::$error, 'lifecycle mutation'),
                  H7LeaseCaptureCycle::$Direct instanceof Connection,
                  H7LeaseCaptureCycle::$Direct instanceof Connection
                     && ConnectionAuthority::check(H7LeaseCaptureCycle::$Direct),
                  H7LeaseCaptureCycle::$Direct?->timers,
                  count($LeasePending->getValue()),
                  $LeasePendingGC->getValue(),
                  count(TimerRegistry::snapshot()),
               ],
               Op::Identical,
               [1, true, null, true, true, false, [], 0, false, 0],
            )
            ->assert();
         H7LeaseCaptureCycle::$Direct?->close();
         H7LeaseCaptureCycle::$Direct = null;
         H7LeaseCaptureCycle::$Nested = null;
         H7LeaseCaptureCycle::$Socket = null;
         $Clear();

         // # Continuous cyclic work exhausts one finite GC drain. The carry
         //   guard stays fail-closed until the signal producer is reaped.
         if (
            function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('pcntl_async_signals')
            && function_exists('posix_kill')
         ) {
            $PreviousAsync = pcntl_async_signals();
            $PreviousUSR1 = pcntl_signal_get_handler(SIGUSR1);
            $PreviousGC = gc_enabled();
            $signalCycles = 0;
            $childPID = 0;
            $childReaped = false;
            $destructionsBeforeFinalDrain = 0;
            $signalQueuedAfterDrain = false;
            $firstBudgetState = [];
            try {
               H7LeaseSignalCycle::$destructions = 0;
               H7LeaseSignalCycle::$allGuarded = true;
               pcntl_async_signals(true);
               pcntl_signal(
                  SIGUSR1,
                  static function () use (&$signalCycles): void {
                     $signalCycles++;
                     if (Lease::guard()) {
                        $Cycle = new H7LeaseSignalCycle;
                        unset($Cycle);
                     }
                  },
                  false,
               );
               $Cycle = new H7LeaseSignalCycle;
               $BudgetRelease = static function () use ($Cycle): void {};
               $BudgetBoundary = new Connection(
                  $Socket,
                  '127.0.0.27:56027',
                  0,
                  $BudgetRelease,
                  true,
               );
               $BudgetBoundary->close();
               unset($Cycle, $BudgetRelease, $BudgetBoundary);
               // ! Connection construction enables GC by contract. Disable
               //   it only after the dead Lease is queued, before the child
               //   can inject cycles that belong to the guarded drain.
               gc_disable();
               $parentPID = getmypid();
               $childPID = pcntl_fork();
               if ($childPID === 0) {
                  pcntl_signal(SIGTERM, SIG_DFL, false);
                  pcntl_async_signals(true);
                  $deadline = hrtime(true) + 1_000_000_000;
                  while (hrtime(true) < $deadline) {
                     posix_kill($parentPID, SIGUSR1);
                     usleep(100);
                  }
                  posix_kill(getmypid(), SIGKILL);
                  exit(0);
               }
               if ($childPID < 0) {
                  throw new RuntimeException('Could not fork Lease GC fixture.');
               }
               $deadline = hrtime(true) + 200_000_000;
               while ($signalCycles === 0 && hrtime(true) < $deadline) {
                  usleep(100);
               }
               if ($signalCycles === 0) {
                  throw new RuntimeException('Lease GC signal fixture did not start.');
               }

               Lease::drain();
               $tasksBeforeGuardedDirect = count(TimerRegistry::snapshot());
               $GuardedDirect = new Connection(
                  $Socket,
                  '127.0.0.28:56028',
                  30,
               );
               // @ The constructor restores GC globally; keep automatic
               //   collection disabled until the carry drain is complete.
               gc_disable();
               $firstBudgetState = [
                  $signalCycles > 0,
                  $LeasePendingGC->getValue(),
                  Lease::guard(),
                  ConnectionAuthority::check($GuardedDirect),
                  $tasksBeforeGuardedDirect,
                  count(TimerRegistry::snapshot()),
                  H7LeaseSignalCycle::$allGuarded,
               ];
               $GuardedDirect->close();
               unset($GuardedDirect);
               $signalsAfterDrain = $signalCycles;
               $deadline = hrtime(true) + 200_000_000;
               while (
                  $signalCycles === $signalsAfterDrain
                  && hrtime(true) < $deadline
               ) {
                  usleep(100);
               }
               $signalQueuedAfterDrain = $signalCycles > $signalsAfterDrain;
            }
            finally {
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
               $destructionsBeforeFinalDrain =
                  H7LeaseSignalCycle::$destructions;
               Lease::drain();
               pcntl_signal(
                  SIGUSR1,
                  $PreviousUSR1 === false ? SIG_DFL : $PreviousUSR1,
                  false,
               );
               pcntl_async_signals($PreviousAsync);
               if ($PreviousGC) {
                  gc_enable();
               }
               else {
                  gc_disable();
               }
            }
            yield new Assertion(description: 'exhausted Lease GC remains fail-closed')
               ->expect(
                  [
                     $firstBudgetState,
                     $childReaped,
                     $signalQueuedAfterDrain,
                     $LeasePendingGC->getValue(),
                     Lease::guard(),
                     H7LeaseSignalCycle::$destructions
                        > $destructionsBeforeFinalDrain,
                     H7LeaseSignalCycle::$allGuarded,
                     count(TimerRegistry::snapshot()),
                  ],
                  Op::Identical,
                  [
                     [true, true, true, false, 0, 0, true],
                     true,
                     true,
                     false,
                     false,
                     true,
                     true,
                     0,
                  ],
               )
               ->assert();
            $Clear();
         }

         // # Cyclic resurrection cannot race the lease release callback.
         $Connections = $Build();
         $Resurrecting = $Connections->accept('127.0.0.13:56013');
         if ($Resurrecting instanceof Connection === false) {
            throw new RuntimeException('Could not admit resurrection peer.');
         }
         $ResurrectionReference = WeakReference::create($Resurrecting);
         H7ResurrectionOwner::$Resurrected = null;
         $Resurrecting->decoded = new H7ResurrectionOwner($Resurrecting);
         $Resurrecting->close();
         unset($Resurrecting);
         gc_collect_cycles();
         Lease::drain();
         $ResurrectionBlocked = $Connections->accept('127.0.0.14:56014');
         $resurrectedBeforeRelease =
            $ResurrectionReference->get() instanceof Connection;
         H7ResurrectionOwner::$Resurrected = null;
         gc_collect_cycles();
         $AfterResurrection = $Connections->accept('127.0.0.14:56014');
         yield new Assertion(description: 'resurrection delays lease release until true death')
            ->expect(
               [
                  $resurrectedBeforeRelease,
                  $ResurrectionBlocked,
                  $ResurrectionReference->get(),
                  $AfterResurrection instanceof Connection,
               ],
               Op::Identical,
               [true, null, null, true],
            )
            ->assert();
         $AfterResurrection?->close();
         unset($ResurrectionBlocked, $AfterResurrection, $Connections);
         $Clear();

         // # A throwing cyclic destructor cannot escape any manager-owned GC
         //   boundary or suppress the queued admission-token drain.
         H7ThrowingGCCycle::$destructions = 0;
         $Connections = $Build();
         $closePeer = '127.0.0.15:56015';
         $CloseGC = $Connections->accept($closePeer);
         if ($CloseGC instanceof Connection === false) {
            throw new RuntimeException('Could not admit close-GC peer.');
         }
         $CloseReference = WeakReference::create($CloseGC);
         $CloseGC->close();
         $CloseCycle = new H7ThrowingGCCycle($CloseGC);
         $CloseGC->callbacks[] = $CloseCycle;
         gc_disable();
         unset($CloseGC, $CloseCycle);
         $closeError = '';
         $closeResult = false;
         try {
            $closeResult = $Connections->close($closePeer);
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $closeError = "{$class}: {$message}";
         }
         finally {
            gc_enable();
         }
         $closeDestructions = H7ThrowingGCCycle::$destructions;
         $closeDestroyed = $CloseReference->get() === null;
         $closePeers = $Peers->getValue();
         $closeIPs = $IPConnections->getValue();
         unset($Connections);
         $Clear();

         $Connections = $Build();
         $acceptPeer = '127.0.0.16:56016';
         $AcceptGC = $Connections->accept($acceptPeer);
         if ($AcceptGC instanceof Connection === false) {
            throw new RuntimeException('Could not admit accept-GC peer.');
         }
         $AcceptReference = WeakReference::create($AcceptGC);
         $AcceptGC->close();
         $AcceptCycle = new H7ThrowingGCCycle($AcceptGC);
         $AcceptGC->callbacks[] = $AcceptCycle;
         gc_disable();
         unset($AcceptGC, $AcceptCycle);
         $acceptError = '';
         $AcceptedGC = null;
         try {
            $AcceptedGC = $Connections->accept($acceptPeer);
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $acceptError = "{$class}: {$message}";
         }
         finally {
            gc_enable();
         }
         $acceptDestructions = H7ThrowingGCCycle::$destructions;
         $acceptDestroyed = $AcceptReference->get() === null;
         $acceptRecovered = $AcceptedGC instanceof Connection
            && (Connections::$Connections[$acceptPeer] ?? null) === $AcceptedGC;
         $acceptPeers = count($Peers->getValue());
         $acceptIPs = $IPConnections->getValue();
         $AcceptedGC?->close();
         unset($AcceptedGC, $Connections);
         $Clear();

         $Connections = $Build();
         $sweepPeer = '127.0.0.18:56018';
         $SweepGC = $Connections->accept($sweepPeer);
         if ($SweepGC instanceof Connection === false) {
            throw new RuntimeException('Could not admit sweep-GC peer.');
         }
         $SweepReference = WeakReference::create($SweepGC);
         $SweepGC->used = time() - 2;
         $SweepCycle = new H7ThrowingGCCycle($SweepGC);
         $SweepGC->callbacks[] = $SweepCycle;
         gc_disable();
         unset($SweepGC, $SweepCycle);
         $Sweep = new ReflectionMethod(Connections::class, 'sweep');
         $sweepError = '';
         try {
            $Sweep->invoke(null);
         }
         catch (Throwable $Throwable) {
            $class = $Throwable::class;
            $message = $Throwable->getMessage();
            $sweepError = "{$class}: {$message}";
         }
         finally {
            gc_enable();
         }
         yield new Assertion(description: 'throwing GC destructors cannot escape lease boundaries')
            ->expect(
               [
                  $closeError,
                  $closeResult,
                  $closeDestructions,
                  $closeDestroyed,
                  $closePeers,
                  $closeIPs,
                  $acceptError,
                  $acceptDestructions,
                  $acceptDestroyed,
                  $acceptRecovered,
                  $acceptPeers,
                  $acceptIPs,
                  $sweepError,
                  H7ThrowingGCCycle::$destructions,
                  $SweepReference->get(),
                  $Peers->getValue(),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  '',
                  true,
                  1,
                  true,
                  [],
                  [],
                  '',
                  2,
                  true,
                  true,
                  1,
                  ['127.0.0.16' => 1],
                  '',
                  3,
                  null,
                  [],
                  [],
               ],
            )
            ->assert();
         unset($Connections);
      }
      finally {
         $Clear(false);
         pcntl_signal(SIGALRM, $PreviousAlarm === false ? SIG_DFL : $PreviousAlarm);
         fclose($Socket);
      }
   })
);
