<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces;


use const LOCK_EX;
use const LOCK_NB;
use const PHP_SAPI;
use const SIG_IGN;
use const SIGALRM;
use const SIGCONT;
use const SIGHUP;
use const SIGINT;
use const SIGIO;
use const SIGIOT;
use const SIGPIPE;
use const SIGQUIT;
use const SIGTERM;
use const SIGTSTP;
use const SIGUSR1;
use const SIGUSR2;
use const STREAM_CLIENT_CONNECT;
use const WUNTRACED;
use function count;
use function defined;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_wait;
use function posix_getpid;
use function register_shutdown_function;
use function stream_context_create;
use function stream_socket_client;
use function time;
use BackedEnum;
use Closure;
use InvalidArgumentException;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Process;
use Bootgly\API\Projects;
use Bootgly\WPI\Event;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Commands;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections;


class UDP_Client_CLI
{
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


   /** @var resource */
   public $Socket;

   // ! Event
   public static Events & Loops $Event;

   // ! Process
   protected Process $Process;
   // ! Console
   protected Commands $Commands;


   // * Config
   #protected string $resource;
   protected null|string $host;
   protected null|int $port;
   protected int $workers;
   // # Mode
   protected int $mode;
   public const int MODE_DEFAULT = 1;
   public const int MODE_MONITOR = 2;
   public const int MODE_TEST = 3;

   // * Data
   /** @var array<string,true> */
   protected array $Events = [];
   // ! On
   // on Worker
   public static null|Closure $onWorkerStarted = null;
   // on Connection
   public static null|Closure $onClientConnect = null;
   public static null|Closure $onClientDisconnect = null;
   // on Datagram
   public static null|Closure $onDatagramRead = null;
   public static null|Closure $onDatagramWrite = null;

   // * Metadata
   // # Error
   /** @var array<int|string|null> */
   public array $error = [];
   // # State
   protected static int $started = 0;
   // # Status
   protected static int $status = 0;
   protected const int STATUS_BOOTING = 1;
   protected const int STATUS_CONFIGURING = 2;
   protected const int STATUS_STARTING = 4;
   protected const int STATUS_RUNNING = 8;
   protected const int STATUS_PAUSED = 16;
   protected const int STATUS_STOPING = 32;


   // ! Connection(s)
   protected Connections $Connections;


   public function __construct (int $mode = self::MODE_DEFAULT)
   {
      if (PHP_SAPI !== 'cli') {
         return;
      }

      // * Config
      // # Mode
      $this->mode = $mode;

      // * Data
      // ...

      // * Metadata
      // # Error
      $this->error = [];
      // # State
      static::$started = time();
      // # Status
      self::$status = self::STATUS_BOOTING;


      // @ Configure Debugging Vars
      Vars::$debug = true;
      Vars::$print = true;
      Vars::$exit = false;

      // @ Instance Bootables
      // ! Connection(s)
      $this->Connections = new Connections($this);
      // ! Web\@\Events
      static::$Event = new Select($this->Connections); // @phpstan-ignore-line

      // @ Skip Process/Commands infrastructure in test mode
      if ($this->mode === self::MODE_TEST) {
         return;
      }

      // ! @\Process
      $processId = defined('BOOTGLY_PROJECT') ? Projects::encode(BOOTGLY_PROJECT->folder) : static::class;
      // ? Clients have no port to qualify instances: qualify by process PID (multi-instance)
      $Process = $this->Process = new Process(id: $processId, instance: (string) posix_getpid());
      $Process->State->lock(LOCK_EX | LOCK_NB);
      $Process->Signals->handler = fn (int $signal) => $this->handle($signal);
      // ! @\Commands
      $this->Commands = new Commands($this);

      // @ Register shutdown function to avoid orphaned children
      register_shutdown_function(function () use ($Process) {
         $Process->Signals->send(SIGINT);
      });
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         case 'Process':
            return $this->Process;

         // * Config
         case 'host':
            return $this->host;
         case 'port':
            return $this->port;

         case 'Connections':
            return $this->Connections;
      }

      return null;
   }

   public function configure (string $host, int $port, int $workers = 0): self
   {
      self::$status = self::STATUS_CONFIGURING;

      // TODO validate configuration user data inputs

      $this->host = $host;
      $this->port = $port;
      $this->workers = $workers;

      return $this;
   }
   /**
    * Register an event handler for the UDP Client.
    *
    * @param Event&BackedEnum $Event The event to listen to.
    * @param Closure $Callback The event callback.
    *
    * @return self
    */
   public function on (
      Event & BackedEnum $Event,
      Closure $Callback
   ): self
   {
      if ($Event instanceof UDP_Client_CLI\Events === false) {
         throw new InvalidArgumentException('Invalid UDP Client event.');
      }

      if (isset($this->Events[$Event->value])) {
         throw new InvalidArgumentException("The event '{$Event->value}' is already registered.");
      }

      $this->Events[$Event->value] = true;

      match ($Event) {
         UDP_Client_CLI\Events::WorkerStarted => self::$onWorkerStarted = $Callback,
         UDP_Client_CLI\Events::ClientConnect => self::$onClientConnect = $Callback,
         UDP_Client_CLI\Events::ClientDisconnect => self::$onClientDisconnect = $Callback,
         UDP_Client_CLI\Events::DatagramRead => self::$onDatagramRead = $Callback,
         UDP_Client_CLI\Events::DatagramWrite => self::$onDatagramWrite = $Callback,
      };

      return $this;
   }
   public function handle (int $signal): void
   {
      switch ($signal) {
         // * Timer
         case SIGALRM:
            Timer::tick();
            break;

         // * Custom command
         case SIGUSR1:  // 10
            break;

         // ! Client
         // @ stop()
         case SIGHUP:  // 1
         case SIGINT:  // 2 (CTRL + C)
         case SIGQUIT: // 3
         case SIGTERM: // 15
            $this->stop();
            break;

         case SIGTSTP: // 20 (CTRL + Z)
            break;
         case SIGCONT: // 18
            break;
         case SIGUSR2: // 12
            break;

         case SIGIOT:  // 6
            break;
         case SIGIO:   // 29
            break;
      }
   }
   public function start (): bool
   {
      self::$status = self::STATUS_STARTING;

      if ($this->workers) {
         $this->Logger->log(info: 'Starting Client... ');

         // ! Process
         // ? Signals
         // @ Install process signals
         $this->Process->Signals->install([
            SIGALRM,  // Timer
            SIGUSR1,  // Custom command
            SIGHUP,   // stop
            SIGINT,   // stop (CTRL + C)
            SIGQUIT,  // stop
            SIGTERM,  // stop
            SIGTSTP,  // pause (CTRL + Z)
            SIGCONT,  // resume
            SIGUSR2,  // reload
            SIGIOT,   // info
            SIGIO,    // stats
         ]);
         pcntl_signal(SIGPIPE, SIG_IGN, false);

         // @ Fork process workers...
         $this->Process->fork($this->workers, instance: function (Process $Process, int $index): void {
            $Process->title = 'Bootgly_UDP_Client_CLI: child process (Worker #' . Process::$index . ')';

            Display::show(Display::MESSAGE, Display::TIMESTAMP, Display::CHANNEL, Display::SEVERITY);

            // @ Call On Worker start
            if (self::$onWorkerStarted) {
               (self::$onWorkerStarted)($this);
            }

            $this->stop();
         });

         // @ Set master process title
         $this->Process->title = 'Bootgly_UDP_Client_CLI: master process';

         // @ Save full process state
         $this->Process->State->save([
            'master'  => Process::$master,
            'workers' => $this->Process->Children->PIDs,
            'host'    => $this->host ?? '0.0.0.0',
            'port'    => $this->port ?? 0,
            'started' => time(),
            'type'    => 'WPI-Client'
         ]);
      }

      $this->Logger->log(debug: '@\;@\;');

      // ... Continue to master process:
      switch ($this->mode) {
         case self::MODE_DEFAULT:
         case self::MODE_TEST:
            if (self::$onWorkerStarted) {
               (self::$onWorkerStarted)($this);
            }
            else {
               if ( $this->connect() ) {
                  self::$Event->loop();
               }
            }
            break;
         case self::MODE_MONITOR:
            $this->monitor();
            break;
      }

      return true;
   }

   private function monitor (): void
   {
      self::$status = self::STATUS_RUNNING;

      if (Display::$segments !== Display::NONE) {
         Display::show(Display::MESSAGE);
      }

      $this->Logger->log(info: 'Entering in Monitor mode...@\;');
      $this->Logger->log(debug: '>_ Type `CTRL + C` to stop the Client.@\;');

      // @ Loop
      while ($this->mode === self::MODE_MONITOR) {
         // @ Calls signal handlers for pending signals
         pcntl_signal_dispatch();

         // @ Suspends execution of the current process until a child has exited, or until a signal is delivered
         $pid = pcntl_wait($status, WUNTRACED);

         // @ Calls signal handlers for pending signals again
         pcntl_signal_dispatch();

         // If child is running?
         if ($pid === 0) {
            // ...
         }
         else if ($pid > 0) { // If a child has already exited?
            $this->Logger->log(error: '@\;Process child exited!@\;');
            $this->Process->Signals->send(SIGINT);
            break;
         }
         else if ($pid === -1) { // If error ignore
            // ...
         }
      }
   }

   /**
    * Open a UDP client socket and associate it with the remote peer.
    *
    * For UDP, `stream_socket_client('udp://...')` does not perform a
    * handshake — it simply binds the local socket and remembers the peer
    * address for subsequent sendto/recvfrom calls. There is no ASYNC flag
    * path and no "trying to connect in the future" retry state.
    *
    * @return resource|false
    */
   public function connect ()
   {
      try {
         $contextOptions = [
            'socket' => [
               // Used to specify the IP address (either IPv4 or IPv6) and/or the port number
               // that PHP will use to access the network. The syntax is ip:port for IPv4 addresses,
               // and [ip]:port for IPv6 addresses. Setting the IP or the port to 0 will
               // let the system choose the IP and/or port.
               #'bindto' => $this->host . ':' . (55000 + $index)
            ]
         ];

         $context = stream_context_create($contextOptions);

         $Socket = @stream_socket_client(
            "udp://{$this->host}:{$this->port}",
            $this->error['code'],
            $this->error['message'],
            timeout: 0,
            flags: STREAM_CLIENT_CONNECT,
            context: $context
         );
      }
      catch (Throwable) {
         $Socket = false;
      }

      if ($Socket === false) {
         $this->Logger->log(warning: 'Unable to bind! Socket not created.@\\;');

         return false;
      }

      $this->Socket = $Socket;

      $this->Connections->connect();

      return $Socket;
   }

   public function stop (): void
   {
      self::$status = self::STATUS_STOPING;

      Display::show(Display::MESSAGE);

      $children = (string) count($this->Process->Children->PIDs);
      match ($this->Process->level) {
         'master' => $this->Logger->log(critical: "{$children} worker(s) stopped!@\\;"),
         'child' => null,
         default => null
      };

      exit(0);
   }
}
