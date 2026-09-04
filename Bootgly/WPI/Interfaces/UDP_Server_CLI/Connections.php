<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI;


use function array_pop;
use function count;
use function explode;
use function gc_collect_cycles;
use function in_array;
use function max;
use function min;
use function spl_object_id;
use function str_starts_with;
use function substr;
use function time;
use Closure;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Throwable;
use WeakReference;

use const Bootgly\CLI;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Registry as TimerRegistry;
use Bootgly\ACI\Events\Timer\Reset as TimerReset;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\WPI;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Authority as ConnectionAuthority;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;


class Connections implements WPI\Connections
{
   // * Data
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class, global: true);
         }

         return $this->Logger;
      }
   }


   public Server $Server;

   // * Config
   public null|float $timeout;

   // * Data
   private int $peerCeiling = 1024;
   private int $IPCeiling = 256;
   private int $idleTimeout = 30;
   private int $datagramBatch = 64;
   // ...

   // * Metadata
   // @ Remote (peer string "ip:port" → Connection)
   /** @var array<string,Connection> */
   public static array $Connections;
   /** @var array<string,array{string,WeakReference<Connection>,object,int}> */
   private static array $Peers;
   /**
    * Live admitted peer count per canonical source IP.
    *
    * @var array<string,int>
    */
   private static array $IPConnections;
   // @ Limiter
   /** @var array<string,bool> */
   public static array $blacklist;
   // @ Stats
   public static bool $stats;
   // Connections
   public int $connections;
   // Errors
   /** @var array<string,int> */
   public static array $errors;
   // Packages
   public static int $reads;
   public static int $writes;
   public static int $read;
   public static int $written;
   /** One central idle-supervisor task per worker; 0 while no peer is live. */
   private static int $timer = 0;
   /** Nominal interval owned by the current central task. */
   private static int $timerInterval = 0;
   /** Full-wheel reset observer; 0 while the private peer ledger is empty. */
   private static int $resetObserver = 0;
   /** @var array<int,bool> Admission tokens whose terminal scrub is incomplete. */
   private static array $quarantineTokens = [];
   /** @var array<int,int> Live peer counts by positive immutable timeout. */
   private static array $timeoutCounts = [];
   /** Smallest positive timeout represented in the private peer ledger. */
   private static int $minimumTimeout = 0;
   /** Connection-scoped direct-quarantine supervisor callback. */
   private static null|Closure $DirectSupervisor = null;
   /** Connection-scoped private authority grant for one admitted instance. */
   private static null|Closure $ConnectionPermit = null;
   /** Sealed Timer-reset recovery registrar bound to the lower layer. */
   private static null|Closure $ResetRegistrar = null;
   /** Sealed Timer-reset recovery remover bound to the lower layer. */
   private static null|Closure $ResetRemover = null;
   /** Process-local identity of the only manager allowed to mutate admission. */
   private static object $CurrentManager;
   /** @var WeakReference<self> Exact object allowed to mutate admission. */
   private static WeakReference $CurrentConnections;
   /** Process-local guard against manager construction from cleanup callbacks. */
   private static null|object $Construction = null;
   /** Exact outer server-configuration transaction, or null while idle. */
   private static null|object $Configuration = null; // @phpstan-ignore property.unusedType
   /** Exact server-start claim, or null outside the bind/fork boundary. */
   private static null|object $Starting = null; // @phpstan-ignore property.unusedType
   /** Identity captured by this manager at construction. */
   private object $ManagerIdentity;
   /** Whether the owning server committed one validated transport Configs. */
   private bool $configured = false;
   /** Number of active accept() frames sharing this manager's budget. */
   private static int $admissionDepth = 0;
   /** Remaining re-entrant resolution work in the current outer invocation. */
   private static int $admissionBudget = 0;
   /** True while one admission publishes and reconciles its private ledgers. */
   private static bool $committing = false;
   /** Number of active close() frames sharing mirror-withdrawal work. */
   private static int $withdrawalDepth = 0;
   /** Remaining mirror generations in the current outer close() chain. */
   private static int $withdrawalBudget = 0;
   /** Maximum peer-resolution steps shared by one nested admission chain. */
   private const ADMISSION_BUDGET = 8;
   /** Maximum public-mirror generations shared by one nested close chain. */
   private const WITHDRAWAL_BUDGET = 32;
   // @ Status
   public const STATUS_INITIAL = 0;
   public const STATUS_CONNECTING = 1;
   public const STATUS_ESTABLISHED = 2;
   public const STATUS_CLOSING = 4;
   public const STATUS_CLOSED = 8;

   // @ Event-loop payload — routes inbound datagrams to per-peer Connections.
   public Router $Router;


   /**
    * @param Server $Server Owning UDP server.
    */
   public function __construct (Server &$Server)
   {
      if (
         self::$Construction !== null
         || self::$Configuration !== null
         || self::$Starting !== null
         || self::$committing
         || self::$admissionDepth > 0
         || self::$withdrawalDepth > 0
         || Lease::guard()
         || ConnectionAuthority::guard()
      ) {
         throw new RuntimeException('UDP admission manager lifecycle mutation is already active.');
      }
      $Construction = new stdClass;
      self::$Construction = $Construction;
      $PreviousManager = null;
      $PreviousReference = null;
      $previousLive = false;
      $ManagerIdentity = null;

      try {
      Lease::drain();

      // ! Retain the exact live authority pair so bounded mirror exhaustion
      //   can roll back without reviving a stale or partial manager.
      $PreviousManager = isSet(self::$CurrentManager)
         ? self::$CurrentManager
         : null;
      $PreviousReference = isSet(self::$CurrentConnections)
         ? self::$CurrentConnections
         : null;
      $PreviousConnections = $PreviousReference?->get();
      $previousLive = $PreviousManager !== null
         && $PreviousConnections instanceof self
         && isSet($PreviousConnections->ManagerIdentity)
         && $PreviousConnections->ManagerIdentity === $PreviousManager;

      $ManagerIdentity = new stdClass;
      self::$CurrentManager = $ManagerIdentity;
      self::$CurrentConnections = WeakReference::create($this);
      $this->ManagerIdentity = $ManagerIdentity;
      $this->Server = $Server;

      // * Config
      $this->timeout = 5;

      // * Data
      // Peer policy keeps the finite class defaults until Configs is applied.

      // * Metadata
      // @ Remote
      if (isSet(self::$Peers)) {
         // ! A replacement manager closes ordinary old peers. Any bounded
         //   quarantine that survives is re-read after timer callback release.
         foreach (self::$Peers as $peer => $Peer) {
            if ($this->authorize() === false) {
               throw new RuntimeException('UDP admission manager was superseded during cleanup.');
            }
            $Connection = $Peer[1]->get();
            if ($Connection instanceof Connection) {
               try {
                  $Connection->close();
               }
               catch (Throwable) {
                  // Terminal private ownership remains charged and is carried.
               }
            }
            else {
               self::forget($peer, $Peer[2]);
            }
            try {
               unset($Connection);
            }
            // @phpstan-ignore-next-line Destructor exceptions are catchable at runtime.
            catch (Throwable) {
               // Manager authority is checked immediately after release.
            }
            if ($this->authorize() === false) {
               throw new RuntimeException('UDP admission manager was superseded during cleanup.');
            }
         }
      }
      if ($this->authorize() === false) {
         throw new RuntimeException('UDP admission manager was superseded during cleanup.');
      }
      if (self::clear($ManagerIdentity) === false) {
         if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
            throw new RuntimeException('UDP admission manager was superseded during mirror cleanup.');
         }
         if (
            $previousLive
            && $PreviousManager !== null // @phpstan-ignore notIdentical.alwaysTrue
            && $PreviousReference !== null // @phpstan-ignore notIdentical.alwaysTrue
         ) {
            self::$CurrentManager = $PreviousManager;
            self::$CurrentConnections = $PreviousReference;

            throw new RuntimeException('UDP admission mirror cleanup exceeded its bounded budget.');
         }
      }
      if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
         throw new RuntimeException('UDP admission manager was superseded during cleanup.');
      }
      $timer = self::$timer;
      if ($timer > 0) {
         // ! Withdraw the old identity before releasing its callback graph.
         //   A destructor may finish a quarantine and rearm a new supervisor;
         //   that new identity must remain authoritative after Timer::del().
         self::$timer = 0;
         self::$timerInterval = 0;
         if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
            throw new RuntimeException('UDP admission manager was superseded during timer cleanup.');
         }
         try {
            Timer::del($timer);
         }
         catch (Throwable) {
            // Current live state is re-counted and supervised below.
         }
      }
      if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
         throw new RuntimeException('UDP admission manager was superseded during timer cleanup.');
      }
      Lease::drain();
      if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
         throw new RuntimeException('UDP admission manager was superseded during lease cleanup.');
      }

      // @ Timer release can run application destructors, terminal callbacks
      //   and cyclic GC. Re-read the actual tuples only after that boundary;
      //   restoring a pre-release snapshot would resurrect a closed peer.
      $Peers = isSet(self::$Peers) ? self::$Peers : [];
      $Quarantines = isSet(self::$quarantineTokens)
         ? self::$quarantineTokens
         : [];
      $IPConnections = [];
      $QuarantineTokens = [];
      $TimeoutCounts = [];
      $minimumTimeout = 0;
      foreach ($Peers as $Peer) {
         if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
            throw new RuntimeException('UDP admission manager was superseded during ledger cleanup.');
         }
         $IP = $Peer[0];
         $IPConnections[$IP] = ($IPConnections[$IP] ?? 0) + 1;
         $identity = spl_object_id($Peer[2]);
         if (isSet($Quarantines[$identity])) {
            $QuarantineTokens[$identity] = true;
         }
         $timeout = $Peer[3];
         if ($timeout > 0) {
            $TimeoutCounts[$timeout] = ($TimeoutCounts[$timeout] ?? 0) + 1;
            if ($minimumTimeout === 0 || $timeout < $minimumTimeout) {
               $minimumTimeout = $timeout;
            }
         }
      }
      if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
         throw new RuntimeException('UDP admission manager was superseded during ledger cleanup.');
      }
      self::$Peers = $Peers;
      self::$IPConnections = $IPConnections;
      self::$quarantineTokens = $QuarantineTokens;
      self::$timeoutCounts = $TimeoutCounts;
      self::$minimumTimeout = $minimumTimeout;
      // @ Limiter
      self::$blacklist = [];
      // @ Stats
      self::$stats = true;
      $this->connections = 0;
      // Errors
      self::$errors = [
         'connection' => 0,
         'read' => 0,
         'write' => 0
      ];
      // Packages
      self::$reads = 0;
      self::$writes = 0;
      self::$read = 0;
      self::$written = 0;

      // @ Router (shared server-socket datagram dispatcher)
      $this->Router = new Router($Server, $this, $this->datagramBatch);
      self::supervise();
      }
      catch (Throwable $Throwable) {
         if (
            $previousLive
            && $PreviousManager !== null
            && $PreviousReference instanceof WeakReference
            && $ManagerIdentity !== null
            && isSet(self::$CurrentManager)
            && self::$CurrentManager === $ManagerIdentity
         ) {
            self::$CurrentManager = $PreviousManager;
            self::$CurrentConnections = $PreviousReference;
         }

         throw $Throwable;
      }
      finally {
         if (
            self::$Construction === $Construction
         ) {
            self::$Construction = null;
         }
      }
   }

   /** Deny duplicated manager authority and independent admission budgets. */
   private function __clone ()
   {
   }

   /** Clear the public compatibility mirror without trusting destructors. */
   private static function clear (object $ManagerIdentity): bool
   {
      if (self::$CurrentManager !== $ManagerIdentity) {
         return false;
      }
      if (isSet(self::$Connections) === false) {
         self::$Connections = [];
         return true;
      }

      for ($pass = 0; $pass < 8 && self::$Connections !== []; $pass++) {
         if (self::$CurrentManager !== $ManagerIdentity) {
            return false;
         }
         $Publics = self::$Connections;
         self::$Connections = [];
         while ($Publics !== []) {
            $Public = array_pop($Publics);
            if (
               self::$CurrentManager === $ManagerIdentity
               && $Public instanceof Connection // @phpstan-ignore instanceof.alwaysTrue
            ) {
               try {
                  $Public->close();
               }
               catch (Throwable) {
                  // The local release below remains contained.
               }
            }
            try {
               unset($Public);
            }
            // @phpstan-ignore-next-line Destructor exceptions are catchable at runtime.
            catch (Throwable) {
               // A compatibility-view destructor cannot abort manager startup.
            }
         }
      }

      if (self::$CurrentManager !== $ManagerIdentity) {
         return false;
      }
      // : Leave the final generation untouched. The constructor either rolls
      //   authority back to a live predecessor or lets the first manager
      //   finish fail-closed without trusting, parking or destructing it.
      return self::$Connections === [];
   }

   /** Check whether this is still the process's current admission manager. */
   private function authorize (): bool
   {
      return isSet(self::$CurrentManager)
         && isSet(self::$CurrentConnections)
         && isSet($this->ManagerIdentity) // @phpstan-ignore isset.initializedProperty
         && $this->ManagerIdentity === self::$CurrentManager
         && self::$CurrentConnections->get() === $this;
   }

   public function __get (string $name): mixed
   {
      // Remove @ in name if exists (eg.: @connections -> connections)
      if (str_starts_with($name, '@')) {
         $name = substr($name, 1);
      }

      CLI->Commands->route([
         __CLASS__,
         ...explode(" ", $name)
      ], From: $this->Server);

      return null;
   }

   /**
    * UDP has no accept() step — datagrams are delivered directly on the
    * shared server socket. This method satisfies the `WPI\Connections`
    * contract but is never called by the event loop for UDP (the
    * listening socket is registered with EVENT_READ pointing at
    * `$this->Router`, not EVENT_CONNECT).
    */
   public function connect (): bool
   {
      return $this->serve();
   }

   /**
    * Resolve a peer address to its Connection, admitting and creating one
    * if the peer is new. Called by the Router on every inbound datagram.
    */
   public function accept (string $peer): null|Connection
   {
      if (
         $this->serve() === false
      ) {
         return null;
      }

      // ! Nested Socket/mirror hooks share one process-local budget. A nested
      //   accept() must not reset the finite work allowance of its outer call.
      $outermost = self::$admissionDepth === 0;
      if ($outermost) {
         self::$admissionBudget = self::ADMISSION_BUDGET;
      }
      self::$admissionDepth++;

      try {
         if ($this->authorize() === false) {
            return null;
         }
         Lease::drain();
         if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
            return null;
         }

         return $this->admit($peer);
      }
      finally {
         self::$admissionDepth--;
         if ($outermost) {
            self::$admissionBudget = 0;
         }
      }
   }

   /** Resolve or admit one peer inside the current shared invocation budget. */
   private function admit (string $peer): null|Connection
   {
      if ($this->configured === false || $this->authorize() === false) {
         return null;
      }

      // ? Timer::del() is public and can clear every task while this worker
      //   still owns peers. Revalidate the supervisor on inbound traffic,
      //   including a new peer that will be rejected at a full ceiling.
      if (self::$Peers !== []) {
         self::supervise();
      }

      // @ Re-resolve after every re-entrant mirror/destructor transition. The
      //   same finite allowance is consumed across this loop and every nested
      //   accept() frame, bounding getter-driven recursion and allocation.
      while ($this->spend()) {
         if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
            return null;
         }
         $Peer = self::$Peers[$peer] ?? null;
         $Reference = $Peer[1] ?? null;
         $Connection = $Reference?->get();
         if ($Connection instanceof Connection && $Peer !== null) {
            if (ConnectionAuthority::check($Connection) === false) {
               $Connection->close();
               unset($Connection);
               self::collect();
               continue;
            }
            if (self::mirror($peer, $Connection)) {
               if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
                  return null;
               }
               self::supervise();
               if ($this->authorize() === false) {
                  return null;
               }
               $Connection->used = time();
               if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
                  return null;
               }

               return $Connection;
            }
            unset($Connection);
            Lease::drain();
            continue;
         }
         if ($Peer !== null) {
            self::forget($peer, $Peer[2]);
            continue;
         }

         // ? Admission before allocation — a rejected peer must not construct
         //   a Connection or consume a per-IP slot.
         [$IP, $port] = Peer::parse($peer);
         if (
            $IP === ''
            || $port < 1
            || $port > 65_535
            || isSet(self::$blacklist[$IP])
            || $this->check($IP) === false
         ) {
            self::$errors['connection']++;
            return null;
         }

         // ! Snapshot the hookable server/socket boundary, then revalidate every
         //   admission invariant before allocation. A getter may re-enter accept()
         //   for this key, fill a ceiling with another key, or replace the manager.
         try {
            $Server = $this->Server;
            $Socket = $Server->Socket;
         }
         catch (Throwable) {
            self::$errors['connection']++;
            return null;
         }
         if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
            return null;
         }
         if (isSet(self::$Peers[$peer])) {
            continue;
         }
         if (
            isSet(self::$blacklist[$IP]) || $this->check($IP) === false // @phpstan-ignore identical.alwaysFalse
         ) {
            self::$errors['connection']++;
            return null;
         }

         $Token = new stdClass;
         $Release = static function () use ($peer, $Token): void {
            self::forget($peer, $Token);
         };
         $Connection = new Connection(
            $Socket,
            $peer,
            $this->idleTimeout,
            $Release,
            true,
         );

         // ! Guard the complete authority/ledger commit. A signal or released
         //   application callback that enters before this assignment may
         //   finish first; the guarded recheck observes it. Once armed, nested
         //   admission/manager-close/sweep calls fail closed until every
         //   derived counter, mirror and supervisor is consistent.
         $accepted = false;
         $ledgerCommitted = false;
         $connections = null;
         try {
            self::$committing = true;
            $connections = $this->connections;
            $eligible = $this->authorize() // @phpstan-ignore booleanAnd.leftAlwaysTrue
               && isSet(self::$Peers[$peer]) === false
               && isSet(self::$blacklist[$IP]) === false
               && $this->check($IP); // @phpstan-ignore booleanAnd.rightAlwaysTrue
            if ($eligible) {
               $eligible = self::permit($Connection);
            }
            if ($eligible) {
               $eligible = $this->authorize() // @phpstan-ignore booleanAnd.leftAlwaysTrue
                  && isSet(self::$Peers[$peer]) === false
                  && isSet(self::$blacklist[$IP]) === false
                  && $this->check($IP) // @phpstan-ignore booleanAnd.rightAlwaysTrue
                  && ConnectionAuthority::check($Connection);
            }
            if ($eligible) {
               self::$Peers[$peer] = [
                  $IP,
                  WeakReference::create($Connection),
                  $Token,
                  $this->idleTimeout,
               ];
               self::track($this->idleTimeout);
               self::$IPConnections[$IP] = (self::$IPConnections[$IP] ?? 0) + 1;
               $ledgerCommitted = true;
               $this->connections++;
               if (self::mirror($peer, $Connection)) {
                  self::supervise();
                  $Peer = self::$Peers[$peer] ?? null;
                  $accepted = $this->authorize()
                     && $Peer !== null
                     && $Peer[1]->get() === $Connection
                     && $Peer[2] === $Token
                     && ConnectionAuthority::check($Connection)
                     && (self::$Connections[$peer] ?? null) === $Connection;
               }
            }
            if ($accepted) {
               self::$committing = false;
               $Peer = self::$Peers[$peer] ?? null;
               $accepted = $this->authorize()
                  && $Peer !== null
                  && $Peer[1]->get() === $Connection
                  && $Peer[2] === $Token
                  && ConnectionAuthority::check($Connection)
                  && (self::$Connections[$peer] ?? null) === $Connection;
               if ($accepted === false) {
                  self::$committing = true;
               }
            }
         }
         catch (Throwable) {
            $accepted = false;
            self::$committing = true;
            // Rollback below derives all counters from authoritative tuples.
         }
         finally {
            try {
               if ($accepted === false) {
                  for ($attempt = 0; $attempt < 2; $attempt++) {
                     try {
                        $Connection->close();
                        break;
                     }
                     catch (Throwable) {
                        // Retry once after an asynchronous interruption. An
                        // unstable object remains charged to its supervisor.
                     }
                  }
                  if ($ledgerCommitted === false) {
                     if ((self::$Connections[$peer] ?? null) === $Connection) {
                        unset(self::$Connections[$peer]);
                     }
                     if ((self::$Peers[$peer][2] ?? null) === $Token) {
                        unset(self::$Peers[$peer]);
                     }
                  }
                  if ($connections !== null) {
                     $this->connections = $connections;
                  }
                  try {
                     unset($Connection);
                  }
                  // @phpstan-ignore-next-line Application destruction may throw.
                  catch (Throwable) {
                     // The tokenized tuple, if committed, remains charged.
                  }
                  $Connection = null;
                  self::collect();
                  try {
                     self::rebuild();
                     self::supervise();
                  }
                  catch (Throwable) {
                     // A later lifecycle touch retries the bounded supervisor.
                  }
               }
            }
            finally {
               self::$committing = false;
            }
         }
         if ($accepted === false || $Connection instanceof Connection === false) {
            try {
               self::supervise();
            }
            catch (Throwable) {
               // A later lifecycle touch retries the bounded supervisor.
            }
            self::$errors['connection']++;

            return null;
         }

         return $Connection;
      }

      self::$errors['connection']++;

      return null;
   }

   /** Check the exact server/configuration boundary before transport work. */
   private function serve (): bool
   {
      if (
         self::$Configuration !== null
         || self::$Starting !== null
         || self::$committing
         || $this->configured === false
         || $this->authorize() === false
      ) {
         return false;
      }

      try {
         $ServerProperty = new ReflectionProperty(self::class, 'Server');
         if ($ServerProperty->isInitialized($this) === false) {
            return false;
         }
         $Server = $ServerProperty->getRawValue($this);
         if ($Server instanceof Server === false) {
            return false;
         }
         $StatusProperty = new ReflectionProperty(Server::class, 'Status');
         $TransportedProperty = new ReflectionProperty(Server::class, 'transported');
         $ConfiguringProperty = new ReflectionProperty(Server::class, 'configuring');
         $ConnectionsProperty = new ReflectionProperty(Server::class, 'Connections');
         if (
            $StatusProperty->isInitialized($Server) === false
            || $TransportedProperty->isInitialized($Server) === false
            || $ConfiguringProperty->isInitialized($Server) === false
            || $ConnectionsProperty->isInitialized($Server) === false
         ) {
            return false;
         }

         return in_array(
            $StatusProperty->getRawValue($Server),
            [Status::Configuring, Status::Starting, Status::Running, Status::Paused],
            true,
         )
            && $TransportedProperty->getRawValue($Server) === true
            && $ConfiguringProperty->getRawValue($Server) === false
            && $ConnectionsProperty->getRawValue($Server) === $this;
      }
      catch (Throwable) {
         return false;
      }
   }

   /** Consume one resolution step from the current nested admission chain. */
   private function spend (): bool
   {
      if (self::$admissionBudget <= 0) {
         return false;
      }

      self::$admissionBudget--;

      return true;
   }

   /** Grant private I/O authority to the exact manager-allocated instance. */
   private static function permit (Connection $Connection): bool
   {
      if (self::$admissionDepth <= 0) {
         return false;
      }
      if (self::$ConnectionPermit === null) {
         self::$ConnectionPermit = Closure::bind(
            static function (
               Connection $Connection,
               int &$budget,
            ): bool {
               if (
                  isSet(Connection::$Instances) === false
                  || isSet(Connection::$Instances[$Connection]) === false
                  || isSet(Connection::$Authorities) === false
               ) {
                  return false;
               }
               $budget = Connection::revoke($Connection, $budget);
               if (Connection::retain($Connection)) {
                  return false;
               }
               Connection::grant($Connection);

               return true;
            },
            null,
            Connection::class,
         );
      }

      try {
         return (self::$ConnectionPermit)(
            $Connection,
            self::$admissionBudget,
         );
      }
      catch (Throwable) {
         return false;
      }
   }

   /** Collect dead owner cycles without letting application destructors skip lease release. */
   private static function collect (): void
   {
      try {
         gc_collect_cycles();
      }
      catch (Throwable) { // @phpstan-ignore catch.neverThrown
         // A hostile destructor cannot escape admission or suppress the drain.
      }
      Lease::drain();
   }

   /**
    * Whether another peer from an IP fits both worker-local ceilings.
    */
   private function check (string $IP): bool
   {
      if (
         $this->peerCeiling > 0
         && count(self::$Peers) >= $this->peerCeiling
      ) {
         return false;
      }

      if (
         $this->IPCeiling > 0
         && (self::$IPConnections[$IP] ?? 0) >= $this->IPCeiling
      ) {
         return false;
      }

      return true;
   }

   /** Rebuild every derived admission counter from authoritative peer tuples. */
   private static function rebuild (): void
   {
      $Peers = isSet(self::$Peers) ? self::$Peers : [];
      $Quarantines = isSet(self::$quarantineTokens)
         ? self::$quarantineTokens
         : [];
      $IPConnections = [];
      $QuarantineTokens = [];
      $TimeoutCounts = [];
      $minimumTimeout = 0;
      foreach ($Peers as $peer => $Peer) {
         if ($Peer[1]->get() instanceof Connection === false) {
            unset($Peers[$peer]);
            continue;
         }
         $IP = $Peer[0];
         $IPConnections[$IP] = ($IPConnections[$IP] ?? 0) + 1;
         $identity = spl_object_id($Peer[2]);
         if (isSet($Quarantines[$identity])) {
            $QuarantineTokens[$identity] = true;
         }
         $timeout = $Peer[3];
         if ($timeout > 0) {
            $TimeoutCounts[$timeout] = ($TimeoutCounts[$timeout] ?? 0) + 1;
            if ($minimumTimeout === 0 || $timeout < $minimumTimeout) {
               $minimumTimeout = $timeout;
            }
         }
      }

      self::$Peers = $Peers;
      self::$IPConnections = $IPConnections;
      self::$quarantineTokens = $QuarantineTokens;
      self::$timeoutCounts = $TimeoutCounts;
      self::$minimumTimeout = $minimumTimeout;
   }

   /** Add one immutable positive timeout to the supervisor ledger. */
   private static function track (int $timeout): void
   {
      if ($timeout <= 0) {
         return;
      }

      self::$timeoutCounts[$timeout] = (self::$timeoutCounts[$timeout] ?? 0) + 1;
      if (self::$minimumTimeout === 0 || $timeout < self::$minimumTimeout) {
         self::$minimumTimeout = $timeout;
      }
   }

   /** Remove one immutable positive timeout from the supervisor ledger. */
   private static function untrack (int $timeout): void
   {
      if ($timeout <= 0 || isSet(self::$timeoutCounts[$timeout]) === false) {
         return;
      }
      if (--self::$timeoutCounts[$timeout] <= 0) {
         unset(self::$timeoutCounts[$timeout]);
      }
      if ($timeout !== self::$minimumTimeout || isSet(self::$timeoutCounts[$timeout])) {
         return;
      }

      self::$minimumTimeout = 0;
      foreach (self::$timeoutCounts as $candidate => $count) {
         if ($count > 0 && (self::$minimumTimeout === 0 || $candidate < self::$minimumTimeout)) {
            self::$minimumTimeout = $candidate;
         }
      }
   }

   /** Arm, retain or stop the one manager supervisor required by live state. */
   private static function supervise (): void
   {
      self::rearm();
      if (self::$Peers !== [] && self::$resetObserver === 0) {
         self::$resetObserver = self::observe(self::supervise(...));
      }
      else if (self::$Peers === [] && self::$resetObserver > 0) {
         self::ignore(self::$resetObserver);
         self::$resetObserver = 0;
      }
      $interval = self::$quarantineTokens === [] ? self::$minimumTimeout : 1;

      if (self::$Peers !== [] && $interval > 0) {
         self::arm($interval);
         return;
      }

      if (self::$timer > 0) {
         $timer = self::$timer;
         self::$timer = 0;
         try {
            Timer::del($timer);
         }
         catch (Throwable) {
            // No remaining ownership depends on this supervisor.
         }
      }
      self::$timerInterval = 0;
      if (self::$Peers === []) {
         self::$quarantineTokens = [];
         self::$timeoutCounts = [];
         self::$minimumTimeout = 0;
      }
   }

   /** Revalidate the transient supervisor for direct legacy quarantines. */
   private static function rearm (): void
   {
      if (self::$DirectSupervisor === null) {
         self::$DirectSupervisor = Closure::bind(
            static function (): void {
               Connection::queue();
            },
            null,
            Connection::class,
         );
      }

      try {
         (self::$DirectSupervisor)();
      }
      catch (Throwable) {
         // A later lifecycle touch retries the transient supervisor.
      }
   }

   /** Register one sealed Timer-reset recovery observer. */
   private static function observe (Closure $Observer): int
   {
      if (self::$ResetRegistrar === null) {
         self::$ResetRegistrar = Closure::bind(
            static function (Closure $Observer): int {
               return TimerReset::keep($Observer);
            },
            null,
            TimerReset::class,
         );
      }

      try {
         return (self::$ResetRegistrar)($Observer);
      }
      catch (Throwable) {
         return 0;
      }
   }

   /** Remove one sealed Timer-reset recovery observer. */
   private static function ignore (int $id): void
   {
      if (self::$ResetRemover === null) {
         self::$ResetRemover = Closure::bind(
            static function (int $id): void {
               TimerReset::drop($id);
            },
            null,
            TimerReset::class,
         );
      }

      try {
         (self::$ResetRemover)($id);
      }
      catch (Throwable) {
         // A later lifecycle touch can retry stale observer reconciliation.
      }
   }

   /** Arm the worker's one central idle-peer supervisor lazily. */
   private static function arm (int $timeout): void
   {
      if ($timeout <= 0) {
         return;
      }
      // ! A nominal cadence of at most five seconds avoids walking the
      //   bounded registry on every SIGALRM tick. Blocking user code can
      //   still delay cooperative Timer::tick() dispatch.
      $interval = max(1, min($timeout, 5));
      if (self::$timer > 0 && TimerRegistry::check(self::$timer)) {
         if (self::$timerInterval === $interval) {
            return;
         }
         try {
            Timer::del(self::$timer);
         }
         catch (Throwable) {
            // The replacement below becomes authoritative.
         }
      }
      self::$timer = 0;
      self::$timerInterval = 0;
      $timer = Timer::add($interval, self::sweep(...));
      self::$timer = $timer === false ? 0 : $timer;
      self::$timerInterval = $timer === false ? 0 : $interval;
   }

   /** Reap every peer whose own configured idle lease has elapsed. */
   private static function sweep (): void
   {
      if (
         self::$Configuration !== null
         || self::$Starting !== null
         || self::$committing
      ) {
         return;
      }

      Lease::drain();
      foreach (self::$Peers as $peer => $Peer) {
         $Connection = null;
         try {
            $Connection = $Peer[1]->get();
            if ($Connection instanceof Connection) {
               if (ConnectionAuthority::check($Connection) === false) {
                  $Connection->close();
                  unset($Connection);
                  continue;
               }
               $Connection->expire($Peer[3]);
               unset($Connection);
               continue;
            }

            self::forget($peer, $Peer[2]);
         }
         catch (Throwable) {
            if ($Connection instanceof Connection) {
               try {
                  $Connection->close();
               }
               catch (Throwable) {
                  // Keep a non-stable peer charged to its admission ceiling.
               }
               unset($Connection);
            }
            else {
               self::forget($peer, $Peer[2]);
            }
         }
      }
      self::collect();
   }

   /** Publish the compatibility mirror without trusting replaced destructors. */
   private static function mirror (string $peer, Connection $Connection): bool
   {
      for ($attempt = 0; $attempt < 8; $attempt++) {
         $Reference = self::$Peers[$peer][1] ?? null;
         if (
            $Reference?->get() !== $Connection
            || ConnectionAuthority::check($Connection) === false
         ) {
            if ((self::$Connections[$peer] ?? null) === $Connection) {
               unset(self::$Connections[$peer]);
            }

            return false;
         }
         if ((self::$Connections[$peer] ?? null) === $Connection) {
            return true;
         }

         $Public = self::$Connections[$peer] ?? null;
         self::$Connections[$peer] = $Connection;
         try {
            unset($Public);
         }
         // @phpstan-ignore-next-line Destructor exceptions are catchable at runtime.
         catch (Throwable) {
            // The authoritative weak registry and ledgers stay committed.
         }
      }

      $Reference = self::$Peers[$peer][1] ?? null;

      return $Reference?->get() === $Connection
         && ConnectionAuthority::check($Connection) // @phpstan-ignore booleanAnd.rightAlwaysTrue
         && (self::$Connections[$peer] ?? null) === $Connection; // @phpstan-ignore nullCoalesce.offset
   }

   /** Remove one terminal-retry token without touching a newer generation. */
   private static function unmark (object $Token): void
   {
      unset(self::$quarantineTokens[spl_object_id($Token)]);
   }

   /** Forget one admitted peer and balance every private ownership ledger. */
   private static function forget (string $peer, null|object $Token = null): void
   {
      $Peer = self::$Peers[$peer] ?? null;
      if ($Peer === null || ($Token !== null && $Peer[2] !== $Token)) {
         return;
      }

      // ! Remove the compatibility mirror only while it still identifies the
      //   admitted object. A stale destructor must never clobber a replacement
      //   that external code assigned under the same public key.
      $Admitted = $Peer[1]->get();
      $Public = null;
      if (
         isSet(self::$Connections[$peer])
         && self::$Connections[$peer] === $Admitted
      ) {
         // ! Hold it until every private ledger is committed; an adversarial
         //   destructor cannot interrupt accounting.
         $Public = self::$Connections[$peer];
         unset(self::$Connections[$peer]);
      }
      self::unmark($Peer[2]);
      self::untrack($Peer[3]);
      unset(self::$Peers[$peer]);

      $IP = $Peer[0];
      if (isSet(self::$IPConnections[$IP])) {
         if (--self::$IPConnections[$IP] <= 0) {
            unset(self::$IPConnections[$IP]);
         }
      }

      try {
         unset($Public);
      }
      // @phpstan-ignore-next-line Destructor exceptions are catchable at runtime.
      catch (Throwable) {
         // A public mirror object's destructor cannot roll accounting back.
      }
      self::supervise();
   }

   /**
    * Close a specific peer Connection.
    *
    * @param string $Connection "ip:port" peer key.
    *
    * @return bool
    */
   public function close ($Connection): bool
   {
      if (
         self::$Configuration !== null
         || self::$Starting !== null
         || self::$committing
      ) {
         return false;
      }

      $outermost = self::$withdrawalDepth === 0;
      if ($outermost) {
         self::$withdrawalBudget = self::WITHDRAWAL_BUDGET;
      }
      self::$withdrawalDepth++;

      try {
      if ($this->authorize() === false) {
         return false;
      }
      Lease::drain();
      if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
         return false;
      }

      $closed = false;
      $Reference = self::$Peers[$Connection][1] ?? null;
      $Peer = $Reference?->get();
      if ($Peer instanceof Connection) {
         try {
            $closed = $Peer->close();
         }
         catch (Throwable) {
            // Continue only while this manager remains authoritative.
         }
         try {
            unset($Peer);
         }
         // @phpstan-ignore-next-line Destructor exceptions are catchable at runtime.
         catch (Throwable) {
            // Generation validation below contains re-entrant replacement.
         }
         self::collect();
         if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
            return false;
         }
      }
      if (isSet(self::$Peers[$Connection])) {
         $PeerData = self::$Peers[$Connection];
         if ($PeerData[1]->get() instanceof Connection === false) {
            self::forget($Connection, $PeerData[2]);
            if ($this->authorize() === false) {
               return false;
            }
         }
      }

      $publicClosed = $this->withdraw($Connection);
      if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
         return false;
      }
      return $publicClosed || $closed;
      }
      finally {
         self::$withdrawalDepth--;
         if ($outermost) {
            self::$withdrawalBudget = 0;
         }
      }
   }

   /** Close bounded public-mirror generations without crossing manager authority. */
   private function withdraw (string $peer): bool
   {
      $closed = false;
      while (self::$withdrawalBudget > 0) {
         self::$withdrawalBudget--;
         if ($this->authorize() === false) {
            return false;
         }
         $Public = self::$Connections[$peer] ?? null;
         if ($Public instanceof Connection === false) {
            return $closed;
         }
         if ($Public->id !== $peer) {
            // ? The compatibility map can be mutated publicly. Drop only the
            //   foreign alias; its own immutable peer remains authoritative.
            if ((self::$Connections[$peer] ?? null) === $Public) {
               unset(self::$Connections[$peer]);
            }
            try {
               unset($Public);
            }
            catch (Throwable) { // @phpstan-ignore catch.neverThrown
               // A foreign object's own lifetime is independent of this key.
            }
            continue;
         }

         $result = false;
         try {
            $result = $Public->close();
         }
         catch (Throwable) {
            // A throwing generation is evaluated fail-closed below.
         }
         $closed = $result || $closed;
         $authorized = ConnectionAuthority::check($Public);
         if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
            try {
               unset($Public);
            }
            catch (Throwable) { // @phpstan-ignore catch.neverThrown
               // The successor manager is already authoritative.
            }
            return false;
         }
         $same = (self::$Connections[$peer] ?? null) === $Public;
         if ($same && $authorized === false) {
            unset(self::$Connections[$peer]);
         }
         try {
            unset($Public);
         }
         // @phpstan-ignore-next-line Destructor exceptions are catchable at runtime.
         catch (Throwable) {
            // Re-resolve a generation installed during destruction.
         }
         if ($this->authorize() === false) { // @phpstan-ignore identical.alwaysFalse
            return false;
         }
         if ($same && $authorized) {
            return false;
         }
      }

      return (self::$Connections[$peer] ?? null) instanceof Connection
         ? false
         : $closed;
   }
}
