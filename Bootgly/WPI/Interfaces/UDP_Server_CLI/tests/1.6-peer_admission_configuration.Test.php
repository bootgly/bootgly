<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ABI\Configs as Configuring;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Registry as TimerRegistry;
use Bootgly\ACI\Events\Timer\Reset as TimerReset;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\WPI\Events as WPIEvents;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Authority as ConnectionAuthority;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Router;


/** Self-replacing decoded owner used to keep a terminal peer unstable. */
final class H7CarryChain
{
   /** Whether destruction installs another generation. */
   public static bool $armed = true;
   /** Remaining replacements; -1 means unbounded until disarmed. */
   public static int $remaining = -1;
   /** Number of owner generations released. */
   public static int $destructions = 0;
   /** Peer whose decoded owner is replaced during destruction. */
   private Connection $Connection;


   /** @param Connection $Connection Peer kept unstable by this owner. */
   public function __construct (Connection $Connection)
   {
      $this->Connection = $Connection;
   }

   /** Install the next owner generation while the chain remains armed. */
   public function __destruct ()
   {
      self::$destructions++;
      if (self::$armed && self::$remaining !== 0) {
         if (self::$remaining > 0) {
            self::$remaining--;
         }
         $this->Connection->decoded = new self($this->Connection);
      }
   }
}


/** Cyclic destructor that stabilizes and closes the carried peer during GC. */
final class H7CarryGCBomb
{
   /** Whether cyclic collection executed the destructor. */
   public static bool $ran = false;
   /** Deliberate self-cycle deferred until Timer::del() collects it. */
   public null|self $Self = null;
   /** Peer closed when the deferred cycle is collected. */
   private Connection $Connection;


   /** @param Connection $Connection Peer closed by cyclic collection. */
   public function __construct (Connection $Connection)
   {
      $this->Connection = $Connection;
   }

   /** Stabilize the owner chain and close the exact carried peer. */
   public function __destruct ()
   {
      self::$ran = true;
      H7CarryChain::$armed = false;
      $this->Connection->close();
   }
}


final class H7NestedManagerMirror extends Connection
{
   /** Manager constructed during mirror destruction. */
   public static null|UDP_Server_CLI $Nested = null;
   /** Reentrant-construction rejection observed by the destructor. */
   public static string $error = '';
   /** @var resource|null */
   public static $SharedSocket = null;


   /** Replace the manager while an outer constructor drains the mirror. */
   public function __destruct ()
   {
      try {
         self::$Nested = new UDP_Server_CLI(Modes::Test);
         self::$Nested->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 1,
            connectionIdleTimeout: 0,
         ));
         if (self::$SharedSocket !== null) {
            $SocketProperty = new ReflectionProperty(self::$Nested, 'Socket');
            $SocketProperty->setValue(self::$Nested, self::$SharedSocket);
         }
      }
      catch (Throwable $Throwable) {
         self::$error = $Throwable->getMessage();
      }
   }
}

final class H7ClearMirrorConnection extends Connection
{
   /** Successor generations still published during manager clear. */
   public static int $remaining = 0;
   /** Whether the terminal successor is an adversarial scalar value. */
   public static bool $scalar = false;


   /** Publish one successor after this public generation is released. */
   public function __destruct ()
   {
      $Socket = $this->Socket;
      $peer = $this->id;
      parent::__destruct();
      if (self::$remaining <= 0) {
         return;
      }
      self::$remaining--;
      if (self::$scalar && self::$remaining === 0) {
         Connections::$Connections[$peer] = 'terminal scalar'; // @phpstan-ignore assign.propertyType
         return;
      }
      $Next = new self($Socket, $peer, 0);
      unset($Next->Connection); // @phpstan-ignore unset.possiblyHookedProperty
      Connections::$Connections[$peer] = $Next;
   }
}


/** Event graph impostor used to verify the concrete Select boundary. */
final class H7InvalidUDPEvent implements WPIEvents, Loops, Scheduler
{
   /** {@inheritDoc} */
   public function add ($Socket, int $flag, mixed $payload): bool
   {
      return false;
   }

   /** {@inheritDoc} */
   public function del ($Socket, int $flag): bool
   {
      return false;
   }

   /** {@inheritDoc} */
   public function loop (): void
   {
   }

   /** {@inheritDoc} */
   public function destroy (): void
   {
   }

   /** {@inheritDoc} */
   public function schedule (
      Fiber $Fiber,
      mixed $value = null,
      int $flag = self::SCHEDULE_READ,
   ): bool
   {
      return false;
   }

   /** {@inheritDoc} */
   public function defer (float|int $deadline, Closure $Callback): int
   {
      return 0;
   }

   /** {@inheritDoc} */
   public function cancel (int $ID): bool
   {
      return false;
   }

   /** {@inheritDoc} */
   public function interrupt (Fiber $Fiber, Throwable $Throwable): bool
   {
      return false;
   }
}


/** Application seam reached after a server owns the start claim. */
final class H7StartClaimApplication
{
   // * Data
   public static bool $masked = false;
   public static bool $claimed = false;
   public static bool $constructionRejected = false;
   public static bool $configurationRejected = false;
   public static bool $current = false;

   // * Metadata
   private static null|UDP_Server_CLI $A = null;
   private static null|UDP_Server_CLI $B = null;
   private static null|Configs $Config = null;
   private static null|object $Manager = null;
   private static null|object $Identity = null;
   private static null|WeakReference $Reference = null;
   private static null|object $Event = null;


   /** Capture the graph that must remain owned by server A. */
   public static function arm (
      UDP_Server_CLI $A,
      UDP_Server_CLI $B,
      Configs $Config,
   ): void
   {
      self::$A = $A;
      self::$B = $B;
      self::$Config = $Config;
      self::$masked = false;
      self::$claimed = false;
      self::$constructionRejected = false;
      self::$configurationRejected = false;
      self::$current = false;

      $ManagerProperty = new ReflectionProperty(
         UDP_Server_CLI::class,
         'Connections',
      );
      self::$Manager = $ManagerProperty->getRawValue($A);
      $Identity = new ReflectionProperty(Connections::class, 'ManagerIdentity');
      self::$Identity = $Identity->getRawValue(self::$Manager);
      $Reference = new ReflectionProperty(Connections::class, 'CurrentConnections');
      self::$Reference = $Reference->getValue();
      self::$Event = UDP_Server_CLI::$Event;
   }

   /** Release every graph reference retained by the application seam. */
   public static function reset (): void
   {
      self::$A = null;
      self::$B = null;
      self::$Config = null;
      self::$Manager = null;
      self::$Identity = null;
      self::$Reference = null;
      self::$Event = null;
      self::$masked = false;
      self::$claimed = false;
      self::$constructionRejected = false;
      self::$configurationRejected = false;
      self::$current = false;
   }

   /** Attack construction and stale configuration inside the claimed seam. */
   public static function boot (mixed $Environment): void
   {
      $Mask = [];
      $read = pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $Mask);
      if ($read) {
         pcntl_sigprocmask(SIG_SETMASK, $Mask);
      }
      self::$masked = in_array(SIGUSR1, $Mask, true);
      $Starting = new ReflectionProperty(Connections::class, 'Starting');
      self::$claimed = $Starting->getValue() !== null;

      try {
         new UDP_Server_CLI(Modes::Test);
      }
      catch (RuntimeException) {
         self::$constructionRejected = true;
      }
      try {
         self::$B?->configure(self::$Config); // @phpstan-ignore argument.type
      }
      catch (RuntimeException) {
         self::$configurationRejected = true;
      }

      $CurrentManager = new ReflectionProperty(Connections::class, 'CurrentManager');
      $CurrentConnections = new ReflectionProperty(
         Connections::class,
         'CurrentConnections',
      );
      $Reference = $CurrentConnections->getValue();
      self::$current = self::$Manager !== null
         && self::$Identity !== null
         && self::$Reference !== null
         && $CurrentManager->getValue() === self::$Identity
         && $Reference === self::$Reference
         && $Reference->get() === self::$Manager
         && UDP_Server_CLI::$Event === self::$Event;

      throw new RuntimeException('controlled start-claim application stop');
   }
}


/** Application seam that cancels start without throwing. */
final class H7StartStopApplication
{
   // * Data
   public static bool $called = false;

   // * Metadata
   private static null|UDP_Server_CLI $Server = null;


   /** Arm one synchronous stop from application boot. */
   public static function arm (UDP_Server_CLI $Server): void
   {
      self::$Server = $Server;
      self::$called = false;
   }

   /** Cancel the claimed start and return normally. */
   public static function boot (mixed $Environment): void
   {
      self::$called = true;
      self::$Server?->stop();
   }

   /** Release the retained server. */
   public static function reset (): void
   {
      self::$Server = null;
      self::$called = false;
   }
}


return new Test(
   description: 'UDP peer protection config should be finite, immutable and compatible',
   test: new Assertions(Case: function (): Generator {
      $Socket = null;
      $PreviousAlarm = pcntl_signal_get_handler(SIGALRM);
      Timer::init(static function (): void {});
      Timer::del();
      H7CarryChain::$armed = true;
      H7CarryChain::$remaining = -1;
      H7CarryChain::$destructions = 0;
      H7CarryGCBomb::$ran = false;
      H7NestedManagerMirror::$Nested = null;
      H7NestedManagerMirror::$error = '';
      H7NestedManagerMirror::$SharedSocket = null;
      H7ClearMirrorConnection::$remaining = 0;
      H7ClearMirrorConnection::$scalar = false;

      try {
         $names = [
            'maxConnections',
            'maxConnectionsPerIP',
            'connectionIdleTimeout',
            'maxDatagramsPerTick',
         ];
         $Constructor = new ReflectionMethod(Configs::class, '__construct');
         $defaults = [];
         foreach ($Constructor->getParameters() as $Parameter) {
            if (in_array($Parameter->getName(), $names, true)) {
               $defaults[$Parameter->getName()] = $Parameter->getDefaultValue();
            }
         }
         $DefaultConfig = new Configs(
            host: '127.0.0.1',
            port: 19996,
            workers: 1,
         );
         $Default = new UDP_Server_CLI(Modes::Test);
         $Default->configure($DefaultConfig);
         yield new Assertion(description: 'UDP peer protection defaults are finite')
            ->expect(
               array_values($defaults),
               Op::Identical,
               [1024, 256, 30, 64],
            )
            ->assert();

         $Read = static function (UDP_Server_CLI $Server): array {
            $Connections = $Server->Connections;
            $values = [];
            foreach (
               ['peerCeiling', 'IPCeiling', 'idleTimeout', 'datagramBatch'] as $name
            ) {
               $Property = new ReflectionProperty(Connections::class, $name);
               $values[] = $Property->getValue($Connections);
            }

            return $values;
         };
         $Snapshot = static function (UDP_Server_CLI $Server): array {
            $ManagerProperty = new ReflectionProperty(
               UDP_Server_CLI::class,
               'Connections',
            );
            $Manager = $ManagerProperty->isInitialized($Server)
               ? $ManagerProperty->getRawValue($Server)
               : null;
            $RouterProperty = new ReflectionProperty(Connections::class, 'Router');
            $values = [];
            foreach (
               ['peerCeiling', 'IPCeiling', 'idleTimeout', 'datagramBatch'] as $name
            ) {
               $Property = new ReflectionProperty(Connections::class, $name);
               $values[] = $Manager instanceof Connections
                  && $Property->isInitialized($Manager)
                  ? $Property->getRawValue($Manager)
                  : null;
            }
            $transport = [];
            foreach (['host', 'port', 'workers', 'user', 'group'] as $name) {
               $Property = new ReflectionProperty(UDP_Server_CLI::class, $name);
               $transport[] = $Property->getRawValue($Server);
            }

            $Status = new ReflectionProperty(UDP_Server_CLI::class, 'Status');
            $Transported = new ReflectionProperty(
               UDP_Server_CLI::class,
               'transported',
            );
            $Configured = new ReflectionProperty(Connections::class, 'configured');

            return [
               'Status' => $Status->isInitialized($Server)
                  ? $Status->getRawValue($Server)
                  : null,
               'transported' => $Transported->isInitialized($Server)
                  ? $Transported->getRawValue($Server)
                  : null,
               'transport' => $transport,
               'Manager' => $Manager,
               'policy' => $values,
               'Router' => $Manager instanceof Connections
                  && $RouterProperty->isInitialized($Manager)
                  ? $RouterProperty->getRawValue($Manager)
                  : null,
               'configured' => $Manager instanceof Connections
                  && $Configured->isInitialized($Manager)
                  ? $Configured->getRawValue($Manager)
                  : null,
               'configuring' => (
                  new ReflectionProperty(UDP_Server_CLI::class, 'configuring')
               )->getRawValue($Server),
            ];
         };

         $CustomConfig = new Configs(
            host: '127.0.0.1',
            port: 19995,
            workers: 1,
            maxConnections: 12,
            maxConnectionsPerIP: 3,
            connectionIdleTimeout: 0,
            maxDatagramsPerTick: 2,
         );
         $Custom = new UDP_Server_CLI(Modes::Test);
         $Custom->configure($CustomConfig);
         yield new Assertion(description: 'Configs applies isolated peer boundaries')
            ->expect(
               [$Read($Custom), $Read($Default)],
               Op::Identical,
               [[12, 3, 0, 2], [1024, 256, 30, 64]],
            )
            ->assert();

         $invalid = [
            ['maxConnections' => -1],
            ['maxConnectionsPerIP' => -1],
            ['connectionIdleTimeout' => -1],
            ['maxDatagramsPerTick' => 0],
         ];
         $failures = [];
         foreach ($invalid as $options) {
            $thrown = false;
            try {
               $arguments = [
                  'host' => '127.0.0.1',
                  'port' => 19994,
                  'workers' => 1,
                  ...$options,
               ];
               new Configs(...$arguments);
            }
            catch (InvalidArgumentException) {
               $thrown = true;
            }
            $failures[] = $thrown;
         }

         yield new Assertion(description: 'invalid Configs boundaries fail closed')
            ->expect($failures, Op::Identical, [true, true, true, true])
            ->assert();

         $missing = false;
         $positional = false;
         $immutable = false;
         try {
            new Configs();
         }
         catch (ArgumentCountError) {
            $missing = true;
         }
         try {
            // @phpstan-ignore-next-line Deliberately verifies the named-only guard.
            new Configs('127.0.0.1', 19995, 1);
         }
         catch (TypeError) {
            $positional = true;
         }
         try {
            // @phpstan-ignore-next-line Deliberately verifies asymmetric write visibility.
            $CustomConfig->maxConnections = 13;
         }
         catch (Error) {
            $immutable = true;
         }
         $types = [];
         foreach ($names as $name) {
            $Type = (new ReflectionProperty(Configs::class, $name))->getType();
            $types[] = $Type instanceof ReflectionNamedType
               ? $Type->getName()
               : '';
         }
         yield new Assertion(description: 'Configs is typed, immutable and named-only')
            ->expect(
               [$missing, $positional, $immutable, $types],
               Op::Identical,
               [true, true, true, ['int', 'int', 'int', 'int']],
            )
            ->assert();

         $Extended = new class(Modes::Test) extends UDP_Server_CLI {
            /** Application property that must not collide with H7 internals. */
            public int $maxConnections = 42;
         };
         $Extended->configure(new Configs(
            host: '127.0.0.1',
            port: 19995,
            workers: 1,
         ));
         yield new Assertion(description: 'Configs and downstream properties remain compatible')
            ->expect(
               [
                  $Extended->host,
                  $Extended->port,
                  $Extended->maxConnections,
                  $Read($Extended),
               ],
               Op::Identical,
               ['127.0.0.1', 19995, 42, [1024, 256, 30, 64]],
            )
            ->assert();

         $ExtendedConnections = new class($Extended) extends Connections {
            /** Downstream admission check. */
            public function check (): bool
            {
               return true;
            }

            /** Downstream removal operation. */
            public function remove (string $peer): bool
            {
               return $peer !== '';
            }
         };
         yield new Assertion(description: 'private H7 helpers do not collide downstream')
            ->expect(
               [$ExtendedConnections->check(), $ExtendedConnections->remove('peer')],
               Op::Identical,
               [true, true],
            )
            ->assert();

         // # Hooked manager properties cannot interpose on raw configuration.
         $GetterServer = new UDP_Server_CLI(Modes::Test);
         $GetterConnections = new class($GetterServer) extends Connections {
            // * Data
            public int $serverCalls = 0;
            public int $routerCalls = 0;
            private bool $armed = false;

            /** A virtual read must never run inside configuration. */
            public UDP_Server_CLI $Server {
               get {
                  $this->serverCalls++;

                  throw new RuntimeException('adversarial Server getter');
               }
               set (UDP_Server_CLI $Server) {
                  $this->Server = $Server;
               }
            }

            /** A virtual write must never run inside configuration. */
            public Router $Router {
               get {
                  return $this->Router;
               }
               set (Router $Router) {
                  if ($this->armed) {
                     $this->routerCalls++;

                     throw new RuntimeException('adversarial Router setter');
                  }

                  $this->Router = $Router;
               }
            }

            /** Arm hooks only after the base manager is complete. */
            public function __construct (UDP_Server_CLI &$Server)
            {
               parent::__construct($Server);
               $this->armed = true;
            }
         };
         $ManagerProperty = new ReflectionProperty(
            UDP_Server_CLI::class,
            'Connections',
         );
         $ManagerProperty->setRawValue($GetterServer, $GetterConnections);
         UDP_Server_CLI::$Event = new Select($GetterConnections);
         $getterBefore = $Snapshot($GetterServer);
         $getterError = '';
         try {
            $GetterServer->configure(new Configs(
               host: '127.0.0.21',
               port: 19821,
               workers: 2,
               maxConnections: 21,
               maxConnectionsPerIP: 7,
               connectionIdleTimeout: 5,
               maxDatagramsPerTick: 9,
            ));
         }
         catch (Throwable $Throwable) {
            $getterError = $Throwable->getMessage();
         }
         $getterAfter = $Snapshot($GetterServer);
         yield new Assertion(description: 'raw manager configuration bypasses throwing property hooks')
            ->expect(
               [
                  $getterError,
                  $GetterConnections->serverCalls,
                  $GetterConnections->routerCalls,
                  $getterAfter['Manager'] === $GetterConnections,
                  $getterAfter['Router'] !== $getterBefore['Router'],
                  $getterAfter['transported'],
                  $getterAfter['transport'],
                  $getterAfter['policy'],
                  $getterAfter['Status'],
                  $getterAfter['configuring'],
               ],
               Op::Identical,
               [
                  '',
                  0,
                  0,
                  true,
                  true,
                  true,
                  ['127.0.0.21', 19821, 2, null, null],
                  [21, 7, 5, 9],
                  Status::Configuring,
                  false,
               ],
            )
            ->assert();

         $NestedGetterServer = new UDP_Server_CLI(Modes::Test);
         $NestedGetterConnections = new class(
            $NestedGetterServer
         ) extends Connections {
            // * Data
            public int $calls = 0;
            private UDP_Server_CLI $Owner;
            private Configs $Config;

            /** Re-enter public configuration if framework code invokes this hook. */
            public UDP_Server_CLI $Server {
               get {
                  $this->calls++;
                  $this->Owner->configure($this->Config);

                  return $this->Server;
               }
               set (UDP_Server_CLI $Server) {
                  $this->Owner = $Server;
                  $this->Server = $Server;
               }
            }

            /** Arm the forbidden nested configuration payload. */
            public function arm (Configs $Config): void
            {
               $this->Config = $Config;
            }
         };
         $NestedGetterConnections->arm(new Configs(
            host: '127.0.0.22',
            port: 19822,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 1,
            maxDatagramsPerTick: 1,
         ));
         $ManagerProperty->setRawValue(
            $NestedGetterServer,
            $NestedGetterConnections,
         );
         UDP_Server_CLI::$Event = new Select($NestedGetterConnections);
         $nestedGetterError = '';
         try {
            $NestedGetterServer->configure(new Configs(
               host: '127.0.0.23',
               port: 19823,
               workers: 3,
               maxConnections: 23,
               maxConnectionsPerIP: 8,
               connectionIdleTimeout: 6,
               maxDatagramsPerTick: 10,
            ));
         }
         catch (Throwable $Throwable) {
            $nestedGetterError = $Throwable->getMessage();
         }
         $nestedGetterState = $Snapshot($NestedGetterServer);
         yield new Assertion(description: 'raw Server access suppresses reentrant getter configuration')
            ->expect(
               [
                  $nestedGetterError,
                  $NestedGetterConnections->calls,
                  $nestedGetterState['Manager'] === $NestedGetterConnections,
                  $nestedGetterState['transport'],
                  $nestedGetterState['policy'],
                  $nestedGetterState['Status'],
                  $nestedGetterState['configuring'],
               ],
               Op::Identical,
               [
                  '',
                  0,
                  true,
                  ['127.0.0.23', 19823, 3, null, null],
                  [23, 8, 6, 10],
                  Status::Configuring,
                  false,
               ],
            )
            ->assert();

         // # Reentrant adoption and manager guards fail atomically.
         $ReentrantConfigServer = new class(Modes::Test) extends UDP_Server_CLI {
            // * Data
            public bool $armed = true;

            /** Attempt one nested configure() before the parent can mutate. */
            protected function adopt (Configuring $Config): void
            {
               if ($this->armed) {
                  $this->armed = false;
                  $this->configure($Config);
               }

               parent::adopt($Config);
            }
         };
         $ReentrantConfig = new Configs(
            host: '127.0.0.24',
            port: 19824,
            workers: 1,
            maxConnections: 24,
            maxConnectionsPerIP: 6,
            connectionIdleTimeout: 4,
            maxDatagramsPerTick: 8,
         );
         $reentrantBefore = $Snapshot($ReentrantConfigServer);
         $reentrantError = '';
         try {
            $ReentrantConfigServer->configure($ReentrantConfig);
         }
         catch (RuntimeException $Exception) {
            $reentrantError = $Exception->getMessage();
         }
         $reentrantFailed = $Snapshot($ReentrantConfigServer);
         $ReentrantConfigServer->configure($ReentrantConfig);
         $reentrantRecovered = $Snapshot($ReentrantConfigServer);
         yield new Assertion(description: 'reentrant adopt failure rolls back and clears configuration guard')
            ->expect(
               [
                  str_contains($reentrantError, 'already active'),
                  $reentrantFailed === $reentrantBefore,
                  $reentrantRecovered['Manager'] === $reentrantBefore['Manager'],
                  $reentrantRecovered['Router'] !== $reentrantBefore['Router'],
                  $reentrantRecovered['transport'],
                  $reentrantRecovered['policy'],
                  $reentrantRecovered['Status'],
                  $reentrantRecovered['configuring'],
               ],
               Op::Identical,
               [
                  true,
                  true,
                  true,
                  true,
                  ['127.0.0.24', 19824, 1, null, null],
                  [24, 6, 4, 8],
                  Status::Configuring,
                  false,
               ],
            )
            ->assert();

         $StatusProperty = new ReflectionProperty(UDP_Server_CLI::class, 'Status');
         $StatusProperty->setRawValue($ReentrantConfigServer, Status::Booting);
         $guardBefore = $Snapshot($ReentrantConfigServer);
         $CommittingProperty = new ReflectionProperty(Connections::class, 'committing');
         $CommittingProperty->setValue(null, true);
         $guardError = '';
         try {
            $ReentrantConfigServer->configure(new Configs(
               host: '127.0.0.25',
               port: 19825,
               workers: 2,
               maxConnections: 25,
               maxConnectionsPerIP: 5,
               connectionIdleTimeout: 3,
               maxDatagramsPerTick: 7,
            ));
         }
         catch (RuntimeException $Exception) {
            $guardError = $Exception->getMessage();
         }
         finally {
            $CommittingProperty->setValue(null, false);
         }
         $guardFailed = $Snapshot($ReentrantConfigServer);
         $GuardRecovery = new Configs(
            host: '127.0.0.26',
            port: 19826,
            workers: 2,
            maxConnections: 26,
            maxConnectionsPerIP: 4,
            connectionIdleTimeout: 2,
            maxDatagramsPerTick: 6,
         );
         $ReentrantConfigServer->configure($GuardRecovery);
         $guardRecovered = $Snapshot($ReentrantConfigServer);
         yield new Assertion(description: 'manager guard failure preserves prior Status and permits retry')
            ->expect(
               [
                  str_contains($guardError, 'active lifecycle'),
                  $guardFailed === $guardBefore,
                  $guardFailed['Status'],
                  $guardFailed['configuring'],
                  $guardRecovered['Manager'] === $guardBefore['Manager'],
                  $guardRecovered['transport'],
                  $guardRecovered['policy'],
                  $guardRecovered['Status'],
                  $guardRecovered['configuring'],
               ],
               Op::Identical,
               [
                  true,
                  true,
                  Status::Booting,
                  false,
                  true,
                  ['127.0.0.26', 19826, 2, null, null],
                  [26, 4, 2, 6],
                  Status::Configuring,
                  false,
               ],
            )
            ->assert();

         // # An exact Configs with an uninitialized transport must fail pre-commit.
         $MalformedClass = new ReflectionClass(Configs::class);
         $MalformedConfig = $MalformedClass->newInstanceWithoutConstructor();
         $MalformedHost = new ReflectionProperty(Configs::class, 'host');
         $MalformedPort = new ReflectionProperty(Configs::class, 'port');
         $MalformedBatch = new ReflectionProperty(
            Configs::class,
            'maxDatagramsPerTick',
         );
         $MalformedHost->setRawValue($MalformedConfig, '127.0.0.27');
         $MalformedBatch->setRawValue($MalformedConfig, 27);
         foreach (
            [
               'workers' => 1,
               'user' => null,
               'group' => null,
               'maxConnections' => 27,
               'maxConnectionsPerIP' => 9,
               'connectionIdleTimeout' => 3,
            ] as $name => $value
         ) {
            $Property = new ReflectionProperty(Configs::class, $name);
            $Property->setRawValue($MalformedConfig, $value);
         }
         $malformedBefore = $Snapshot($ReentrantConfigServer);
         $malformedError = '';
         try {
            $ReentrantConfigServer->configure($MalformedConfig);
         }
         catch (Error $Error) {
            $malformedError = $Error->getMessage();
         }
         $malformedFailed = $Snapshot($ReentrantConfigServer);
         $MalformedRecovery = new Configs(
            host: '127.0.0.28',
            port: 19828,
            workers: 3,
            maxConnections: 28,
            maxConnectionsPerIP: 4,
            connectionIdleTimeout: 2,
            maxDatagramsPerTick: 5,
         );
         $ReentrantConfigServer->configure($MalformedRecovery);
         $malformedRecovered = $Snapshot($ReentrantConfigServer);
         yield new Assertion(description: 'incomplete exact Configs fails atomically and permits retry')
            ->expect(
               [
                  $MalformedConfig::class === Configs::class,
                  $MalformedHost->isInitialized($MalformedConfig),
                  $MalformedPort->isInitialized($MalformedConfig),
                  $MalformedBatch->isInitialized($MalformedConfig),
                  str_contains($malformedError, 'must not be accessed before initialization'),
                  $malformedFailed === $malformedBefore,
                  $malformedFailed['configuring'],
                  $malformedRecovered['Manager'] === $malformedBefore['Manager'],
                  $malformedRecovered['transport'],
                  $malformedRecovered['policy'],
                  $malformedRecovered['Status'],
                  $malformedRecovered['configuring'],
               ],
               Op::Identical,
               [
                  true,
                  true,
                  false,
                  true,
                  true,
                  true,
                  false,
                  true,
                  ['127.0.0.28', 19828, 3, null, null],
                  [28, 4, 2, 5],
                  Status::Configuring,
                  false,
               ],
            )
            ->assert();

         // # Deserialized policy values are revalidated inside adopt().
         $Forge = static function (array $changes = []): Configs {
            $values = [
               'host' => '127.0.0.29',
               'port' => 19829,
               'workers' => 1,
               'user' => null,
               'group' => null,
               'maxConnections' => 29,
               'maxConnectionsPerIP' => 9,
               'connectionIdleTimeout' => 3,
               'maxDatagramsPerTick' => 7,
               ...$changes,
            ];
            $Class = new ReflectionClass(Configs::class);
            $Forged = $Class->newInstanceWithoutConstructor();
            foreach ($values as $name => $value) {
               $Property = new ReflectionProperty(Configs::class, $name);
               $Property->setRawValue($Forged, $value);
            }

            return $Forged;
         };
         $invalidPolicies = [
            ['maxConnections' => -1],
            ['maxConnectionsPerIP' => -1],
            ['connectionIdleTimeout' => -1],
            ['maxDatagramsPerTick' => 0],
         ];
         $policyFailures = [];
         foreach ($invalidPolicies as $change) {
            $Forged = $Forge($change);
            $policyBefore = $Snapshot($ReentrantConfigServer);
            $policyError = false;
            try {
               $ReentrantConfigServer->configure($Forged);
            }
            catch (InvalidArgumentException) {
               $policyError = true;
            }
            $policyFailed = $Snapshot($ReentrantConfigServer);
            $ReentrantConfigServer->configure($MalformedRecovery);
            $policyRecovered = $Snapshot($ReentrantConfigServer);
            $policyFailures[] = [
               $Forged::class === Configs::class,
               $policyError,
               $policyFailed === $policyBefore,
               $policyFailed['Status'] === Status::Configuring,
               $policyFailed['transported'] === true,
               $policyFailed['configured'] === true,
               $policyFailed['configuring'] === false,
               $policyRecovered['Manager'] === $policyBefore['Manager'],
               $policyRecovered['transport']
                  === ['127.0.0.28', 19828, 3, null, null],
               $policyRecovered['policy'] === [28, 4, 2, 5],
               $policyRecovered['configured'] === true,
            ];
         }
         yield new Assertion(description: 'forged policy boundaries fail closed before mutation')
            ->expect(
               $policyFailures,
               Op::Identical,
               array_fill(0, 4, [
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
               ]),
            )
            ->assert();

         $FreshConfigServer = new UDP_Server_CLI(Modes::Test);
         $freshBefore = $Snapshot($FreshConfigServer);
         $freshError = false;
         try {
            $FreshConfigServer->configure($Forge(['maxConnections' => -1]));
         }
         catch (InvalidArgumentException) {
            $freshError = true;
         }
         $freshFailed = $Snapshot($FreshConfigServer);
         $FreshConfigServer->configure(new Configs(
            host: '127.0.0.30',
            port: 19830,
            workers: 2,
            maxConnections: 30,
            maxConnectionsPerIP: 10,
            connectionIdleTimeout: 4,
            maxDatagramsPerTick: 8,
         ));
         $freshRecovered = $Snapshot($FreshConfigServer);
         yield new Assertion(description: 'first forged Configs preserves untransported state and permits retry')
            ->expect(
               [
                  $freshError,
                  $freshFailed === $freshBefore,
                  $freshFailed['Status'],
                  $freshFailed['transported'],
                  $freshFailed['configured'],
                  $freshFailed['configuring'],
                  $freshRecovered['Manager'] === $freshBefore['Manager'],
                  $freshRecovered['transport'],
                  $freshRecovered['policy'],
                  $freshRecovered['Status'],
                  $freshRecovered['transported'],
                  $freshRecovered['configured'],
                  $freshRecovered['configuring'],
               ],
               Op::Identical,
               [
                  true,
                  true,
                  Status::Booting,
                  false,
                  false,
                  false,
                  true,
                  ['127.0.0.30', 19830, 2, null, null],
                  [30, 10, 4, 8],
                  Status::Configuring,
                  true,
                  true,
                  false,
               ],
            )
            ->assert();

         // # Connections has one policy source: the owning Server Configs.
         $SurfaceServer = new UDP_Server_CLI(Modes::Test);
         $ConnectionsConstructor = new ReflectionMethod(Connections::class, '__construct');
         $ConnectionsParameters = $ConnectionsConstructor->getParameters();
         $NamedPolicyFailure = null;
         $TypedOwnerFailure = null;
         try {
            // @phpstan-ignore-next-line Deliberately verifies removal of the policy surface.
            new Connections(Server: $SurfaceServer, maxConnections: 1);
         }
         catch (Error $Error) {
            $NamedPolicyFailure = $Error;
         }
         $invalidOwner = 1;
         try {
            // @phpstan-ignore-next-line Deliberately verifies the sole typed owner input.
            new Connections($invalidOwner);
         }
         catch (TypeError $Error) {
            $TypedOwnerFailure = $Error;
         }
         $SurfaceManager = $Snapshot($SurfaceServer)['Manager'];
         $ServerConnectionsSlot = new ReflectionProperty(
            UDP_Server_CLI::class,
            'Connections',
         );
         $ServerStatusSlot = new ReflectionProperty(
            UDP_Server_CLI::class,
            'Status',
         );
         $CurrentConnectionsProperty = new ReflectionProperty(
            Connections::class,
            'CurrentConnections',
         );
         $SurfaceReference = $CurrentConnectionsProperty->getValue();
         yield new Assertion(description: 'Connections constructor exposes only its Server owner')
            ->expect(
               [
                  count($ConnectionsParameters),
                  $ConnectionsParameters[0]->getName(),
                  (string) $ConnectionsParameters[0]->getType(),
                  $ConnectionsParameters[0]->isPassedByReference(),
                  $ConnectionsParameters[0]->isVariadic(),
                  $NamedPolicyFailure instanceof Error,
                  $NamedPolicyFailure instanceof Error
                     && str_contains(
                        $NamedPolicyFailure->getMessage(),
                        'Unknown named parameter',
                     ),
                  $TypedOwnerFailure instanceof TypeError,
                  $ServerConnectionsSlot->isFinal(),
                  $ServerConnectionsSlot->getRawValue($SurfaceServer)
                     === $SurfaceManager,
                  $ServerStatusSlot->isFinal(),
                  $ServerStatusSlot->getHooks(),
                  $ServerStatusSlot->getRawValue($SurfaceServer),
                  $SurfaceReference instanceof WeakReference
                     && $SurfaceReference->get() === $SurfaceManager,
               ],
               Op::Identical,
               [
                  1,
                  'Server',
                  UDP_Server_CLI::class,
                  true,
                  false,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  [],
                  Status::Booting,
                  true,
               ],
            )
            ->assert();

         // # An unconfigured manager has no admission authority.
         $AdmissionSocket = stream_socket_server(
            'udp://127.0.0.1:0',
            $admissionCode,
            $admissionMessage,
            STREAM_SERVER_BIND,
         );
         if ($AdmissionSocket === false) {
            throw new RuntimeException(
               "Could not bind pre-configuration admission socket: {$admissionMessage}"
            );
         }
         $AdmissionServerSocket = new ReflectionProperty(
            UDP_Server_CLI::class,
            'Socket',
         );
         $AdmissionServerSocket->setRawValue($SurfaceServer, $AdmissionSocket);
         $AdmissionPeers = new ReflectionProperty(Connections::class, 'Peers');
         $AdmissionIPs = new ReflectionProperty(Connections::class, 'IPConnections');
         $AdmissionAuthorities = new ReflectionProperty(Connection::class, 'Authorities');
         $AdmissionState = static function () use (
            $AdmissionAuthorities,
            $AdmissionIPs,
            $AdmissionPeers,
         ): array {
            return [
               isSet(Connections::$Connections) ? Connections::$Connections : [],
               $AdmissionPeers->isInitialized()
                  ? $AdmissionPeers->getValue()
                  : [],
               $AdmissionIPs->isInitialized()
                  ? $AdmissionIPs->getValue()
                  : [],
               $AdmissionAuthorities->isInitialized()
                  ? count($AdmissionAuthorities->getValue())
                  : 0,
               TimerRegistry::snapshot(),
            ];
         };
         $admissionBefore = $AdmissionState();
         $connectBefore = $SurfaceManager->connect();
         $Premature = $SurfaceManager->accept('127.0.0.41:46041');
         $prematureRejected = $Premature === null;
         $admissionRejected = $AdmissionState();
         $surfaceBeforeConfig = $Snapshot($SurfaceServer);
         $SurfaceServer->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
            maxDatagramsPerTick: 4,
         ));
         $surfaceConfigured = $Snapshot($SurfaceServer);
         $connectAfter = $SurfaceManager->connect();
         $Admitted = $SurfaceManager->accept('127.0.0.41:46041');
         $admittedAuthorized = $Admitted instanceof Connection
            && ConnectionAuthority::check($Admitted);
         $Admitted?->close();
         unset($Admitted, $Premature);
         gc_collect_cycles();
         Lease::drain();
         $admissionClean = $AdmissionState();
         fclose($AdmissionSocket);
         yield new Assertion(description: 'unconfigured manager rejects admission until Configs commit')
            ->expect(
               [
                  $surfaceBeforeConfig['configured'],
                  $surfaceBeforeConfig['transported'],
                  $connectBefore,
                  $prematureRejected,
                  $admissionRejected === $admissionBefore,
                  $surfaceConfigured['Manager'] === $SurfaceManager,
                  $surfaceConfigured['configured'],
                  $surfaceConfigured['transported'],
                  $connectAfter,
                  $admittedAuthorized,
                  $admissionClean === $admissionBefore,
               ],
               Op::Identical,
               [
                  false,
                  false,
                  false,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
               ],
            )
            ->assert();

         // # start() requires both an applied transport and the exact pre-start status.
         $RuntimeSnapshot = static function (
            UDP_Server_CLI $Server
         ) use ($Snapshot): array {
            $Socket = new ReflectionProperty(UDP_Server_CLI::class, 'Socket');
            $State = $Server->Process->State;
            $static = [];
            foreach (['binary', 'argv', 'directory'] as $name) {
               $Property = new ReflectionProperty(UDP_Server_CLI::class, $name);
               $static[$name] = $Property->getValue();
            }

            return [
               'server' => $Snapshot($Server),
               'socketInitialized' => $Socket->isInitialized($Server),
               'socket' => $Socket->isInitialized($Server)
                  ? $Socket->getRawValue($Server)
                  : null,
               'state' => [
                  $State->pidFile,
                  $State->pidLockFile,
                  $State->commandFile,
                  $State->tapFile,
               ],
               'children' => $Server->Process->Children->PIDs,
               'process' => [
                  $Server->Process->stopping,
                  $Server->Process->reloading,
               ],
               'qualifier' => Record::$qualifier,
               'launch' => $static,
            ];
         };

         $StartingState = new ReflectionProperty(Connections::class, 'Starting');
         $UnconfiguredServer = new UDP_Server_CLI(Modes::Test);
         $unconfiguredBefore = $RuntimeSnapshot($UnconfiguredServer);
         $unconfiguredStarted = $UnconfiguredServer->start();
         $unconfiguredAfter = $RuntimeSnapshot($UnconfiguredServer);
         $unconfiguredStarting = $StartingState->getValue();
         $StatusState = new ReflectionProperty(UDP_Server_CLI::class, 'Status');
         $StatusState->setRawValue($UnconfiguredServer, Status::Configuring);
         $untransportedBefore = $RuntimeSnapshot($UnconfiguredServer);
         $untransportedStarted = $UnconfiguredServer->start();
         $untransportedAfter = $RuntimeSnapshot($UnconfiguredServer);
         $untransportedStarting = $StartingState->getValue();
         yield new Assertion(description: 'start rejects an unconfigured transport without side effects')
            ->expect(
               [
                  $unconfiguredStarted,
                  $unconfiguredAfter === $unconfiguredBefore,
                  $unconfiguredAfter['server']['Status'],
                  $unconfiguredAfter['server']['transported'],
                  $unconfiguredStarting,
                  $untransportedStarted,
                  $untransportedAfter === $untransportedBefore,
                  $untransportedAfter['server']['Status'],
                  $untransportedAfter['server']['transported'],
                  $untransportedStarting,
               ],
               Op::Identical,
               [
                  false,
                  true,
                  Status::Booting,
                  false,
                  null,
                  false,
                  true,
                  Status::Configuring,
                  false,
                  null,
               ],
            )
            ->assert();

         $WrongStatusServer = new UDP_Server_CLI(Modes::Test);
         $WrongStatusServer->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 2,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
            maxDatagramsPerTick: 2,
         ));
         $StatusState->setRawValue($WrongStatusServer, Status::Booting);
         $wrongStatusBefore = $RuntimeSnapshot($WrongStatusServer);
         $wrongStatusStarted = $WrongStatusServer->start();
         $wrongStatusAfter = $RuntimeSnapshot($WrongStatusServer);
         $wrongStatusStarting = $StartingState->getValue();
         yield new Assertion(description: 'start rejects a configured server outside Configuring')
            ->expect(
               [
                  $wrongStatusStarted,
                  $wrongStatusAfter === $wrongStatusBefore,
                  $wrongStatusAfter['server']['Status'],
                  $wrongStatusAfter['server']['transported'],
                  $wrongStatusStarting,
               ],
               Op::Identical,
               [false, true, Status::Booting, true, null],
            )
            ->assert();

         // # transported and manager readiness are independent start gates.
         $OrthogonalServer = new UDP_Server_CLI(Modes::Test);
         $OrthogonalServer->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 2,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
            maxDatagramsPerTick: 2,
         ));
         $orthogonalState = $Snapshot($OrthogonalServer);
         $OrthogonalManager = $orthogonalState['Manager'];
         $TransportedState = new ReflectionProperty(
            UDP_Server_CLI::class,
            'transported',
         );
         $ConfiguredState = new ReflectionProperty(Connections::class, 'configured');
         $ConfigurationState = new ReflectionProperty(
            Connections::class,
            'Configuration',
         );
         $StartCheck = new ReflectionMethod(UDP_Server_CLI::class, 'check');
         $StartProbe = static function (
            Closure $Corrupt,
            Closure $Restore,
         ) use (
            $CommittingProperty,
            $ConfigurationState,
            $OrthogonalServer,
            $RuntimeSnapshot,
            $StartingState,
            $StartCheck,
         ): array {
            $error = '';
            $started = null;
            $same = false;
            $Corrupt();
            try {
               $before = $RuntimeSnapshot($OrthogonalServer);
               $started = $OrthogonalServer->start();
               $after = $RuntimeSnapshot($OrthogonalServer);
               $same = $after === $before;
            }
            catch (Throwable $Throwable) {
               $class = $Throwable::class;
               $message = $Throwable->getMessage();
               $error = "{$class}: {$message}";
            }
            finally {
               $Restore();
            }

            return [
               $error,
               $started,
               $same,
               $StartCheck->invoke($OrthogonalServer),
               $ConfigurationState->getValue(),
               $CommittingProperty->getValue(),
               $StartingState->getValue(),
            ];
         };
         $startOrthogonal = [
            $StartProbe(
               static function () use (
                  $OrthogonalServer,
                  $TransportedState,
               ): void {
                  $TransportedState->setRawValue($OrthogonalServer, false);
               },
               static function () use (
                  $OrthogonalServer,
                  $TransportedState,
               ): void {
                  $TransportedState->setRawValue($OrthogonalServer, true);
               },
            ),
            $StartProbe(
               static function () use (
                  $ConfiguredState,
                  $OrthogonalManager,
               ): void {
                  $ConfiguredState->setRawValue($OrthogonalManager, false);
               },
               static function () use (
                  $ConfiguredState,
                  $OrthogonalManager,
               ): void {
                  $ConfiguredState->setRawValue($OrthogonalManager, true);
               },
            ),
         ];
         yield new Assertion(description: 'start independently requires transport and manager readiness')
            ->expect(
               $startOrthogonal,
               Op::Identical,
               array_fill(0, 2, ['', false, true, true, null, false, null]),
            )
            ->assert();

         // # A child adopt failure after parent mutation rolls back externally.
         $PostAdoptServer = new class(Modes::Test) extends UDP_Server_CLI {
            // * Data
            public bool $fail = true;
            /** @var array<int,array{null|Connection,bool,bool,bool,bool,bool,bool}> */
            public array $attempts = [];

            /** Mutate through the parent, probe in-transaction admission, then fail. */
            protected function adopt (Configuring $Config): void
            {
               parent::adopt($Config);
               $Attempt = $this->Connections->accept('127.0.0.42:46042');
               $connected = $this->Connections->connect();
               $CurrentManager = new ReflectionProperty(
                  Connections::class,
                  'CurrentManager',
               );
               $CurrentConnections = new ReflectionProperty(
                  Connections::class,
                  'CurrentConnections',
               );
               $manager = $CurrentManager->getValue();
               $Reference = $CurrentConnections->getValue();
               $Event = UDP_Server_CLI::$Event;
               $managerRejected = false;
               $serverRejected = false;
               $Owner = $this;
               try {
                  new Connections($Owner);
               }
               catch (RuntimeException) {
                  $managerRejected = true;
               }
               try {
                  new UDP_Server_CLI(Modes::Test);
               }
               catch (RuntimeException) {
                  $serverRejected = true;
               }
               $CurrentReference = $CurrentConnections->getValue();
               $this->attempts[] = [
                  $Attempt,
                  $connected,
                  $managerRejected,
                  $serverRejected,
                  $CurrentManager->getValue() === $manager,
                  $CurrentReference === $Reference
                     && $CurrentReference instanceof WeakReference
                     && $CurrentReference->get() === $this->Connections,
                  UDP_Server_CLI::$Event === $Event,
               ];
               if ($this->fail) {
                  $this->fail = false;

                  throw new RuntimeException('post-adopt rollback fixture');
               }
            }

            /** Arm one deterministic failure after the next parent adoption. */
            public function arm (): void
            {
               $this->fail = true;
            }
         };
         $PostManager = $Snapshot($PostAdoptServer)['Manager'];
         $PostConfigA = new Configs(
            host: '127.0.0.42',
            port: 19842,
            workers: 2,
            maxConnections: 42,
            maxConnectionsPerIP: 7,
            connectionIdleTimeout: 5,
            maxDatagramsPerTick: 9,
         );
         $postFirstBefore = $RuntimeSnapshot($PostAdoptServer);
         $postFirstLedger = $AdmissionState();
         $postFirstError = '';
         try {
            $PostAdoptServer->configure($PostConfigA);
         }
         catch (RuntimeException $Exception) {
            $postFirstError = $Exception->getMessage();
         }
         $postFirstFailed = $RuntimeSnapshot($PostAdoptServer);
         $postFirstConfiguration = $ConfigurationState->getValue();
         $postFirstCommitting = $CommittingProperty->getValue();
         $postFirstStarting = $StartingState->getValue();
         $postOutsideAccept = $PostManager->accept('127.0.0.43:46043');
         $postOutsideConnect = $PostManager->connect();
         $postBeforeStart = $RuntimeSnapshot($PostAdoptServer);
         $postOutsideStart = $PostAdoptServer->start();
         $postAfterStart = $RuntimeSnapshot($PostAdoptServer);
         $postAfterStartStarting = $StartingState->getValue();
         $postFirstClean = $AdmissionState();
         $PostAdoptServer->configure($PostConfigA);
         $postFirstRecovered = $Snapshot($PostAdoptServer);
         yield new Assertion(description: 'first post-adopt failure rolls back and leaves admission inert')
            ->expect(
               [
                  $postFirstError,
                  $postFirstFailed === $postFirstBefore,
                  $PostAdoptServer->attempts[0] ?? null,
                  $postOutsideAccept,
                  $postOutsideConnect,
                  $postOutsideStart,
                  $postAfterStart === $postBeforeStart,
                  $postFirstClean === $postFirstLedger,
                  $postFirstConfiguration,
                  $postFirstCommitting,
                  $postFirstStarting,
                  $postFirstFailed['server']['configuring'],
                  $postAfterStartStarting,
                  $postFirstRecovered['Manager'] === $PostManager,
                  $postFirstRecovered['transported'],
                  $postFirstRecovered['configured'],
                  $postFirstRecovered['transport'],
                  $postFirstRecovered['policy'],
               ],
               Op::Identical,
               [
                  'post-adopt rollback fixture',
                  true,
                  [null, false, true, true, true, true, true],
                  null,
                  false,
                  false,
                  true,
                  true,
                  null,
                  false,
                  null,
                  false,
                  null,
                  true,
                  true,
                  true,
                  ['127.0.0.42', 19842, 2, null, null],
                  [42, 7, 5, 9],
               ],
            )
            ->assert();

         $PostConfigB = new Configs(
            host: '127.0.0.44',
            port: 19844,
            workers: 3,
            maxConnections: 44,
            maxConnectionsPerIP: 8,
            connectionIdleTimeout: 6,
            maxDatagramsPerTick: 10,
         );
         $PostAdoptServer->arm();
         $postReconfigureBefore = $RuntimeSnapshot($PostAdoptServer);
         $postReconfigureLedger = $AdmissionState();
         $postReconfigureError = '';
         try {
            $PostAdoptServer->configure($PostConfigB);
         }
         catch (RuntimeException $Exception) {
            $postReconfigureError = $Exception->getMessage();
         }
         $postReconfigureFailed = $RuntimeSnapshot($PostAdoptServer);
         $postReconfigureConfiguration = $ConfigurationState->getValue();
         $postReconfigureCommitting = $CommittingProperty->getValue();
         $postReconfigureStarting = $StartingState->getValue();
         $postReconfigureConnect = $PostManager->connect();
         $postReconfigureClean = $AdmissionState();
         $postReconfigureCheck = $StartCheck->invoke($PostAdoptServer);
         $PostAdoptServer->configure($PostConfigB);
         $postReconfigureRecovered = $Snapshot($PostAdoptServer);
         yield new Assertion(description: 'post-adopt reconfiguration failure restores the live pre-start graph')
            ->expect(
               [
                  $postReconfigureError,
                  $postReconfigureFailed === $postReconfigureBefore,
                  $PostAdoptServer->attempts[2] ?? null,
                  $postReconfigureConnect,
                  $postReconfigureClean === $postReconfigureLedger,
                  $postReconfigureCheck,
                  $postReconfigureConfiguration,
                  $postReconfigureCommitting,
                  $postReconfigureStarting,
                  $postReconfigureFailed['server']['configuring'],
                  $postReconfigureRecovered['Manager'] === $PostManager,
                  $postReconfigureRecovered['transport'],
                  $postReconfigureRecovered['policy'],
                  $postReconfigureRecovered['transported'],
                  $postReconfigureRecovered['configured'],
               ],
               Op::Identical,
               [
                  'post-adopt rollback fixture',
                  true,
                  [null, false, true, true, true, true, true],
                  true,
                  true,
                  true,
                  null,
                  false,
                  null,
                  false,
                  true,
                  ['127.0.0.44', 19844, 3, null, null],
                  [44, 8, 6, 10],
                  true,
                  true,
               ],
            )
            ->assert();

         // # A child adopt may cancel configuration by stopping its owner.
         $StopConfigServer = new class(Modes::Test) extends UDP_Server_CLI {
            // * Data
            public bool $called = false;

            /** Apply the child policy, then stop and return normally. */
            protected function adopt (Configuring $Config): void
            {
               parent::adopt($Config);
               $this->called = true;
               $this->stop();
            }
         };
         $stopConfigBefore = $RuntimeSnapshot($StopConfigServer);
         $StopConfigManager = $stopConfigBefore['server']['Manager'];
         $StopConfigMask = [];
         $stopConfigMaskRead = pcntl_sigprocmask(
            SIG_BLOCK,
            [SIGUSR1],
            $StopConfigMask,
         );
         if ($stopConfigMaskRead) {
            pcntl_sigprocmask(SIG_SETMASK, $StopConfigMask);
         }
         $stopConfigError = '';
         try {
            $StopConfigServer->configure(new Configs(
               host: '127.0.0.48',
               port: 19848,
               workers: 2,
               maxConnections: 48,
               maxConnectionsPerIP: 8,
               connectionIdleTimeout: 4,
               maxDatagramsPerTick: 12,
            ));
         }
         catch (RuntimeException $Exception) {
            $stopConfigError = $Exception->getMessage();
         }
         $StopConfigMaskAfter = [];
         $stopConfigMaskReadAfter = pcntl_sigprocmask(
            SIG_BLOCK,
            [SIGUSR1],
            $StopConfigMaskAfter,
         );
         if ($stopConfigMaskReadAfter) {
            pcntl_sigprocmask(SIG_SETMASK, $StopConfigMaskAfter);
         }
         sort($StopConfigMask);
         sort($StopConfigMaskAfter);
         $stopConfigAfter = $RuntimeSnapshot($StopConfigServer);
         $stopConfigAccept = $StopConfigManager instanceof Connections
            ? $StopConfigManager->accept('127.0.0.48:46048')
            : null;
         $stopConfigConnect = $StopConfigManager instanceof Connections
            && $StopConfigManager->connect();
         $stopConfigBeforeStart = $RuntimeSnapshot($StopConfigServer);
         $stopConfigStarted = $StopConfigServer->start();
         $stopConfigAfterStart = $RuntimeSnapshot($StopConfigServer);
         yield new Assertion(description: 'child stop cancels configuration without restoring active state')
            ->expect(
               [
                  $stopConfigMaskRead,
                  $stopConfigMaskReadAfter,
                  $StopConfigMaskAfter === $StopConfigMask,
                  $stopConfigError,
                  $StopConfigServer->called,
                  $stopConfigAfter['server']['Manager'] === $StopConfigManager,
                  $stopConfigAfter['server']['transport']
                     === $stopConfigBefore['server']['transport'],
                  $stopConfigAfter['server']['policy']
                     === $stopConfigBefore['server']['policy'],
                  $stopConfigAfter['server']['Router']
                     === $stopConfigBefore['server']['Router'],
                  $stopConfigAfter['server']['transported'],
                  $stopConfigAfter['server']['configured'],
                  $stopConfigAfter['server']['configuring'],
                  $stopConfigAfter['server']['Status'],
                  $stopConfigAfter['process'],
                  $stopConfigAfter['state'] === $stopConfigBefore['state'],
                  array_values(array_filter(
                     $stopConfigAfter['state'],
                     static fn (string $file): bool => is_file($file),
                  )),
                  $stopConfigAfter['children'] === $stopConfigBefore['children'],
                  $stopConfigAfter['socketInitialized']
                     === $stopConfigBefore['socketInitialized'],
                  $stopConfigAfter['socket'] === $stopConfigBefore['socket'],
                  $ConfigurationState->getValue(),
                  $StartingState->getValue(),
                  $CommittingProperty->getValue(),
                  $StartCheck->invoke($StopConfigServer),
                  $stopConfigAccept,
                  $stopConfigConnect,
                  $stopConfigStarted,
                  $stopConfigAfterStart === $stopConfigBeforeStart,
               ],
               Op::Identical,
               [
                  true,
                  true,
                  true,
                  'UDP server configuration was cancelled by lifecycle work.',
                  true,
                  true,
                  true,
                  true,
                  true,
                  false,
                  false,
                  false,
                  Status::Stopping,
                  [true, false],
                  true,
                  [],
                  true,
                  true,
                  true,
                  null,
                  null,
                  false,
                  false,
                  null,
                  false,
                  false,
                  true,
               ],
            )
            ->assert();

         // # The application boot seam runs only after the start claim is owned.
         $ClaimB = new UDP_Server_CLI(Modes::Test);
         $ClaimA = new UDP_Server_CLI(Modes::Test);
         $ClaimA->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 4,
            maxConnectionsPerIP: 2,
            connectionIdleTimeout: 0,
            maxDatagramsPerTick: 4,
         ));
         $ClaimConfigB = new Configs(
            host: '127.0.0.45',
            port: 19845,
            workers: 1,
            maxConnections: 5,
            maxConnectionsPerIP: 2,
            connectionIdleTimeout: 0,
            maxDatagramsPerTick: 5,
         );
         H7StartClaimApplication::arm($ClaimA, $ClaimB, $ClaimConfigB);
         $claimABefore = $RuntimeSnapshot($ClaimA);
         $claimBBefore = $Snapshot($ClaimB);
         $PreviousApplication = UDP_Server_CLI::$Application;
         $MaskBefore = [];
         $maskReadBefore = pcntl_sigprocmask(
            SIG_BLOCK,
            [SIGUSR1],
            $MaskBefore,
         );
         if ($maskReadBefore) {
            pcntl_sigprocmask(SIG_SETMASK, $MaskBefore);
         }
         $claimError = '';
         $claimStarted = null;
         try {
            UDP_Server_CLI::$Application = H7StartClaimApplication::class;
            $claimStarted = $ClaimA->start();
         }
         catch (RuntimeException $Exception) {
            $claimError = $Exception->getMessage();
         }
         finally {
            UDP_Server_CLI::$Application = $PreviousApplication;
         }
         $MaskAfter = [];
         $maskReadAfter = pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $MaskAfter);
         if ($maskReadAfter) {
            pcntl_sigprocmask(SIG_SETMASK, $MaskAfter);
         }
         sort($MaskBefore);
         sort($MaskAfter);
         $claimAAfter = $RuntimeSnapshot($ClaimA);
         $claimBAfter = $Snapshot($ClaimB);
         $claimCheck = $StartCheck->invoke($ClaimA);
         $ClaimManager = $claimAAfter['server']['Manager'];
         yield new Assertion(description: 'start claim rejects synchronous manager replacement before fork')
            ->expect(
               [
                  $claimStarted,
                  $claimError,
                  H7StartClaimApplication::$masked,
                  H7StartClaimApplication::$claimed,
                  H7StartClaimApplication::$constructionRejected,
                  H7StartClaimApplication::$configurationRejected,
                  H7StartClaimApplication::$current,
                  $claimAAfter['server'] === $claimABefore['server'],
                  $claimAAfter['socketInitialized']
                     === $claimABefore['socketInitialized'],
                  $claimAAfter['socket'] === $claimABefore['socket'],
                  $claimAAfter['state'] === $claimABefore['state'],
                  $claimAAfter['children'] === $claimABefore['children'],
                  $claimBAfter === $claimBBefore,
                  $claimAAfter['server']['Status'],
                  $StartingState->getValue(),
                  $ConfigurationState->getValue(),
                  $CommittingProperty->getValue(),
                  $claimCheck,
                  $ClaimManager instanceof Connections
                     && $ClaimManager->connect(),
                  $maskReadBefore,
                  $maskReadAfter,
                  $MaskAfter === $MaskBefore,
               ],
               Op::Identical,
               [
                  null,
                  'controlled start-claim application stop',
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  Status::Configuring,
                  null,
                  null,
                  false,
                  true,
                  true,
                  true,
                  true,
                  true,
               ],
            )
            ->assert();
         H7StartClaimApplication::reset();

         // # Application boot may cancel the claimed start and return normally.
         $StopStartServer = new UDP_Server_CLI(Modes::Test);
         $StopStartServer->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 4,
            maxConnectionsPerIP: 2,
            connectionIdleTimeout: 0,
            maxDatagramsPerTick: 4,
         ));
         $stopStartBefore = $RuntimeSnapshot($StopStartServer);
         $StopStartManager = $stopStartBefore['server']['Manager'];
         $StopStartMask = [];
         $stopStartMaskRead = pcntl_sigprocmask(
            SIG_BLOCK,
            [SIGUSR1],
            $StopStartMask,
         );
         if ($stopStartMaskRead) {
            pcntl_sigprocmask(SIG_SETMASK, $StopStartMask);
         }
         $PreviousApplication = UDP_Server_CLI::$Application;
         H7StartStopApplication::arm($StopStartServer);
         try {
            UDP_Server_CLI::$Application = H7StartStopApplication::class;
            $stopStartResult = $StopStartServer->start();
         }
         finally {
            UDP_Server_CLI::$Application = $PreviousApplication;
         }
         $stopStartCalled = H7StartStopApplication::$called;
         H7StartStopApplication::reset();
         $StopStartMaskAfter = [];
         $stopStartMaskReadAfter = pcntl_sigprocmask(
            SIG_BLOCK,
            [SIGUSR1],
            $StopStartMaskAfter,
         );
         if ($stopStartMaskReadAfter) {
            pcntl_sigprocmask(SIG_SETMASK, $StopStartMaskAfter);
         }
         sort($StopStartMask);
         sort($StopStartMaskAfter);
         $stopStartAfter = $RuntimeSnapshot($StopStartServer);
         $stopStartExpected = $stopStartBefore;
         $stopStartExpected['server']['Status'] = Status::Stopping;
         $stopStartExpected['process'] = [true, false];
         yield new Assertion(description: 'application stop cancels claimed start before state or fork')
            ->expect(
               [
                  $stopStartMaskRead,
                  $stopStartMaskReadAfter,
                  $StopStartMaskAfter === $StopStartMask,
                  $stopStartResult,
                  $stopStartCalled,
                  $stopStartAfter === $stopStartExpected,
                  $stopStartAfter['server']['Status'],
                  $stopStartAfter['process'],
                  $stopStartAfter['state'] === $stopStartBefore['state'],
                  array_values(array_filter(
                     $stopStartAfter['state'],
                     static fn (string $file): bool => is_file($file),
                  )),
                  $stopStartAfter['children'],
                  is_resource($stopStartAfter['socket']),
                  $StartingState->getValue(),
                  $ConfigurationState->getValue(),
                  $CommittingProperty->getValue(),
                  $StartCheck->invoke($StopStartServer),
                  $StopStartManager instanceof Connections
                     && $StopStartManager->connect(),
               ],
               Op::Identical,
               [
                  true,
                  true,
                  true,
                  false,
                  true,
                  true,
                  Status::Stopping,
                  [true, false],
                  true,
                  [],
                  [],
                  false,
                  null,
                  null,
                  false,
                  false,
                  false,
               ],
            )
            ->assert();

         // # Pending SIGUSR1 runs only after the configuration token is released.
         $SignalServer = new class(Modes::Test) extends UDP_Server_CLI {
            // * Data
            public bool $masked = false;
            public bool $bomb = true;

            /** Queue a finite standard-signal burst after parent adoption. */
            protected function adopt (Configuring $Config): void
            {
               parent::adopt($Config);
               if ($this->bomb === false) {
                  return;
               }
               $this->bomb = false;
               $Mask = [];
               $read = pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $Mask);
               if ($read) {
                  pcntl_sigprocmask(SIG_SETMASK, $Mask);
               }
               $this->masked = in_array(SIGUSR1, $Mask, true);
               for ($signal = 0; $signal < 32; $signal++) {
                  posix_kill(getmypid(), SIGUSR1);
               }
            }
         };
         $SignalManager = $Snapshot($SignalServer)['Manager'];
         $SignalConfiguring = new ReflectionProperty(
            UDP_Server_CLI::class,
            'configuring',
         );
         $SignalMask = [];
         $signalMaskRead = pcntl_sigprocmask(
            SIG_BLOCK,
            [SIGUSR1],
            $SignalMask,
         );
         if ($signalMaskRead) {
            pcntl_sigprocmask(SIG_SETMASK, $SignalMask);
         }
         $PreviousUSR1 = pcntl_signal_get_handler(SIGUSR1);
         $PreviousAsync = pcntl_async_signals();
         $signalCalls = 0;
         $signalSafe = true;
         $signalObservations = [];
         $signalError = '';
         $signalDispatchError = '';
         try {
            pcntl_sigprocmask(SIG_UNBLOCK, [SIGUSR1]);
            pcntl_async_signals(true);
            pcntl_signal(
               SIGUSR1,
               static function () use (
                  &$signalCalls,
                  &$signalObservations,
                  &$signalSafe,
                  $CommittingProperty,
                  $ConfigurationState,
                  $SignalConfiguring,
                  $SignalServer,
                  $StartingState,
               ): void {
                  $signalCalls++;
                  $Observation = [
                     $ConfigurationState->getValue(),
                     $SignalConfiguring->getRawValue($SignalServer),
                     $CommittingProperty->getValue(),
                     $StartingState->getValue(),
                  ];
                  $signalObservations[] = $Observation;
                  $safe = $Observation === [null, false, false, null];
                  $signalSafe = $signalSafe && $safe;
                  if ($safe === false) {
                     throw new RuntimeException(
                        'SIGUSR1 entered an active UDP configuration transaction.'
                     );
                  }
               },
               false,
            );
            try {
               $SignalServer->configure(new Configs(
                  host: '127.0.0.46',
                  port: 19846,
                  workers: 2,
                  maxConnections: 46,
                  maxConnectionsPerIP: 9,
                  connectionIdleTimeout: 4,
                  maxDatagramsPerTick: 11,
               ));
            }
            catch (Throwable $Throwable) {
               $class = $Throwable::class;
               $message = $Throwable->getMessage();
               $signalError = "{$class}: {$message}";
            }
            try {
               pcntl_signal_dispatch();
            }
            catch (Throwable $Throwable) {
               $class = $Throwable::class;
               $message = $Throwable->getMessage();
               $signalDispatchError = "{$class}: {$message}";
            }
         }
         finally {
            pcntl_signal(
               SIGUSR1,
               $PreviousUSR1 === false ? SIG_DFL : $PreviousUSR1,
               false,
            );
            pcntl_async_signals($PreviousAsync);
            pcntl_sigprocmask(SIG_SETMASK, $SignalMask);
         }
         $SignalMaskAfter = [];
         $signalMaskReadAfter = pcntl_sigprocmask(
            SIG_BLOCK,
            [SIGUSR1],
            $SignalMaskAfter,
         );
         if ($signalMaskReadAfter) {
            pcntl_sigprocmask(SIG_SETMASK, $SignalMaskAfter);
         }
         sort($SignalMask);
         sort($SignalMaskAfter);
         $signalAfter = $Snapshot($SignalServer);
         $signalConfiguration = $ConfigurationState->getValue();
         $signalCommitting = $CommittingProperty->getValue();
         $signalStarting = $StartingState->getValue();
         $signalConnect = $SignalManager instanceof Connections
            && $SignalManager->connect();
         $SignalServer->configure(new Configs(
            host: '127.0.0.47',
            port: 19847,
            workers: 3,
            maxConnections: 47,
            maxConnectionsPerIP: 8,
            connectionIdleTimeout: 3,
            maxDatagramsPerTick: 10,
         ));
         $signalRecovered = $Snapshot($SignalServer);
         yield new Assertion(description: 'signal mask releases configuration before pending SIGUSR1 delivery')
            ->expect(
               [
                  $signalMaskRead,
                  $signalMaskReadAfter,
                  $SignalMaskAfter === $SignalMask,
                  pcntl_signal_get_handler(SIGUSR1) === $PreviousUSR1,
                  pcntl_async_signals() === $PreviousAsync,
                  $SignalServer->masked,
                  $signalCalls > 0,
                  $signalSafe,
                  $signalObservations,
                  $signalError,
                  $signalDispatchError,
                  $signalAfter['Manager'] === $SignalManager,
                  $signalAfter['transported'],
                  $signalAfter['configured'],
                  $signalAfter['configuring'],
                  $signalConfiguration,
                  $signalCommitting,
                  $signalStarting,
                  $signalConnect,
                  $signalRecovered['Manager'] === $SignalManager,
                  $signalRecovered['transport'],
                  $signalRecovered['policy'],
                  $signalRecovered['configured'],
               ],
               Op::Identical,
               [
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  [[null, false, false, null]],
                  '',
                  '',
                  true,
                  true,
                  true,
                  false,
                  null,
                  false,
                  null,
                  true,
                  true,
                  ['127.0.0.47', 19847, 3, null, null],
                  [47, 8, 3, 10],
                  true,
               ],
            )
            ->assert();

         // # A server superseded by a new manager must fail before bind/start state.
         $StaleServer = new UDP_Server_CLI(Modes::Test);
         $StaleServer->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 3,
            maxConnectionsPerIP: 2,
            connectionIdleTimeout: 1,
            maxDatagramsPerTick: 4,
         ));
         $staleBefore = $Snapshot($StaleServer);
         $SocketState = new ReflectionProperty(UDP_Server_CLI::class, 'Socket');
         $staleSocketInitialized = $SocketState->isInitialized($StaleServer);
         $staleSocketBefore = $staleSocketInitialized
            ? $SocketState->getRawValue($StaleServer)
            : null;

         $CurrentServer = new UDP_Server_CLI(Modes::Test);
         $CurrentServer->configure(new Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxConnections: 5,
            maxConnectionsPerIP: 3,
            connectionIdleTimeout: 2,
            maxDatagramsPerTick: 6,
         ));
         $currentBefore = $Snapshot($CurrentServer);
         $CurrentEvent = UDP_Server_CLI::$Event;
         $EventConnections = new ReflectionProperty(Select::class, 'Connections');
         $CurrentManager = new ReflectionProperty(Connections::class, 'CurrentManager');
         $ManagerIdentity = new ReflectionProperty(Connections::class, 'ManagerIdentity');
         $staleStarted = $StaleServer->start();
         $staleStarting = $StartingState->getValue();
         $staleAfter = $Snapshot($StaleServer);
         $currentAfter = $Snapshot($CurrentServer);
         $CurrentReference = $CurrentConnectionsProperty->getValue();
         $staleSocketAfterInitialized = $SocketState->isInitialized($StaleServer);
         $staleSocketAfter = $staleSocketAfterInitialized
            ? $SocketState->getRawValue($StaleServer)
            : null;
         yield new Assertion(description: 'superseded server cannot start or steal the current graph')
            ->expect(
               [
                  $staleStarted,
                  $staleStarting,
                  $staleAfter === $staleBefore,
                  $staleAfter['Status'],
                  $staleSocketAfterInitialized === $staleSocketInitialized,
                  $staleSocketAfter === $staleSocketBefore,
                  is_resource($staleSocketAfter),
                  $currentAfter === $currentBefore,
                  $CurrentReference instanceof WeakReference
                     && $CurrentReference->get() === $currentBefore['Manager'],
                  $CurrentManager->getValue()
                     === $ManagerIdentity->getRawValue($currentBefore['Manager']),
                  UDP_Server_CLI::$Event === $CurrentEvent,
                  $EventConnections->getRawValue($CurrentEvent)
                     === $currentBefore['Manager'],
               ],
               Op::Identical,
               [
                  false,
                  null,
                  true,
                  Status::Configuring,
                  true,
                  true,
                  false,
                  true,
                  true,
                  true,
                  true,
                  true,
               ],
            )
            ->assert();

         // # Every edge of the current admission/event graph is mandatory.
         $GraphServer = $CurrentServer;
         $GraphManager = $currentBefore['Manager'];
         $GraphRouter = $currentBefore['Router'];
         $GraphEvent = $CurrentEvent;
         $DetachedServer = (new ReflectionClass(UDP_Server_CLI::class))
            ->newInstanceWithoutConstructor();
         $DetachedConnections = (new ReflectionClass(Connections::class))
            ->newInstanceWithoutConstructor();
         $OtherRouter = new Router(
            $DetachedServer,
            $DetachedConnections,
            1,
         );
         $InvalidEvent = new H7InvalidUDPEvent;
         $ManagerServerEdge = new ReflectionProperty(Connections::class, 'Server');
         $ManagerRouterEdge = new ReflectionProperty(Connections::class, 'Router');
         $RouterServerEdge = new ReflectionProperty(Router::class, 'Server');
         $RouterConnectionsEdge = new ReflectionProperty(
            Router::class,
            'Connections',
         );
         $GraphStatusEdge = new ReflectionProperty(UDP_Server_CLI::class, 'Status');
         $GraphTransportedEdge = new ReflectionProperty(
            UDP_Server_CLI::class,
            'transported',
         );
         $GraphConnectionsEdge = new ReflectionProperty(
            UDP_Server_CLI::class,
            'Connections',
         );
         $UnsetServerSlot = Closure::bind(
            static function (UDP_Server_CLI $Server, string $name): void {
               unset($Server->{$name});
            },
            null,
            UDP_Server_CLI::class,
         );
         $UnsetManagerSlot = Closure::bind(
            static function (Connections $Connections, string $name): void {
               unset($Connections->{$name});
            },
            null,
            Connections::class,
         );
         $UnsetRouterSlot = Closure::bind(
            static function (Router $Router, string $name): void {
               unset($Router->{$name});
            },
            null,
            Router::class,
         );
         $UnsetEventSlot = Closure::bind(
            static function (Select $Event, string $name): void {
               unset($Event->{$name});
            },
            null,
            Select::class,
         );
         $GraphCheck = new ReflectionMethod(UDP_Server_CLI::class, 'check');
         $GraphClean = $RuntimeSnapshot($GraphServer);
         $Corruptions = [
            [
               static function () use (
                  $DetachedServer,
                  $GraphManager,
                  $ManagerServerEdge,
               ): void {
                  $ManagerServerEdge->setRawValue($GraphManager, $DetachedServer);
               },
               static function () use (
                  $GraphManager,
                  $GraphServer,
                  $ManagerServerEdge,
               ): void {
                  $ManagerServerEdge->setRawValue($GraphManager, $GraphServer);
               },
            ],
            [
               static function () use (
                  $GraphManager,
                  $ManagerRouterEdge,
                  $OtherRouter,
               ): void {
                  $ManagerRouterEdge->setRawValue($GraphManager, $OtherRouter);
               },
               static function () use (
                  $GraphManager,
                  $GraphRouter,
                  $ManagerRouterEdge,
               ): void {
                  $ManagerRouterEdge->setRawValue($GraphManager, $GraphRouter);
               },
            ],
            [
               static function () use (
                  $DetachedServer,
                  $GraphRouter,
                  $RouterServerEdge,
               ): void {
                  $RouterServerEdge->setRawValue($GraphRouter, $DetachedServer);
               },
               static function () use (
                  $GraphRouter,
                  $GraphServer,
                  $RouterServerEdge,
               ): void {
                  $RouterServerEdge->setRawValue($GraphRouter, $GraphServer);
               },
            ],
            [
               static function () use (
                  $DetachedConnections,
                  $GraphRouter,
                  $RouterConnectionsEdge,
               ): void {
                  $RouterConnectionsEdge->setRawValue(
                     $GraphRouter,
                     $DetachedConnections,
                  );
               },
               static function () use (
                  $GraphManager,
                  $GraphRouter,
                  $RouterConnectionsEdge,
               ): void {
                  $RouterConnectionsEdge->setRawValue($GraphRouter, $GraphManager);
               },
            ],
            [
               static function () use (
                  $DetachedConnections,
                  $EventConnections,
                  $GraphEvent,
               ): void {
                  $EventConnections->setRawValue(
                     $GraphEvent,
                     $DetachedConnections,
                  );
               },
               static function () use (
                  $EventConnections,
                  $GraphEvent,
                  $GraphManager,
               ): void {
                  $EventConnections->setRawValue($GraphEvent, $GraphManager);
               },
            ],
            [
               static function () use ($InvalidEvent): void {
                  UDP_Server_CLI::$Event = $InvalidEvent;
               },
               static function () use ($GraphEvent): void {
                  UDP_Server_CLI::$Event = $GraphEvent;
               },
            ],
            [
               static function () use (
                  $GraphManager,
                  $UnsetManagerSlot,
               ): void {
                  $UnsetManagerSlot($GraphManager, 'Server');
               },
               static function () use (
                  $GraphManager,
                  $GraphServer,
                  $ManagerServerEdge,
               ): void {
                  $ManagerServerEdge->setRawValue($GraphManager, $GraphServer);
               },
            ],
            [
               static function () use (
                  $GraphManager,
                  $UnsetManagerSlot,
               ): void {
                  $UnsetManagerSlot($GraphManager, 'Router');
               },
               static function () use (
                  $GraphManager,
                  $GraphRouter,
                  $ManagerRouterEdge,
               ): void {
                  $ManagerRouterEdge->setRawValue($GraphManager, $GraphRouter);
               },
            ],
            [
               static function () use (
                  $GraphRouter,
                  $UnsetRouterSlot,
               ): void {
                  $UnsetRouterSlot($GraphRouter, 'Server');
               },
               static function () use (
                  $GraphRouter,
                  $GraphServer,
                  $RouterServerEdge,
               ): void {
                  $RouterServerEdge->setRawValue($GraphRouter, $GraphServer);
               },
            ],
            [
               static function () use (
                  $GraphRouter,
                  $UnsetRouterSlot,
               ): void {
                  $UnsetRouterSlot($GraphRouter, 'Connections');
               },
               static function () use (
                  $GraphManager,
                  $GraphRouter,
                  $RouterConnectionsEdge,
               ): void {
                  $RouterConnectionsEdge->setRawValue($GraphRouter, $GraphManager);
               },
            ],
            [
               static function () use (
                  $GraphEvent,
                  $UnsetEventSlot,
               ): void {
                  $UnsetEventSlot($GraphEvent, 'Connections');
               },
               static function () use (
                  $EventConnections,
                  $GraphEvent,
                  $GraphManager,
               ): void {
                  $EventConnections->setRawValue($GraphEvent, $GraphManager);
               },
            ],
            [
               static function () use (
                  $GraphServer,
                  $UnsetServerSlot,
               ): void {
                  $UnsetServerSlot($GraphServer, 'Status');
               },
               static function () use (
                  $GraphServer,
                  $GraphStatusEdge,
               ): void {
                  $GraphStatusEdge->setRawValue(
                     $GraphServer,
                     Status::Configuring,
                  );
               },
            ],
            [
               static function () use (
                  $GraphServer,
                  $UnsetServerSlot,
               ): void {
                  $UnsetServerSlot($GraphServer, 'transported');
               },
               static function () use (
                  $GraphServer,
                  $GraphTransportedEdge,
               ): void {
                  $GraphTransportedEdge->setRawValue($GraphServer, true);
               },
            ],
            [
               static function () use (
                  $DetachedConnections,
                  $GraphConnectionsEdge,
                  $GraphServer,
               ): void {
                  $GraphConnectionsEdge->setRawValue(
                     $GraphServer,
                     $DetachedConnections,
                  );
               },
               static function () use (
                  $GraphConnectionsEdge,
                  $GraphManager,
                  $GraphServer,
               ): void {
                  $GraphConnectionsEdge->setRawValue($GraphServer, $GraphManager);
               },
            ],
            [
               static function () use (
                  $GraphServer,
                  $UnsetServerSlot,
               ): void {
                  $UnsetServerSlot($GraphServer, 'Connections');
               },
               static function () use (
                  $GraphConnectionsEdge,
                  $GraphManager,
                  $GraphServer,
               ): void {
                  $GraphConnectionsEdge->setRawValue($GraphServer, $GraphManager);
               },
            ],
         ];
         $GraphProbe = static function (
            Closure $Corrupt,
            Closure $Restore,
         ) use (
            $CurrentConnectionsProperty,
            $CurrentManager,
            $EventConnections,
            $GraphCheck,
            $GraphClean,
            $GraphEvent,
            $GraphManager,
            $GraphServer,
            $ManagerIdentity,
            $RuntimeSnapshot,
            $StartingState,
         ): array {
            $error = '';
            $started = null;
            $same = false;
            $Corrupt();
            try {
               $before = $RuntimeSnapshot($GraphServer);
               $started = $GraphServer->start();
               $after = $RuntimeSnapshot($GraphServer);
               $same = $after === $before;
            }
            catch (Throwable $Throwable) {
               $class = $Throwable::class;
               $message = $Throwable->getMessage();
               $error = "{$class}: {$message}";
            }
            finally {
               $Restore();
            }
            $CurrentReference = $CurrentConnectionsProperty->getValue();

            return [
               $error,
               $started,
               $same,
               $GraphCheck->invoke($GraphServer),
               $RuntimeSnapshot($GraphServer) === $GraphClean,
               $CurrentReference instanceof WeakReference
                  && $CurrentReference->get() === $GraphManager,
               $CurrentManager->getValue()
                  === $ManagerIdentity->getRawValue($GraphManager),
               UDP_Server_CLI::$Event === $GraphEvent,
               $EventConnections->getRawValue($GraphEvent) === $GraphManager,
               $StartingState->getValue(),
            ];
         };
         $graphResults = [];
         foreach ($Corruptions as [$Corrupt, $Restore]) {
            $graphResults[] = $GraphProbe($Corrupt, $Restore);
         }
         yield new Assertion(description: 'start rejects each corrupted graph edge without side effects')
            ->expect(
               $graphResults,
               Op::Identical,
               array_fill(0, 15, [
                  '',
                  false,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  null,
               ]),
            )
            ->assert();

         // # Admission — exact global/per-IP boundaries.
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new Configs(
            host: '127.0.0.1',
            port: 19994,
            workers: 1,
            maxConnections: 2,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
         ));

         $Socket = stream_socket_server(
            'udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND
         );
         yield new Assertion(description: 'the config fixture UDP socket is bound')
            ->expect($Socket !== false)
            ->to->be(true)
            ->assert();
         if ($Socket === false) {
            return;
         }

         $SocketProperty = new ReflectionProperty($Server, 'Socket');
         $SocketProperty->setValue($Server, $Socket);

         // # Crossing the start boundary makes every later configure a no-op.
         $Connections = $Server->Connections;
         $Event = UDP_Server_CLI::$Event;
         $RouterProperty = new ReflectionProperty(Connections::class, 'Router');
         $OldRouter = $RouterProperty->getRawValue($Connections);
         $ReadingProperty = new ReflectionProperty(Select::class, 'reading');
         $registered = $Event->add($Socket, Select::EVENT_READ, $OldRouter);
         $socketID = (int) $Socket;
         $StatusProperty->setRawValue($Server, Status::Running);
         $runningBefore = $Snapshot($Server);
         $payloadBefore = $ReadingProperty->getRawValue($Event)[$socketID] ?? null;
         $RunningNoPeer = $Server->configure(new Configs(
            host: '127.0.0.31',
            port: 19831,
            workers: 3,
            user: 'rejected-user',
            group: 'rejected-group',
            maxConnections: 31,
            maxConnectionsPerIP: 9,
            connectionIdleTimeout: 7,
            maxDatagramsPerTick: 11,
         ));
         $runningWithoutPeer = $Snapshot($Server);
         $payloadWithoutPeer = $ReadingProperty->getRawValue($Event)[$socketID] ?? null;
         yield new Assertion(description: 'Running configure without peers preserves endpoint and Select payload')
            ->expect(
               [
                  $registered,
                  $RunningNoPeer === $Server,
                  $runningWithoutPeer === $runningBefore,
                  $runningWithoutPeer['Status'],
                  $payloadBefore === $OldRouter,
                  $payloadWithoutPeer === $OldRouter,
               ],
               Op::Identical,
               [true, true, true, Status::Running, true, true],
            )
            ->assert();

         $RunningPeer = $Connections->accept('127.0.0.31:46031');
         $runningWithPeerBefore = $Snapshot($Server);
         $payloadWithPeerBefore = $ReadingProperty->getRawValue($Event)[$socketID] ?? null;
         $RunningWithPeer = $Server->configure(new Configs(
            host: '127.0.0.32',
            port: 19832,
            workers: 4,
            user: 'still-rejected',
            group: 'still-rejected',
            maxConnections: 32,
            maxConnectionsPerIP: 10,
            connectionIdleTimeout: 8,
            maxDatagramsPerTick: 12,
         ));
         $runningWithPeer = $Snapshot($Server);
         $payloadWithPeer = $ReadingProperty->getRawValue($Event)[$socketID] ?? null;
         yield new Assertion(description: 'Running configure with a live peer preserves all state')
            ->expect(
               [
                  $RunningPeer instanceof Connection,
                  $RunningWithPeer === $Server,
                  $runningWithPeer === $runningWithPeerBefore,
                  $runningWithPeer['Status'],
                  $payloadWithPeerBefore === $OldRouter,
                  $payloadWithPeer === $OldRouter,
               ],
               Op::Identical,
               [true, true, true, Status::Running, true, true],
            )
            ->assert();
         $RunningPeer?->close();
         unset($RunningPeer);
         gc_collect_cycles();
         Lease::drain();
         $Event->del($Socket, Select::EVENT_READ);

         // # Before start, the same manager receives one atomic policy/Router update.
         $StatusProperty->setRawValue($Server, Status::Configuring);
         $preStartBefore = $Snapshot($Server);
         $EventBefore = UDP_Server_CLI::$Event;
         $PreStart = $Server->configure(new Configs(
            host: '127.0.0.33',
            port: 19833,
            workers: 5,
            user: 'pre-start-user',
            group: 'pre-start-group',
            maxConnections: 33,
            maxConnectionsPerIP: 11,
            connectionIdleTimeout: 9,
            maxDatagramsPerTick: 13,
         ));
         $preStartAfter = $Snapshot($Server);
         $PreStartRouter = $preStartAfter['Router'];
         $RouterBatch = new ReflectionProperty(Router::class, 'batch');
         yield new Assertion(description: 'pre-start reconfiguration updates policy and Router on the same manager')
            ->expect(
               [
                  $PreStart === $Server,
                  $preStartAfter['Manager'] === $preStartBefore['Manager'],
                  UDP_Server_CLI::$Event === $EventBefore,
                  $preStartAfter['Router'] !== $preStartBefore['Router'],
                  $PreStartRouter instanceof Router
                     && $PreStartRouter->Server === $Server,
                  $PreStartRouter instanceof Router
                     && $PreStartRouter->Connections === $Connections,
                  $PreStartRouter instanceof Router
                     ? $RouterBatch->getRawValue($PreStartRouter)
                     : 0,
                  $preStartAfter['transported'],
                  $preStartAfter['transport'],
                  $preStartAfter['policy'],
                  $preStartAfter['Status'],
                  $preStartAfter['configuring'],
               ],
               Op::Identical,
               [
                  true,
                  true,
                  true,
                  true,
                  true,
                  true,
                  13,
                  true,
                  [
                     '127.0.0.33',
                     19833,
                     5,
                     'pre-start-user',
                     'pre-start-group',
                  ],
                  [33, 11, 9, 13],
                  Status::Configuring,
                  false,
               ],
            )
            ->assert();

         // @ Restore the admission values exercised by the remaining cases.
         $Server->configure(new Configs(
            host: '127.0.0.1',
            port: 19994,
            workers: 1,
            maxConnections: 2,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
         ));

         Connections::$stats = false;
         $ThrowingMirror = new class($Socket, '127.0.0.7:40997') extends Connection {
            /** Throw when manager replacement releases this valid mirror. */
            public function __destruct ()
            {
               throw new RuntimeException('replacement mirror destructor');
            }
         };
         unset($ThrowingMirror->Connection); // @phpstan-ignore unset.possiblyHookedProperty
         Connections::$Connections['127.0.0.7:40997'] = $ThrowingMirror;
         unset($ThrowingMirror);
         $managerThrown = '';
         $Expiring = null;
         try {
            $Expiring = new UDP_Server_CLI(Modes::Test);
            $Expiring->configure(new Configs(
               host: '127.0.0.1',
               port: 19993,
               workers: 1,
               connectionIdleTimeout: 1,
            ));
         }
         catch (Throwable $Throwable) {
            $managerThrown = $Throwable->getMessage();
         }
         Connections::$stats = true;
         if ($Expiring instanceof UDP_Server_CLI === false) {
            throw new RuntimeException("Expiring manager construction failed: {$managerThrown}");
         }
         $SocketProperty->setValue($Expiring, $Socket);
         $ExpiringConnections = $Expiring->Connections;
         $ExpiringPeer = $ExpiringConnections->accept('127.0.0.9:40999');
         $expiringAdmitted = $ExpiringPeer instanceof Connection;
         $ExpiringWeak = $ExpiringPeer instanceof Connection
            ? WeakReference::create($ExpiringPeer)
            : null;
         if ($ExpiringPeer instanceof Connection) {
            $ExpiringPeer->used = time() - 2;
         }
         $Tasks = new ReflectionProperty(Timer::class, 'tasks');
         $due = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }
         $Tasks->setValue(null, $due === [] ? [] : [time() - 1 => $due]);
         Timer::tick();
         $expiringExpiration = $ExpiringPeer?->expiration;
         $expiringStatus = $ExpiringPeer?->status;
         unset($ExpiringPeer);
         gc_collect_cycles();
         Lease::drain();
         $Disabled = new UDP_Server_CLI(Modes::Test);
         $Disabled->configure(new Configs(
            host: '127.0.0.1',
            port: 19992,
            workers: 1,
            maxConnections: 1,
            connectionIdleTimeout: 0,
         ));
         $SocketProperty->setValue($Disabled, $Socket);
         $DisabledConnections = $Disabled->Connections;
         $StalePeer = $ExpiringConnections->accept('127.0.0.10:41000');
         $DisabledPeer = $DisabledConnections->accept('127.0.0.8:40998');
         $Peers = new ReflectionProperty(Connections::class, 'Peers');
         yield new Assertion(description: 'replacement manager invalidates obsolete admission paths')
            ->expect(
               [
                  $managerThrown,
                  $expiringAdmitted,
                  $expiringExpiration,
                  $expiringStatus,
                  $ExpiringWeak?->get(),
                  $StalePeer,
                  $DisabledPeer instanceof Connection,
                  $DisabledPeer?->expiration,
                  $DisabledPeer?->status,
                  count($Peers->getValue()),
                  (Connections::$Connections['127.0.0.8:40998'] ?? null)
                     === $DisabledPeer,
                  $Tasks->getValue(),
               ],
               Op::Identical,
               [
                  '',
                  true,
                  1,
                  Connections::STATUS_CLOSED,
                  null,
                  null,
                  true,
                  0,
                  Connections::STATUS_ESTABLISHED,
                  1,
                  true,
                  [],
               ],
            )
            ->assert();
         $DisabledPeer?->close();
         unset(
            $StalePeer,
            $DisabledPeer,
            $ExpiringConnections,
            $DisabledConnections,
            $Expiring,
            $Disabled,
         );

         H7NestedManagerMirror::$SharedSocket = $Socket;
         H7NestedManagerMirror::$error = '';
         Connections::$stats = false;
         $NestedMirror = new H7NestedManagerMirror($Socket, '127.0.0.6:40996');
         unset($NestedMirror->Connection); // @phpstan-ignore unset.possiblyHookedProperty
         Connections::$Connections['127.0.0.6:40996'] = $NestedMirror;
         unset($NestedMirror);
         $replacementThrown = '';
         $OuterReplacement = null;
         try {
            $OuterReplacement = new UDP_Server_CLI(Modes::Test);
         }
         catch (RuntimeException $Exception) {
            $replacementThrown = $Exception->getMessage();
         }
         Connections::$stats = true;
         $NestedManager = H7NestedManagerMirror::$Nested;
         if ($OuterReplacement instanceof UDP_Server_CLI) {
            $OuterReplacement->configure(new Configs(
               host: '127.0.0.1',
               port: 0,
               workers: 1,
               connectionIdleTimeout: 0,
            ));
            $SocketProperty->setValue($OuterReplacement, $Socket);
         }
         $OuterConnections = $OuterReplacement?->Connections;
         $OuterPeer = $OuterConnections?->accept('127.0.0.5:40995');
         yield new Assertion(description: 'nested manager construction cannot steal outer authority')
            ->expect(
               [
                  $replacementThrown,
                  str_contains(H7NestedManagerMirror::$error, 'already active'),
                  $NestedManager,
                  $OuterReplacement instanceof UDP_Server_CLI,
                  $OuterPeer instanceof Connection,
               ],
               Op::Identical,
               ['', true, null, true, true],
            )
            ->assert();
         $OuterPeer?->close();
         unset($OuterPeer, $OuterConnections, $OuterReplacement, $NestedManager);
         H7NestedManagerMirror::$Nested = null;
         H7NestedManagerMirror::$error = '';
         H7NestedManagerMirror::$SharedSocket = null;
         H7ClearMirrorConnection::$remaining = 0;

         // # A terminal object that cannot stabilize must remain charged to
         //   every private ceiling until a later bounded scrub succeeds.
         H7CarryChain::$armed = true;
         H7CarryChain::$remaining = 70;
         H7CarryChain::$destructions = 0;
         $UnstableServer = new UDP_Server_CLI(Modes::Test);
         $UnstableServer->configure(new Configs(
            host: '127.0.0.1',
            port: 19991,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 1,
         ));
         $SocketProperty->setValue($UnstableServer, $Socket);
         $UnstableConnections = $UnstableServer->Connections;
         $unstablePeer = '127.0.0.1:53001';
         $Unstable = $UnstableConnections->accept($unstablePeer);
         $UnstableWeak = $Unstable instanceof Connection
            ? WeakReference::create($Unstable)
            : null;
         if ($Unstable instanceof Connection) {
            $Unstable->decoded = new H7CarryChain($Unstable);
         }
         $unstableClosed = $UnstableConnections->close($unstablePeer);
         $IPConnections = new ReflectionProperty(Connections::class, 'IPConnections');
         $QuarantineTokens = new ReflectionProperty(Connections::class, 'quarantineTokens');
         $Blocked = $UnstableConnections->accept('127.0.0.2:53002');
         $unstableState = [
            $Unstable instanceof Connection,
            $unstableClosed,
            H7CarryChain::$destructions,
            count($Peers->getValue()),
            $IPConnections->getValue(),
            count($QuarantineTokens->getValue()),
            count(TimerRegistry::snapshot()),
            $Blocked,
         ];

         H7CarryChain::$armed = false;
         $stabilized = $UnstableConnections->close($unstablePeer);
         $AfterUnstable = $UnstableConnections->accept('127.0.0.2:53002');
         $stableRetained = [
            $stabilized,
            $AfterUnstable,
            count($Peers->getValue()),
            $IPConnections->getValue(),
         ];
         unset($Blocked, $AfterUnstable, $Unstable);
         gc_collect_cycles();
         Lease::drain();
         $RecoveredUnstable = $UnstableConnections->accept('127.0.0.2:53002');
         yield new Assertion(description: 'unstable close stays charged until stabilization and death')
            ->expect(
               [
                  $unstableState,
                  $stableRetained,
                  $UnstableWeak?->get(),
                  $RecoveredUnstable instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  [true, true, 32, 1, ['127.0.0.1' => 1], 1, 1, null],
                  [true, null, 1, ['127.0.0.1' => 1]],
                  null,
                  true,
                  1,
                  ['127.0.0.2' => 1],
               ],
            )
            ->assert();
         $RecoveredUnstable?->close();
         unset(
            $RecoveredUnstable,
            $UnstableConnections,
            $UnstableServer,
         );

         // # Timer callback release runs cyclic GC. A tuple closed by that
         //   boundary must not be restored from a pre-release manager snapshot.
         H7CarryChain::$armed = true;
         H7CarryChain::$remaining = -1;
         H7CarryChain::$destructions = 0;
         H7CarryGCBomb::$ran = false;
         $CarryServer = new UDP_Server_CLI(Modes::Test);
         $CarryServer->configure(new Configs(
            host: '127.0.0.1',
            port: 19990,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 1,
         ));
         $SocketProperty->setValue($CarryServer, $Socket);
         $CarryConnections = $CarryServer->Connections;
         $carryPeer = '127.0.0.1:54001';
         $Carried = $CarryConnections->accept($carryPeer);
         if ($Carried instanceof Connection === false) {
            throw new RuntimeException('Could not admit the carried H7 peer.');
         }
         $CarriedWeak = WeakReference::create($Carried);
         $Carried->decoded = new H7CarryChain($Carried);
         $Bomb = new H7CarryGCBomb($Carried);
         $Bomb->Self = $Bomb;
         unset($Bomb);
         $Carried->close();
         $beforeCarryRelease = [
            H7CarryGCBomb::$ran,
            H7CarryChain::$destructions,
            count($Peers->getValue()),
            $IPConnections->getValue(),
            count($QuarantineTokens->getValue()),
            count(TimerRegistry::snapshot()),
         ];
         unset($Carried);

         $NewCarryServer = new UDP_Server_CLI(Modes::Test);
         $NewCarryServer->configure(new Configs(
            host: '127.0.0.1',
            port: 19989,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 1,
         ));
         $SocketProperty->setValue($NewCarryServer, $Socket);
         $NewCarryConnections = $NewCarryServer->Connections;
         $CurrentPeers = $Peers->getValue();
         $afterCarryRelease = [
            H7CarryGCBomb::$ran,
            isSet($CurrentPeers[$carryPeer]),
            count($CurrentPeers),
            $IPConnections->getValue(),
            count($QuarantineTokens->getValue()),
            count(TimerRegistry::snapshot()),
            $CarriedWeak->get() instanceof Connection,
         ];
         $NewCarry = $NewCarryConnections->accept('127.0.0.2:54002');
         yield new Assertion(description: 'manager replacement never resurrects a tuple closed during timer release')
            ->expect(
               [
                  $beforeCarryRelease,
                  $afterCarryRelease,
                  $NewCarry instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  [false, 32, 1, ['127.0.0.1' => 1], 1, 1],
                  [true, false, 0, [], 0, 0, false],
                  true,
                  1,
                  ['127.0.0.2' => 1],
               ],
            )
            ->assert();
         $NewCarry?->close();
         unset(
            $NewCarry,
            $NewCarryConnections,
            $NewCarryServer,
            $CarryConnections,
            $CarryServer,
         );

         $AdmissionGuardServer = new class(Modes::Test) extends UDP_Server_CLI {
            // * Data
            public null|UDP_Server_CLI $Nested = null;
            public null|Connection $Direct = null;
            public string $error = '';
            /** @var resource|null */
            private $BoundSocket = null;
            private bool $armed = false;

            /** Hooked socket that attempts a manager replacement during accept(). */
            public $Socket {
               get {
                  if ($this->armed) {
                     $this->armed = false;
                     $this->Direct = new Connection(
                        $this->BoundSocket,
                        '127.0.0.19:42998',
                        30,
                     );
                     try {
                        $this->Nested = new UDP_Server_CLI(Modes::Test);
                     }
                     catch (Throwable $Throwable) {
                        $this->error = $Throwable->getMessage();
                     }
                  }

                  return $this->BoundSocket;
               }
               set ($Socket) {
                  $this->BoundSocket = $Socket;
               }
            }

            /** Arm one manager-construction attempt from the next getter read. */
            public function reset (): void
            {
               $this->armed = true;
            }
         };
         $AdmissionGuardServer->configure(new Configs(
            host: '127.0.0.1',
            port: 19988,
            workers: 1,
            maxConnections: 1,
            connectionIdleTimeout: 0,
         ));
         $AdmissionGuardServer->Socket = $Socket;
         $AdmissionGuardConnections = $AdmissionGuardServer->Connections;
         $AdmissionManager = new ReflectionProperty(
            Connections::class,
            'CurrentManager',
         );
         $admissionManagerBefore = $AdmissionManager->getValue();
         $AdmissionGuardServer->reset();
         $AdmissionGuardPeer = $AdmissionGuardConnections->accept(
            '127.0.0.9:42999'
         );
         yield new Assertion(description: 'socket getter cannot replace manager during admission')
            ->expect(
               [
                  $AdmissionGuardPeer instanceof Connection,
                  str_contains($AdmissionGuardServer->error, 'lifecycle mutation'),
                  $AdmissionGuardServer->Nested,
                  $AdmissionGuardServer->Direct instanceof Connection,
                  $AdmissionGuardServer->Direct instanceof Connection
                     && ConnectionAuthority::check($AdmissionGuardServer->Direct),
                  $AdmissionGuardServer->Direct?->timers,
                  count(TimerRegistry::snapshot()),
                  $AdmissionManager->getValue() === $admissionManagerBefore,
               ],
               Op::Identical,
               [true, true, null, true, false, [], 0, true],
            )
            ->assert();
         $AdmissionGuardPeer?->close();
         $AdmissionGuardServer->Direct?->close();
         unset(
            $AdmissionGuardPeer,
            $AdmissionGuardConnections,
            $AdmissionGuardServer,
         );
         gc_collect_cycles();
         Lease::drain();

         $ReentrantServer = new class(Modes::Test) extends UDP_Server_CLI {
            // * Data
            public null|Connection $Nested = null;
            public string $nestedPeer = '';
            /** @var resource|null */
            private $BoundSocket = null;
            private bool $reenter = false;

            /** Hooked socket fixture that performs one nested admission. */
            public $Socket {
               get {
                  if ($this->reenter) {
                     $this->reenter = false;
                     $this->Nested = $this->Connections->accept($this->nestedPeer);
                  }

                  return $this->BoundSocket;
               }
               set ($Socket) {
                  $this->BoundSocket = $Socket;
               }
            }

            /** Arm one nested admission for the given peer. */
            public function reset (string $peer): void
            {
               $this->Nested = null;
               $this->nestedPeer = $peer;
               $this->reenter = true;
            }
         };
         $ReentrantServer->configure(new Configs(
            host: '127.0.0.1',
            port: 19987,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
         ));
         $ReentrantServer->Socket = $Socket;
         $ReentrantConnections = $ReentrantServer->Connections;
         $ReentrantServer->reset('127.0.0.2:43002');
         $OuterDifferent = $ReentrantConnections->accept('127.0.0.1:43001');
         $NestedDifferent = $ReentrantServer->Nested;
         $differentState = [
            $OuterDifferent,
            $NestedDifferent instanceof Connection,
            count($Peers->getValue()),
         ];
         $NestedDifferent?->close();
         $ReentrantServer->Nested = null;
         unset($OuterDifferent, $NestedDifferent);
         gc_collect_cycles();
         Lease::drain();

         $ReentrantServer->reset('127.0.0.1:43003');
         $OuterSame = $ReentrantConnections->accept('127.0.0.1:43003');
         $NestedSame = $ReentrantServer->Nested;
         $IPProperty = new ReflectionProperty(Connections::class, 'IPConnections');
         yield new Assertion(description: 'socket getter reentry cannot overcommit admission')
            ->expect(
               [
                  $differentState,
                  $OuterSame === $NestedSame,
                  $OuterSame instanceof Connection,
                  count($Peers->getValue()),
                  $IPProperty->getValue(),
               ],
               Op::Identical,
               [
                  [null, true, 1],
                  true,
                  true,
                  1,
                  ['127.0.0.1' => 1],
               ],
            )
            ->assert();
         $OuterSame?->close();
         $ReentrantServer->Nested = null;
         unset(
            $OuterDifferent,
            $NestedDifferent,
            $OuterSame,
            $NestedSame,
            $ReentrantConnections,
            $ReentrantServer,
         );
         gc_collect_cycles();
         Lease::drain();

         $InvalidatingServer = new class(Modes::Test) extends UDP_Server_CLI {
            // * Data
            /** Prevent recursive invalidation only while the nested call runs. */
            public bool $inside = false;
            /** Number of adversarial nested calls still requested. */
            public int $remaining = 64;
            /** Admission key recursively resolved by the Socket getter. */
            public string $nestedPeer = '';
            /** Number of Socket getter invocations. */
            public int $calls = 0;
            /** Whether the fixture uses its one-frame recursion guard. */
            public bool $guard = true;
            /** @var resource|null */
            private $BoundSocket = null;

            /** Hooked socket fixture that invalidates every nested generation. */
            public $Socket {
               get {
                  $this->calls++;
                  if (
                     ($this->inside === false || $this->guard === false)
                     && $this->remaining > 0
                  ) {
                     $this->remaining--;
                     $this->inside = true;
                     $Nested = null;
                     try {
                        $Nested = $this->Connections->accept($this->nestedPeer);
                     }
                     finally {
                        $this->inside = false;
                     }
                     if ($Nested instanceof Connection) {
                        $Nested->status = Connections::STATUS_CLOSED;
                     }
                  }

                  return $this->BoundSocket;
               }
               set ($Socket) {
                  $this->BoundSocket = $Socket;
               }
            }
         };
         $InvalidatingServer->configure(new Configs(
            host: '127.0.0.1',
            port: 19986,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
         ));
         $InvalidatingServer->Socket = $Socket;
         $InvalidatingConnections = $InvalidatingServer->Connections;
         $InvalidatingServer->nestedPeer = '127.0.0.1:44001';
         $Invalidated = $InvalidatingConnections->accept(
            $InvalidatingServer->nestedPeer
         );
         $guardedState = [
            $Invalidated,
            $InvalidatingServer->calls,
            $InvalidatingServer->remaining,
            $InvalidatingConnections->connections,
            count($Peers->getValue()),
            $IPConnections->getValue(),
         ];
         $guardedClosed = $InvalidatingConnections->close(
            $InvalidatingServer->nestedPeer
         );
         $guardedCleanup = [
            $guardedClosed,
            count($Peers->getValue()),
            $IPConnections->getValue(),
         ];

         $InvalidatingServer->guard = false;
         $InvalidatingServer->inside = false;
         $InvalidatingServer->remaining = 64;
         $InvalidatingServer->calls = 0;
         $InvalidatingServer->nestedPeer = '127.0.0.1:44002';
         $Recursive = $InvalidatingConnections->accept(
            $InvalidatingServer->nestedPeer
         );
         $recursiveState = [
            $Recursive,
            $InvalidatingServer->calls,
            $InvalidatingServer->remaining,
            $InvalidatingConnections->connections,
            count($Peers->getValue()),
            $IPConnections->getValue(),
         ];
         $recursiveClosed = $InvalidatingConnections->close(
            $InvalidatingServer->nestedPeer
         );
         yield new Assertion(description: 'socket getter recursion shares one finite admission budget')
            ->expect(
               [
                  $guardedState,
                  $guardedCleanup,
                  $recursiveState,
                  $recursiveClosed,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  [null, 6, 61, 3, 1, ['127.0.0.1' => 1]],
                  [true, 0, []],
                  [null, 8, 56, 4, 1, ['127.0.0.1' => 1]],
                  true,
                  0,
                  [],
               ],
            )
            ->assert();
         unset(
            $Invalidated,
            $Recursive,
            $InvalidatingConnections,
            $InvalidatingServer,
         );

         $CloneServer = new class(Modes::Test) extends UDP_Server_CLI {
            // * Data
            /** Number of manager-clone attempts made by the Socket getter. */
            public int $cloneAttempts = 0;
            /** Number of clone attempts rejected by manager authority. */
            public int $cloneFailures = 0;
            /** Number of peers allocated through a duplicated manager. */
            public int $nestedAllocations = 0;
            /** @var resource|null */
            private $BoundSocket = null;

            /** Hooked socket fixture that recursively spends cloned budgets. */
            public $Socket {
               get {
                  if ($this->cloneAttempts < 32) {
                     $this->cloneAttempts++;
                     try {
                        // @phpstan-ignore-next-line Deliberately verifies runtime clone denial.
                        $Clone = clone $this->Connections;
                        $port = 45_000 + $this->cloneAttempts;
                        $Nested = $Clone->accept("127.0.0.1:{$port}");
                        if ($Nested instanceof Connection) {
                           $this->nestedAllocations++;
                        }
                     }
                     catch (Throwable) {
                        $this->cloneFailures++;
                     }
                  }

                  return $this->BoundSocket;
               }
               set ($Socket) {
                  $this->BoundSocket = $Socket;
               }
            }
         };
         $CloneServer->configure(new Configs(
            host: '127.0.0.1',
            port: 19985,
            workers: 1,
            maxConnections: 64,
            maxConnectionsPerIP: 64,
            connectionIdleTimeout: 0,
         ));
         $CloneServer->Socket = $Socket;
         $CloneConnections = $CloneServer->Connections;
         $ClonePeer = $CloneConnections->accept('127.0.0.1:44999');
         $AdmissionDepth = new ReflectionProperty(Connections::class, 'admissionDepth');
         $AdmissionBudget = new ReflectionProperty(Connections::class, 'admissionBudget');
         $cloneState = [
            $ClonePeer instanceof Connection,
            $CloneServer->cloneAttempts,
            $CloneServer->cloneFailures,
            $CloneServer->nestedAllocations,
            $AdmissionDepth->getValue($CloneConnections),
            $AdmissionBudget->getValue($CloneConnections),
            $CloneConnections->connections,
            count($Peers->getValue()),
            $IPConnections->getValue(),
         ];
         $CloneWeak = $ClonePeer instanceof Connection
            ? WeakReference::create($ClonePeer)
            : null;
         $cloneClosed = $ClonePeer?->close();
         $cloneRetained = [
            count($Peers->getValue()),
            $IPConnections->getValue(),
         ];
         unset($ClonePeer);
         gc_collect_cycles();
         Lease::drain();
         yield new Assertion(description: 'manager clones cannot reset the shared admission budget')
            ->expect(
               [
                  $cloneState,
                  $cloneClosed,
                  $cloneRetained,
                  $CloneWeak?->get(),
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  [true, 1, 1, 0, 0, 0, 1, 1, ['127.0.0.1' => 1]],
                  true,
                  [1, ['127.0.0.1' => 1]],
                  null,
                  0,
                  [],
               ],
            )
            ->assert();
         unset($CloneConnections, $CloneServer);

         $Partial = (new ReflectionClass(Connections::class))
            ->newInstanceWithoutConstructor();
         $partialThrown = '';
         $partialAccepted = null;
         $partialClosed = null;
         try {
            $partialAccepted = $Partial->accept('127.0.0.1:45990');
            $partialClosed = $Partial->close('127.0.0.1:45990');
         }
         catch (Throwable $Throwable) {
            $partialThrown = $Throwable->getMessage();
         }
         yield new Assertion(description: 'partial manager objects deny authority without throwing')
            ->expect(
               [$partialThrown, $partialAccepted, $partialClosed],
               Op::Identical,
               ['', null, false],
            )
            ->assert();
         unset($Partial);

         $RollbackServer = new UDP_Server_CLI(Modes::Test);
         $RollbackServer->configure(new Configs(
            host: '127.0.0.1',
            port: 19984,
            workers: 1,
            maxConnections: 1,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
         ));
         $SocketProperty->setValue($RollbackServer, $Socket);
         $RollbackConnections = $RollbackServer->Connections;
         $CurrentManager = new ReflectionProperty(
            Connections::class, 'CurrentManager'
         );
         $CurrentConnections = new ReflectionProperty(
            Connections::class, 'CurrentConnections'
         );
         $RollbackManager = $CurrentManager->getValue();
         $RollbackReference = $CurrentConnections->getValue();

         $generalPeer = '127.0.0.1:45989';
         $GeneralPeer = $RollbackConnections->accept($generalPeer);
         if ($GeneralPeer instanceof Connection === false) {
            throw new RuntimeException('Could not admit general-rollback peer.');
         }
         $GeneralWeak = WeakReference::create($GeneralPeer);
         $OriginalPeers = $Peers->getValue();
         $MalformedPeers = $OriginalPeers;
         $MalformedPeers[$generalPeer][1] = new stdClass;
         $Peers->setValue(null, $MalformedPeers);
         $generalThrown = '';
         try {
            new UDP_Server_CLI(Modes::Test);
         }
         catch (Throwable $Throwable) {
            $generalThrown = $Throwable->getMessage();
         }
         finally {
            $Peers->setValue(null, $OriginalPeers);
         }
         $GeneralRecovered = $RollbackConnections->accept($generalPeer);
         $GeneralReference = $CurrentConnections->getValue();
         yield new Assertion(description: 'failed manager construction restores prior authority')
            ->expect(
               [
                  $generalThrown !== '',
                  $CurrentManager->getValue() === $RollbackManager,
                  $GeneralReference === $RollbackReference,
                  $GeneralReference instanceof WeakReference
                     && $GeneralReference->get() === $RollbackConnections,
                  $GeneralRecovered === $GeneralPeer,
                  $GeneralWeak->get() === $GeneralPeer,
               ],
               Op::Identical,
               [true, true, true, true, true, true],
            )
            ->assert();
         $GeneralPeer->close();
         unset($GeneralRecovered, $GeneralPeer, $MalformedPeers, $OriginalPeers);
         gc_collect_cycles();
         Lease::drain();

         H7ClearMirrorConnection::$remaining = 8;
         H7ClearMirrorConnection::$scalar = true;
         $clearPeer = '127.0.0.1:45991';
         $ClearMirror = new H7ClearMirrorConnection($Socket, $clearPeer, 0);
         unset($ClearMirror->Connection); // @phpstan-ignore unset.possiblyHookedProperty
         Connections::$Connections[$clearPeer] = $ClearMirror;
         unset($ClearMirror);
         $clearThrown = '';
         try {
            new UDP_Server_CLI(Modes::Test);
         }
         catch (Throwable $Throwable) {
            $clearThrown = $Throwable->getMessage();
         }
         $AfterClear = $RollbackConnections->accept('127.0.0.2:45992');
         $RestoredReference = $CurrentConnections->getValue();
         yield new Assertion(description: 'mirror clear exhaustion restores exact prior manager authority')
            ->expect(
               [
                  str_contains($clearThrown, 'bounded budget'),
                  H7ClearMirrorConnection::$remaining,
                  Connections::$Connections[$clearPeer] ?? null,
                  $CurrentManager->getValue() === $RollbackManager,
                  $RestoredReference === $RollbackReference,
                  $RestoredReference instanceof WeakReference
                     && $RestoredReference->get() === $RollbackConnections,
                  $AfterClear instanceof Connection,
                  count($Peers->getValue()),
                  $IPConnections->getValue(),
               ],
               Op::Identical,
               [
                  true,
                  0,
                  'terminal scalar',
                  true,
                  true,
                  true,
                  true,
                  1,
                  ['127.0.0.2' => 1],
               ],
            )
            ->assert();
         $AfterClear?->close();
         H7ClearMirrorConnection::$remaining = 0;
         H7ClearMirrorConnection::$scalar = false;
         unset(Connections::$Connections[$clearPeer]);
         unset($AfterClear, $RollbackConnections, $RollbackServer);

         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new Configs(
            host: '127.0.0.1',
            port: 19994,
            workers: 1,
            maxConnections: 2,
            maxConnectionsPerIP: 1,
            connectionIdleTimeout: 0,
         ));
         $SocketProperty->setValue($Server, $Socket);
         $Connections = $Server->Connections;
         $A = $Connections->accept('127.0.0.1:41001');
         $PerIPRejected = $Connections->accept('127.0.0.1:41002');
         $B = $Connections->accept('127.0.0.2:41002');
         $GlobalRejected = $Connections->accept('127.0.0.3:41003');

         if ($A instanceof Connection) {
            $A->used = time() - 100;
         }
         $idleDisabled = [
            $A?->expire(0),
            $A?->status === Connections::STATUS_ESTABLISHED,
         ];

         yield new Assertion(description: 'admission enforces exact caps and zero idle opt-out')
            ->expect(
               [
                  $A instanceof Connection,
                  $B instanceof Connection,
                  $PerIPRejected,
                  $GlobalRejected,
                  $idleDisabled,
               ],
               Op::Identical,
               [true, true, null, null, [false, true]],
            )
            ->assert();

         $AWeak = $A instanceof Connection ? WeakReference::create($A) : null;
         $BWeak = $B instanceof Connection ? WeakReference::create($B) : null;
         $A?->close();
         $B?->close();
         unset($A, $B, $PerIPRejected, $GlobalRejected);
         gc_collect_cycles();
         Lease::drain();
         $Unlimited = new UDP_Server_CLI(Modes::Test);
         $Unlimited->configure(new Configs(
            host: '127.0.0.1',
            port: 19983,
            workers: 1,
            maxConnections: 0,
            maxConnectionsPerIP: 0,
            connectionIdleTimeout: 0,
         ));
         $UnlimitedSocket = new ReflectionProperty($Unlimited, 'Socket');
         $UnlimitedSocket->setValue($Unlimited, $Socket);
         $UnlimitedConnections = $Unlimited->Connections;
         $U1 = $UnlimitedConnections->accept('127.0.0.1:42001');
         $U2 = $UnlimitedConnections->accept('127.0.0.1:42002');
         $U3 = $UnlimitedConnections->accept('127.0.0.1:42003');
         $IPProperty = new ReflectionProperty(Connections::class, 'IPConnections');
         yield new Assertion(description: 'zero explicitly opts out of peer ceilings')
            ->expect(
               [
                  $AWeak?->get(),
                  $BWeak?->get(),
                  $U1 instanceof Connection,
                  $U2 instanceof Connection,
                  $U3 instanceof Connection,
                  count(Connections::$Connections),
                  $IPProperty->getValue(),
               ],
               Op::Identical,
               [null, null, true, true, true, 3, ['127.0.0.1' => 3]],
            )
            ->assert();
         $U1?->close();
         $U2?->close();
         $U3?->close();
         unset($U1, $U2, $U3);
         gc_collect_cycles();
         Lease::drain();
      }
      finally {
         H7StartClaimApplication::reset();
         H7StartStopApplication::reset();
         H7CarryChain::$armed = false;
         H7CarryChain::$remaining = 0;
         H7NestedManagerMirror::$Nested = null;
         H7NestedManagerMirror::$error = '';
         H7NestedManagerMirror::$SharedSocket = null;
         H7ClearMirrorConnection::$remaining = 0;
         H7ClearMirrorConnection::$scalar = false;
         foreach (array_values(Connections::$Connections) as $Connection) {
            if ($Connection instanceof Connection) {
               $Connection->close();
            }
         }
         Connections::$Connections = [];
         unset($Connection);
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
         $Configuration = new ReflectionProperty(Connections::class, 'Configuration');
         $Starting = new ReflectionProperty(Connections::class, 'Starting');
         $Committing = new ReflectionProperty(Connections::class, 'committing');
         $clean = $IPProperty->getValue() === []
            && $PeersProperty->getValue() === []
            && $PendingProperty->getValue() === []
            && $TasksProperty->getValue() === []
            && $ManagerReset->getValue() === 0
            && $DirectReset->getValue() === 0
            && $ResetObservers->getValue() === []
            && $Configuration->getValue() === null
            && $Starting->getValue() === null
            && $Committing->getValue() === false
            && $remainingAlarm === 0;
         Connections::$blacklist = [];

         if (is_resource($Socket)) {
            fclose($Socket);
         }
         pcntl_signal(SIGALRM, $PreviousAlarm === false ? SIG_DFL : $PreviousAlarm);
         if ($clean === false) {
            throw new RuntimeException('UDP configuration test teardown left process state.');
         }
      }
   })
);
