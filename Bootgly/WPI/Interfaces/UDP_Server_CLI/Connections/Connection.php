<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;


use function array_pop;
use function count;
use function gc_collect_cycles;
use function gc_enable;
use function gc_enabled;
use function hash_hmac;
use function is_array;
use function is_int;
use function is_object;
use function ord;
use function random_bytes;
use function spl_object_id;
use function time;
use Closure;
use ReflectionProperty;
use Throwable;
use WeakMap;
use WeakReference;

use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Registry as TimerRegistry;
use Bootgly\ACI\Events\Timer\Reset as TimerReset;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Authority as ConnectionAuthority;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Packages;


class Connection extends Packages
{
   /** @var resource */
   public $Socket;

   // * Config
   /** @var array<int> */
   public array $timers;
   public int $expiration;

   // * Data
   // @ Remote
   public string $peer;
   public string $ip;
   public int $port;

   // * Metadata
   /** Immutable admission key captured from the original peer address. */
   public readonly string $id;
   public bool $encrypted;
   public int $status;
   // @ State
   public int $started;
   public int $used;
   // @ Stats
   public int $writes;
   // Last `writes` value observed by `expire()` (activity detection)
   protected int $expiredWrites;
   // Last `writes` value observed by `limit()` (rate window baseline)
   protected int $limitedWrites;
   /** @var WeakMap<Connection,Closure> Non-serializable ledger callbacks kept off-object. */
   private static WeakMap $Releases;
   /** @var WeakMap<Connection,Lease> Actual-lifetime managed admission leases. */
   private static WeakMap $Leases;
   /** @var WeakMap<Connection,bool> Process-local positive I/O authority. */
   private static WeakMap $Authorities;
   /** @var array<int,WeakMap<Connection,bool>> Keyed authority hash buckets. */
   private static array $GenerationBuckets = [];
   /** Process-random key that prevents chosen peer-bucket collisions. */
   private static string $generationSecret;
   /** @var WeakMap<Connection,bool> Objects created by the real constructor. */
   private static WeakMap $Instances;
   /** @var WeakMap<Connection,bool> Exact objects currently executing close(). */
   private static WeakMap $Closings;
   /** @var array<int,Connection> Strong ownership for bounded unstable peers. */
   private static array $Quarantines = [];
   /** @var array<int,Connection> Strong ownership for direct legacy objects. */
   private static array $DirectQuarantines = [];
   /** Direct-quarantine supervisor task; 0 while no direct object is retained. */
   private static int $quarantineTimer = 0;
   /** Full-wheel reset observer; 0 while direct quarantine is empty. */
   private static int $resetObserver = 0;
   /** Sealed Timer-reset recovery registrar bound to the lower layer. */
   private static null|Closure $ResetRegistrar = null;
   /** Sealed Timer-reset recovery remover bound to the lower layer. */
   private static null|Closure $ResetRemover = null;
   /** Connections-scoped supervisor coordinator, bound without exposing API. */
   private static null|Closure $Coordinator = null;
   /** Maximum synchronous passes before an unstable public owner stays quarantined. */
   private const SCRUB_BUDGET = 32;
   /** Fixed upper bound for lazily allocated authority-index buckets. */
   private const GENERATION_BUCKETS = 1024;
   /** True only while this exact object is executing terminal cleanup. */
   private bool $closing = false; // @phpstan-ignore property.onlyWritten
   /** True after private terminal authority has been committed. */
   private bool $closed = false; // @phpstan-ignore property.onlyWritten
   /** Number of exact Connection terminal scrubs active in this process. */
   private static int $terminalDepth = 0;


   /**
    * @param resource $Socket Shared UDP server socket.
    * @param string $peer "ip:port" peer address (IPv4 or "[ip]:port" for IPv6).
    * @param int $expiration Idle lifetime captured from immutable server config.
    * @param null|Closure $Release Admission-ledger release callback.
    * @param bool $deferRelease Release the managed token at actual finalization.
    */
   public function __construct (
      $Socket,
      string $peer,
      int $expiration = 30,
      null|Closure $Release = null,
      bool $deferRelease = false,
   )
   {
      // ! Direct compatibility objects created from a framework lifecycle
      //   callback start inert. The admission manager explicitly permits only
      //   the exact instance it allocates after all invariants are rechecked.
      $authorize = ConnectionAuthority::guard($peer) === false;
      $this->Socket = $Socket;

      // * Config
      $this->timers = [];
      $this->expiration = $expiration;

      // * Data
      // @ Remote
      $this->peer = $peer;
      [$this->ip, $this->port] = Peer::parse($peer);

      // * Metadata
      $this->id = $peer;
      $this->encrypted = false;
      $this->status = Connections::STATUS_ESTABLISHED;
      // @ State
      $this->started = time();
      $this->used = time();
      // @ Stats
      $this->writes = 0;
      $this->expiredWrites = 0;
      $this->limitedWrites = 0;
      if ($Release !== null) {
         if ($deferRelease) {
            if (isSet(self::$Leases) === false) {
               self::$Leases = new WeakMap;
            }
            self::$Leases[$this] = new Lease(
               WeakReference::create($this),
               $Release,
            );
         }
         else {
            if (isSet(self::$Releases) === false) {
               self::$Releases = new WeakMap;
            }
            self::$Releases[$this] = $Release;
         }
      }


      // ! Packages exposes the established strong self-view. Automatic GC is
      //   therefore a memory-safety invariant for closed compatibility shells;
      //   enable it before parent construction forms that cycle, including
      //   processes started with zend.enable_gc=0.
      if (gc_enabled() === false) {
         gc_enable();
      }
      parent::__construct($this);
      if (isSet(self::$Authorities) === false) {
         self::$Authorities = new WeakMap;
      }
      if (isSet(self::$Instances) === false) {
         self::$Instances = new WeakMap;
      }
      self::$Instances[$this] = true;
      $authorize = $authorize
         && ConnectionAuthority::guard($this->id) === false;
      if ($authorize) {
         self::grant($this);
         if (ConnectionAuthority::guard($this->id)) {
            self::deny($this);
            $authorize = false;
         }
      }

      // @ Preserve the legacy direct-construction contract for extensions.
      //   Router-admitted peers always carry a Release callback and use the
      //   single central supervisor instead of one timer per remote peer.
      if (
         $authorize
         && $Release === null
         && isSet(Connections::$stats)
         && Connections::$stats
         && $expiration > 0
      ) {
         $this->schedule($expiration);
      }
   }

   /** Check whether this peer remains authorized and outside the blacklist. */
   public function check (): bool
   {
      if (ConnectionAuthority::check($this) === false) {
         return false;
      }

      // @ Check blacklist
      if ( isSet(Connections::$blacklist[$this->ip]) ) {
         return false;
      }

      return true;
   }
   /**
    * Expire this peer after its idle lease.
    *
    * @param int $timeout Idle lease in seconds; zero disables expiration.
    */
   public function expire (int $timeout): bool
   {
      if (ConnectionAuthority::check($this) === false) {
         return true;
      }
      if ($timeout <= 0) {
         return false;
      }

      // ! Per-instance snapshot (was a per-method `static` shared by every
      //   Connection in the worker — one busy peer masked the idleness of
      //   all others and no peer was ever reclaimed).
      if ($this->expiredWrites !== $this->writes) {
         $this->expiredWrites = $this->writes;
         $this->used = time();
      }

      if ((time() - $this->used) >= $timeout) {
         return $this->close();
      }

      return false;
   }
   /**
    * Enforce one per-peer package window and blacklist abusive source IPs.
    *
    * @param int $packages Maximum writes allowed in the current window.
    */
   public function limit (int $packages): bool
   {
      if (ConnectionAuthority::check($this) === false) {
         return true;
      }

      // ! Per-instance window baseline (was a per-method `static` shared by
      //   every Connection — the delta measured one peer against another).
      if (($this->writes - $this->limitedWrites) >= $packages) {
         Connections::$blacklist[$this->ip] = true;
         return $this->close();
      }

      $this->limitedWrites = $this->writes;

      return false;
   }

   /** Arm one legacy direct timer without invoking public property hooks. */
   private function schedule (int $expiration): void
   {
      try {
         $Property = new ReflectionProperty($this, 'timers');
         if ($Property->isVirtual()) {
            return;
         }
         $timers = $Property->isInitialized($this)
            ? $Property->getRawValue($this)
            : [];
         if (is_array($timers) === false) {
            return;
         }
      }
      catch (Throwable) {
         return;
      }
      if (self::authorize($this) === false) {
         return;
      }

      try {
         $timer = Timer::add(
            interval: $expiration,
            handler: [$this, 'expire'],
            args: [$expiration],
         );
      }
      catch (Throwable) {
         return;
      }
      if ($timer === false) {
         return;
      }
      $timers[] = $timer;
      try {
         $Property->setRawValue($this, $timers);
      }
      catch (Throwable) {
         try {
            Timer::del($timer);
         }
         catch (Throwable) {
            // The timer implementation contains detached callback failures.
         }
         return;
      }
      if (self::authorize($this) === false) {
         [, $timers] = $this->extract('timers', []);
         $timers = is_array($timers) ? $timers : [$timer];
         self::cancel($timers);
      }
   }

   /** Close this exact peer and release ownership only after bounded cleanup. */
   public function close (): true
   {
      $identity = spl_object_id($this);
      if (
         isSet(self::$Instances) === false
         || isSet(self::$Instances[$this]) === false
      ) {
         // ? A clone/unserialized shell owns no process-local authority or
         //   lease. Deny it without acting on copied timer IDs or ledgers.
         $this->closing = false;
         $this->closed = true;
         $this->extract('status', Connections::STATUS_CLOSED);
         $this->separate();

         return true;
      }
      self::deny($this);
      // ? Only the exact object already on this call stack is re-entrant.
      //   Serialized/cloned snapshots can carry `$closing = true` without
      //   owning this process-local marker and must execute a full cleanup.
      if (isSet(self::$Closings) && isSet(self::$Closings[$this])) {
         return true;
      }
      if (isSet(self::$Closings) === false) {
         self::$Closings = new WeakMap;
      }
      self::$Closings[$this] = true;
      $this->closing = true;
      self::$terminalDepth++;

      try {
         $stable = false;
         try {
            $stable = $this->terminate();
         }
         catch (Throwable) {
            // The peer remains terminal and charged if a user hook cannot stabilize.
         }
         $this->separate();

         // ! Mark every managed terminal object before the last mirror read.
         //   This both supervises cycle-only owners and keeps all coordination
         //   side effects inside the revocation boundary below.
         $managed = self::coordinate($this, true);
         // @ Coordination can release timer callbacks and run destructors.
         //   Revoke every same-key public generation observed afterwards;
         //   constructors reached from this lifecycle already start inert.
         $stable = ($managed === false || self::withdraw($this)) && $stable;
         $callback = isSet(self::$Releases) && isSet(self::$Releases[$this]);
         if ($stable) {
            $released = $this->release();
            if ($callback && $managed === false) {
               try {
                  $stable = $this->terminate();
               }
               catch (Throwable) {
                  $stable = false;
               }
               $stable = $released && $stable;
            }
            else {
               $stable = ($managed === false || self::withdraw($this))
                  && $released;
            }
         }

         if ($stable) {
            unset(self::$Quarantines[$identity]);
            unset(self::$DirectQuarantines[$identity]);
            self::queue();
         }
         else if (self::coordinate($this, true)) {
            self::$Quarantines[$identity] = $this;
         }
         else {
            self::$DirectQuarantines[$identity] = $this;
            self::queue();
         }
         self::coordinate($this);
      }
      finally {
         $this->closed = true;
         $this->closing = false;
         unset(self::$Closings[$this]);
         self::$terminalDepth--;
      }

      return true;
   }

   /** Check process-local private I/O authority without invoking public hooks. */
   private static function authorize (Connection $Connection): bool
   {
      return isSet(self::$Authorities)
         && isSet(self::$Authorities[$Connection])
         && $Connection->closing === false
         && $Connection->closed === false;
   }

   /** Grant process-local authority and index its immutable peer generation. */
   private static function grant (Connection $Connection): void
   {
      if (isSet(self::$Authorities) === false) {
         self::$Authorities = new WeakMap;
      }
      $index = self::locate($Connection->id);
      if (isSet(self::$GenerationBuckets[$index]) === false) {
         /** @var WeakMap<Connection,bool> $GenerationBucket */
         $GenerationBucket = new WeakMap;
         self::$GenerationBuckets[$index] = $GenerationBucket;
      }
      self::$Authorities[$Connection] = true;
      self::$GenerationBuckets[$index][$Connection] = true;
   }

   /** Revoke process-local authority and its immutable peer index entry. */
   private static function deny (Connection $Connection): void
   {
      if (
         isSet(self::$Authorities) === false
         || isSet(self::$Authorities[$Connection]) === false
      ) {
         return;
      }
      unset(self::$Authorities[$Connection]);
      $index = self::locate($Connection->id);
      $GenerationBucket = self::$GenerationBuckets[$index] ?? null;
      if ($GenerationBucket === null) {
         return;
      }
      unset($GenerationBucket[$Connection]);
      if (count($GenerationBucket) === 0) {
         unset(self::$GenerationBuckets[$index]);
      }
   }

   /** Locate one immutable peer in a process-keyed fixed bucket set. */
   private static function locate (string $peer): int
   {
      if (isSet(self::$generationSecret) === false) {
         self::$generationSecret = random_bytes(16);
      }
      $hash = hash_hmac('sha256', $peer, self::$generationSecret, true);

      return ((ord($hash[0]) << 8) | ord($hash[1]))
         % self::GENERATION_BUCKETS;
   }

   /** Revoke bounded same-key authority generations before ownership release. */
   private static function withdraw (Connection $Root): bool
   {
      $remaining = self::revoke($Root, self::SCRUB_BUDGET);
      if (self::retain($Root)) {
         return false;
      }
      for ($pass = 0; $pass < $remaining; $pass++) {
         $Public = Connections::$Connections[$Root->id] ?? null;
         if ($Public instanceof Connection === false || $Public === $Root) {
            return true;
         }
         if ($Public->id !== $Root->id) {
            // ? A corrupted alias must not revoke an unrelated peer's exact
            //   authority. Remove only this foreign public binding.
            if ((Connections::$Connections[$Root->id] ?? null) === $Public) {
               unset(Connections::$Connections[$Root->id]);
            }
            try {
               unset($Public);
            }
            // @phpstan-ignore-next-line A foreign object destructor may throw.
            catch (Throwable) {
               // Its own immutable peer authority remains independent.
            }
            continue;
         }

         self::deny($Public);
         if ((Connections::$Connections[$Root->id] ?? null) === $Public) {
            unset(Connections::$Connections[$Root->id]);
         }
         try {
            $Public->close();
         }
         catch (Throwable) {
            // Private authority was already revoked before application code.
         }
         try {
            unset($Public);
         }
         // @phpstan-ignore-next-line A public-generation destructor may throw.
         catch (Throwable) {
            // Re-read the next generation inside the same finite budget.
         }
      }

      $Public = Connections::$Connections[$Root->id] ?? null;

      return $Public instanceof Connection === false
         || $Public === $Root
         || self::authorize($Public) === false;
   }

   /** Check for another privately authorized object with the same immutable key. */
   private static function retain (Connection $Root): bool
   {
      $index = self::locate($Root->id);
      $GenerationBucket = self::$GenerationBuckets[$index] ?? null;
      if ($GenerationBucket === null) {
         return false;
      }
      if (count($GenerationBucket) === 0) {
         unset(self::$GenerationBuckets[$index]);
         return false;
      }
      foreach ($GenerationBucket as $Candidate => $authorized) {
         if (
            $authorized
            && $Candidate !== $Root
            && self::authorize($Candidate)
            && $Candidate->id === $Root->id
         ) {
            return true;
         }
      }

      return false;
   }

   /** Revoke same-key authorities inside one caller-owned finite budget. */
   private static function revoke (Connection $Root, int $budget): int
   {
      while ($budget > 0) {
         $Target = null;
         $index = self::locate($Root->id);
         $GenerationBucket = self::$GenerationBuckets[$index] ?? null;
         if ($GenerationBucket !== null && count($GenerationBucket) === 0) {
            unset(self::$GenerationBuckets[$index]);
            $GenerationBucket = null;
         }
         if ($GenerationBucket !== null) {
            foreach ($GenerationBucket as $Candidate => $authorized) {
               if (
                  $authorized
                  && $Candidate !== $Root
                  && self::authorize($Candidate)
                  && $Candidate->id === $Root->id
               ) {
                  $Target = $Candidate;
                  break;
               }
            }
         }
         unset($Candidate);
         if ($Target instanceof Connection === false) {
            break;
         }

         $budget--;
         self::deny($Target);
         try {
            $Target->close();
         }
         catch (Throwable) {
            // Private authority was revoked before application cleanup.
         }
         try {
            unset($Target);
         }
         // @phpstan-ignore-next-line A direct object destructor may throw.
         catch (Throwable) {
            // Re-read the weak authority map on the next bounded pass.
         }
      }

      return $budget;
   }

   /** Execute a bounded terminal scrub and report whether every owner stabilized. */
   private function terminate (): bool
   {
      $this->extract('status', Connections::STATUS_CLOSING);
      $this->initialize('used', time());
      $this->initialize('expiration', 0);

      // The underlying socket is shared by the server with every peer —
      // do not unregister it from the event loop and do not fclose().

      // ! Packages keeps the established public self-view for clone/reference
      //   compatibility, so the small closed shell remains a collectable cycle.
      //   A finite scrub budget contains re-entrant application destructors. If
      //   they keep restoring public owners, the private admission token remains
      //   charged to the cap and the central supervisor retries on its next turn.
      for ($pass = 0; $pass < self::SCRUB_BUDGET; $pass++) {
         $detached = true;
         $Owners = [];
         foreach (['Decoder', 'Encoder', 'decoded', 'Template'] as $name) {
            [$success, $Owner] = $this->extract($name, null);
            $detached = $success && $detached;
            if (is_object($Owner)) {
               $Owners[] = $Owner;
            }
         }
         foreach (['input', 'output', 'known'] as $name) {
            [$success] = $this->extract($name, '');
            $detached = $success && $detached;
         }

         while ($Owners !== []) {
            $Owner = array_pop($Owners);
            try {
               $Owner = null;
            }
            // @phpstan-ignore-next-line Destructor exceptions are catchable at runtime.
            catch (Throwable) {
               // A throwing owner cannot prevent the next cleanup pass.
            }
         }

         // @ Re-entrant owner destructors may register new timers or restore
         //   the public compatibility mirror after the initial teardown.
         [$success, $timers] = $this->extract('timers', []);
         if ($success === false || is_array($timers) === false) {
            $timers = [];
            $detached = false;
         }
         self::cancel($timers);
         if ((Connections::$Connections[$this->id] ?? null) === $this) {
            unset(Connections::$Connections[$this->id]);
         }
         $this->extract('status', Connections::STATUS_CLOSED);

         if ($detached && $this->verify()) {
            try {
               gc_collect_cycles();
            }
            catch (Throwable) { // @phpstan-ignore catch.neverThrown
               // A throwing deferred destructor requires another bounded pass.
               continue;
            }
            if ($this->verify()) { // @phpstan-ignore if.alwaysTrue
               return true;
            }
         }
      }

      // ! Do not release another object owner after the final checked pass: its
      //   destructor could repopulate state without a remaining revalidation.
      //   Scalar/timer/mirror invariants are still restored, and terminal I/O is
      //   already denied by private authority while this peer stays quarantined.
      [, $timers] = $this->extract('timers', []);
      $timers = is_array($timers) ? $timers : [];
      self::cancel($timers);
      if ((Connections::$Connections[$this->id] ?? null) === $this) {
         unset(Connections::$Connections[$this->id]);
      }
      foreach (['input', 'output', 'known'] as $name) {
         $this->extract($name, '');
      }
      $this->extract('status', Connections::STATUS_CLOSED);

      return false;
   }

   /**
    * Replace one backed compatibility property without executing its hooks.
    *
    * A virtual override has no framework-owned storage and is therefore an
    * opaque application view: cleanup uses the terminal value internally but
    * never invokes its getter/setter. Backed hooks are bypassed through raw
    * reflection, so application code cannot allocate, recurse or reset process
    * timers merely because a peer is closing.
    *
    * @return array{bool,mixed} Success and the replaced raw value.
    */
   private function extract (string $name, mixed $terminal): array
   {
      try {
         $Property = new ReflectionProperty($this, $name);
         if ($Property->isVirtual()) {
            return [true, $terminal];
         }
         $Value = $Property->isInitialized($this)
            ? $Property->getRawValue($this)
            : $terminal;
         $Property->setRawValue($this, $terminal);

         return [true, $Value];
      }
      catch (Throwable) {
         return [false, null];
      }
   }

   /** Initialize one missing backed field without invoking property hooks. */
   private function initialize (string $name, mixed $default): bool
   {
      try {
         $Property = new ReflectionProperty($this, $name);
         if (
            $Property->isVirtual() === false
            && $Property->isInitialized($this) === false
         ) {
            $Property->setRawValue($this, $default);
         }

         return true;
      }
      catch (Throwable) {
         return false;
      }
   }

   /** Break the live compatibility self-view before actual finalization. */
   private function separate (): void
   {
      try {
         unset($this->Connection); // @phpstan-ignore unset.possiblyHookedProperty
      }
      catch (Throwable) {
         // Hooked direct extensions remain governed by their own references.
      }
   }

   /**
    * Cancel and synchronously release every auxiliary timer value.
    *
    * @param array<mixed> $timers
    */
   private static function cancel (array &$timers): void
   {
      while ($timers !== []) {
         $id = array_pop($timers);
         if (is_int($id)) {
            try {
               Timer::del($id);
            }
            catch (Throwable) {
               // A malformed public value is still released below.
            }
         }
         try {
            unset($id);
         }
         // @phpstan-ignore-next-line Destructor exceptions are catchable at runtime.
         catch (Throwable) {
            // A timer-value destructor cannot escape terminal cleanup.
         }
      }
   }

   /**
    * Read cleanup state without invoking public property hooks.
    *
    * @return null|array<string,mixed>
    */
   private function snapshot (): null|array
   {
      $State = [];
      $Terminals = [
         'Decoder' => null,
         'Encoder' => null,
         'decoded' => null,
         'Template' => null,
         'input' => '',
         'output' => '',
         'known' => '',
         'timers' => [],
      ];
      foreach ($Terminals as $name => $terminal) {
         try {
            $Property = new ReflectionProperty($this, $name);
            if ($Property->isVirtual()) {
               $State[$name] = $terminal;
               continue;
            }
            if ($Property->isInitialized($this) === false) {
               return null;
            }
            $State[$name] = $Property->getRawValue($this);
         }
         catch (Throwable) {
            return null;
         }
      }

      return $State;
   }

   /** Verify raw cleanup invariants without invoking public hooks. */
   private function verify (): bool
   {
      $State = $this->snapshot();

      return $State !== null
         && $State['Decoder'] === null
         && $State['Encoder'] === null
         && $State['decoded'] === null
         && $State['Template'] === null
         && $State['input'] === ''
         && $State['output'] === ''
         && $State['known'] === ''
         && $State['timers'] === []
         && (Connections::$Connections[$this->id] ?? null) !== $this;
   }

   /** Release the exact admission token only after the terminal scrub stabilizes. */
   private function release (): bool
   {
      $Release = isSet(self::$Releases) ? (self::$Releases[$this] ?? null) : null;
      if ($Release !== null) {
         try {
            $Release($this);
            unset(self::$Releases[$this]);
            $Release = null;
         }
         catch (Throwable) {
            // A later close/sweep can retry the idempotent tokenized release.
            return false;
         }
         return true;
      }

      if ((Connections::$Connections[$this->id] ?? null) === $this) {
         // @ Direct compatibility-view objects own no private ledger.
         unset(Connections::$Connections[$this->id]);
      }

      return true;
   }

   /** Arm or stop the one supervisor for unstable direct legacy objects. */
   private static function queue (): void
   {
      if (self::$DirectQuarantines === []) {
         if (self::$resetObserver > 0) {
            self::ignore(self::$resetObserver);
            self::$resetObserver = 0;
         }
         if (self::$quarantineTimer > 0) {
            $timer = self::$quarantineTimer;
            self::$quarantineTimer = 0;
            try {
               Timer::del($timer);
            }
            catch (Throwable) {
               // The direct quarantine is already empty.
            }
         }

         return;
      }
      if (self::$resetObserver === 0) {
         self::$resetObserver = self::observe(self::queue(...));
      }
      if (
         self::$quarantineTimer > 0
         && TimerRegistry::check(self::$quarantineTimer)
      ) {
         return;
      }

      self::$quarantineTimer = 0;
      $timer = Timer::add(1, self::drain(...));
      self::$quarantineTimer = $timer === false ? 0 : $timer;
   }

   /** Retry one bounded scrub for every unstable direct legacy object. */
   private static function drain (): void
   {
      foreach (self::$DirectQuarantines as $Connection) {
         try {
            $Connection->close();
         }
         catch (Throwable) {
            // One extension object cannot block the remaining quarantines.
         }
      }
      self::queue();
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

   /**
    * Revalidate managed ownership and optionally mark this exact peer terminal.
    */
   private static function coordinate (
      Connection $Connection, bool $quarantine = false
   ): bool
   {
      if (self::$Coordinator === null) {
         self::$Coordinator = Closure::bind(
            static function (Connection $Connection, bool $quarantine): bool {
               if (isSet(Connections::$Peers) === false) {
                  return false;
               }

               $Peer = Connections::$Peers[$Connection->id] ?? null;
               $managed = $Peer !== null && $Peer[1]->get() === $Connection;
               if ($managed && $quarantine) {
                  Connections::$quarantineTokens[spl_object_id($Peer[2])] = true;
               }
               Connections::supervise();

               return $managed;
            },
            null,
            Connections::class,
         );
      }

      try {
         return (self::$Coordinator)($Connection, $quarantine);
      }
      catch (Throwable) {
         // Inbound traffic independently revalidates managed ownership.
         return false;
      }
   }

   /** Complete terminal cleanup when the last external owner releases this peer. */
   public function __destruct ()
   {
      try {
         $this->close();
      }
      catch (Throwable) {
         // Destructors must never leak a terminal-cleanup failure.
      }
   }
}
