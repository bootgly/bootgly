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


use const BOOTGLY_ENVIRONMENT;
use const PHP_BINARY;
use const PHP_SAPI;
use const SIG_DFL;
use const SIG_IGN;
use const SIGALRM;
use const SIGCHLD;
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
use const SIGWINCH;
use const SO_KEEPALIVE;
use const SOL_SOCKET;
use const SOL_TCP;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const TCP_NODELAY;
use const WNOHANG;
use const WUNTRACED;
use function array_merge;
use function array_slice;
use function chdir;
use function count;
use function defined;
use function exec;
use function explode;
use function fclose;
use function file;
use function function_exists;
use function get_included_files;
use function getcwd;
use function getenv;
use function is_file;
use function is_numeric;
use function method_exists;
use function pcntl_exec;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_wait;
use function pcntl_waitpid;
use function posix_getgrnam;
use function posix_getpid;
use function posix_getpwnam;
use function posix_getuid;
use function posix_initgroups;
use function posix_setgid;
use function posix_setsid;
use function posix_setuid;
use function register_shutdown_function;
use function restore_error_handler;
use function rtrim;
use function socket_import_stream;
use function socket_set_option;
use function str_contains;
use function stream_context_create;
use function stream_socket_enable_crypto;
use function stream_socket_server;
use function time;
use function usleep;
use BackedEnum;
use Closure;
use InvalidArgumentException;
use Throwable;

use const Bootgly\CLI;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Debugging\Shutdown;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\IO\IPC\Pipe as IPCPipe;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers\Pipe as PipeHandler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Process;
use Bootgly\ACI\Process\Events as Worker;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\API\Environment;
use Bootgly\API\Environments;
use Bootgly\API\Projects;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\UI\Components\Logs as LogsViewer;
use Bootgly\WPI\Endpoints\Servers;
use Bootgly\WPI\Endpoints\Servers\Decoder;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Event;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Commands;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


class TCP_Server_CLI implements Servers
{
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


   // !
   /** @var resource */
   protected $Socket;

   public static Events & Loops & Scheduler $Event;

   protected Commands $Commands;
   protected Process $Process;

   // * Config
   protected null|string $domain;
   protected null|string $host;
   protected null|int $port;
   protected int $workers;
   /** @var array<string> */
   protected null|array $secure; // Secure SSL/TLS Stream Context
   protected null|string $user = null;
   protected null|string $group = null;
   protected bool $demoted = false;
   // # Mode
   public Modes $Mode;
   // # Verbosity

   // * Data
   // # SAPI
   public static null|string $Application = null;
   public static null|Decoder $Decoder = null;
   public static null|Encoder $Encoder = null;
   // # Application protocols (TLS-ALPN)
   //   ALPN token → per-connection installer, invoked by
   //   `Connection::handshake()` with the negotiated Connection. Registered
   //   by nodes (e.g. HTTP_Server_CLI maps 'h2' to its HTTP/2 decoder) —
   //   the transport layer never names higher-layer entities.
   /** @var array<string, Closure> */
   public static array $Protocols = [];
   // # Backpressure (async write state machine)
   //   Maximum bytes a single connection may keep in its in-memory write
   //   backlog while the socket is unwritable. Past this threshold the
   //   connection is closed to avoid memory exhaustion.
   public static int $maxPendingBytes = 4194304; // 4 MiB
   //   Wall-clock budget (seconds) a deferred write may remain stalled
   //   before the connection is closed deterministically. Replaces the
   //   previous synchronous `stream_select(..., 200_000)` retry loop.
   public static int $maxWriteWallTime = 30;
   // # Connection-exhaustion caps (audit F-2)
   //   Maximum simultaneously-established connections per worker. New
   //   connections accepted past this ceiling are immediately shed (accepted
   //   then closed) to bound FD/memory under a connection-flood DoS. 0 =
   //   unlimited. Evaluated once per accept — never on the per-request hot
   //   path — so it does not affect throughput on established connections.
   public static int $maxConnections = 10000;
   //   Maximum simultaneously-established connections from a single peer IP.
   //   Opt-in (0 = unlimited) because reverse-proxy deployments collapse every
   //   client onto one source IP; enable it only when the peer IP is the real
   //   client. When > 0, accepts past it are shed.
   public static int $maxConnectionsPerIP = 0;
   // # Reload — graceful drain budget (seconds) each worker gets to finish its
   //   in-flight connections before the master force-kills it during a reload.
   public static int $drainTimeout = 30;
   /** @var array<string,true> */
   protected array $Events = [];
   // # Hooks — server lifecycle callbacks, set by the node `on()` overrides.
   protected null|Closure $onServerStarted = null;
   protected null|Closure $onServerStopped = null;

   // * Metadata
   // # State
   protected int $started = 0;
   protected bool $daemonized = false;
   // # Reload — the launch command captured at start(), replayed verbatim by
   //   reload() via pcntl_exec so the master re-execs into a fresh PHP image
   //   (reloading all code) while keeping its PID. Absolute script path + saved
   //   cwd survive a daemon chdir.
   protected static string $binary = '';
   /** @var array<int,string> */
   protected static array $argv = [];
   protected static string $directory = '';
   // # Socket
   protected null|string $socket;
   /** @var array<array<bool|int|string>|string> */
   public static array $context;
   // # Process
   //   Process-title prefix; nodes override it (e.g. `Bootgly_HTTP_Server`) so
   //   the master and workers are identifiable in `ps`.
   protected string $process = 'Bootgly_TCP_Server_CLI';
   // # Status
   protected Status $Status = Status::Booting;

   // /
   protected Connections $Connections;

   // @ Monitor live-log viewer pipe (workers → master), opened pre-fork.
   protected null|IPCPipe $LogPipe = null;


   public function __construct (Modes $Mode = Modes::Monitor)
   {
      if (PHP_SAPI !== 'cli') {
         return;
      }


      // * Config
      // $domain
      $this->socket = 'tcp://';
      $this->host = null;
      $this->port = null;
      // $workers
      $this->secure = null;
      // # Mode
      $this->Mode = $Mode;

      // * Data
      // # SAPI
      // $Application
      if (__CLASS__ !== static::class) {
         self::$Application = static::class;
      }
      // $Decoder
      // $Encoder

      // * Metadata
      // # State
      $this->started = time();
      // # Status
      $this->Status = Status::Booting;


      // @
      // @ Configure Logger
      $this->Logger = new Logger(channel: 'TCP.Server.CLI');
      // @ Configure Debugging Vars
      Vars::$debug = true;
      Vars::$print = true;
      Vars::$exit = false;

      // @ Instance Bootables
      // ! Connection(s)
      $this->Connections = new Connections($this);

      // ! WPI\Events
      static::$Event = new Select($this->Connections); // @phpstan-ignore-line

      // ! @\Process
      $processId = defined('BOOTGLY_PROJECT') ? Projects::encode(BOOTGLY_PROJECT->folder) : static::class;
      $instance = $this->Mode === Modes::Test ? 'test' : null;
      $Process = $this->Process = new Process(id: $processId, instance: $instance);
      $Process->State->lock();
      $Process->Signals->handler = fn (int $signal) => $this->handle($signal);
      // ! @\Commands
      $this->Commands = new Commands($this);

      CLI->Commands->autoload(__CLASS__, Context: $this, Script: $this);

      // @ Register shutdown function to avoid orphaned children
      register_shutdown_function(function () use ($Process) {
         // ? Skip if this process daemonized (workers belong to daemon child)
         if ($this->daemonized) {
            return;
         }

         // ? Only the master should initiate shutdown signaling.
         //   Children must not signal back to the master on exit — otherwise
         //   a child exiting during teardown would queue SIGINT on the master
         //   and kill a subsequent test suite running in the same PHP process.
         if ($Process->level !== 'master') {
            return;
         }

         Shutdown::debug();

         $Process->Signals->send(SIGINT, master: false, children: true);
      });
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         case 'Socket':
            return $this->Socket;

         case 'Commands':
            return $this->Commands;
         case 'Connections':
            return $this->Connections;
         case 'Process':
            return $this->Process;

         case 'host':
            return $this->host;
         case 'port':
            return $this->port;
         case 'socket':
            return $this->socket;
         case 'secure':
            return $this->secure;
         case 'Status':
            return $this->Status;

         case '@test init':
            SAPI::$Environment = Environments::Test;

            if (self::$Application) {
               self::$Application::boot(Environments::Test);
            }

            return true;
         case '@test':
            if ($this->Process->level === 'master' && self::$Application && method_exists(self::$Application, 'test')) {
               self::$Application::test($this);
            }

            return true;
         case '@test end':
            $Environment = Environments::fetch(BOOTGLY_ENVIRONMENT);
            SAPI::$Environment = $Environment;

            if (self::$Application) {
               self::$Application::boot($Environment);
               return true;
            }

            SAPI::boot(reset: true);

            return true;
         case '@status':
            // @ Set log display none
            $display = Display::$segments;
            Display::show(Display::MESSAGE);

            CLI->Commands->find('status', From: $this)?->run();

            // @ Restore log display
            Display::show($display);
            return true;
      }

      return null;
   }
   /**
    * Configure the TCP Server.
    * 
    * @param string $host Domain name or IP address
    * @param int $port Port number
    * @param int $workers Number of workers
   * @param array<string>|null $secure Secure SSL/TLS Stream Context
    * @param string|null $user User to drop privileges to after socket binding
    * @param string|null $group Group to drop privileges to after socket binding
    * 
    * @return self
    */
   public function configure (
      string $host,
      int $port,
      int $workers,
      null|array $secure = null,
      null|string $user = null,
      null|string $group = null
   ): self
   {
      $this->Status = Status::Configuring;

      // TODO validate configuration user data inputs

      #$this->domain = $domain;

      $this->host = $host;
      $this->port = $port;
      $this->workers = $workers;

      $this->secure = $secure;

      $this->user = $user;
      $this->group = $group;

      return $this;
   }
   /**
    * Register an event handler for the TCP Server.
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
      if ($Event instanceof TCP_Server_CLI\Events === false) {
         throw new InvalidArgumentException('Invalid TCP Server event.');
      }

      if (isset($this->Events[$Event->value])) {
         throw new InvalidArgumentException("The event '{$Event->value}' is already registered.");
      }

      $this->Events[$Event->value] = true;

      // @
      // on Data
      SAPI::$Handler = $Callback;

      // :
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
            $lines = @file($this->Process->State->commandFile);

            if ($lines) {
               $line = $lines[count($lines) - 1];

               [$command, $context] = explode(':', rtrim($line));

               // @ Prepend command
               $command = '@' . $command;

               // @ Match context
               match ($context) { // @phpstan-ignore-line
                  'Connections' => $this->Connections->{$command},
                  default => $this->{$command}
               };
            }

            break;

         // ! Server
         // @ stop() — fast shutdown (in-flight connections dropped immediately)
         case SIGHUP:  // 1
         case SIGINT:  // 2 (CTRL + C)
         case SIGTERM: // 15
            $this->stop();
            break;
         // @ drain() — graceful worker shutdown: stop accepting, finish in-flight
         //   connections, then exit. The master has no in-flight work of its own,
         //   so it falls back to a fast stop(); reload() drives workers here.
         case SIGQUIT: // 3
            if ($this->Process->level === 'child') {
               $this->drain();
            }
            else {
               $this->stop();
            }
            break;
         // @ pause()
         case SIGTSTP: // 20 (CTRL + Z)
            match ($this->Mode) {
               Modes::Monitor => $this->Mode = Modes::Interactive,
               Modes::Interactive => $this->pause(),
               default => null
            };
            break;
         // @ resume()
         case SIGCONT: // 18
            $this->resume();
            break;
         // @ reload() — master-only: graceful re-exec (drain workers, then replace
         //   the master image with a fresh one so ALL code reloads, same PID). A
         //   worker that somehow receives it does nothing; the master drives the
         //   worker drain via SIGQUIT from inside reload().
         case SIGUSR2: // 12
            if ($this->Process->level === 'master') {
               $this->reload();
            }
            break;

         // @ recover()
         case SIGCHLD: // 17
            if ($this->Process->level === 'master') {
               while ($dead = $this->Process->recover()) {
                  [$deadIndex, $deadPID] = $dead;

                  $this->Logger->log(warning: "Worker #{$deadIndex} (PID: {$deadPID}) crashed, reforking...@.;");

                  $newPID = pcntl_fork();

                  // # Child process (new worker)
                  if ($newPID === 0) {
                     $this->Process->Children->push($this->Process->id, $deadIndex);
                     Process::$index = $deadIndex + 1;

                     // @ Run the SAME boot body as the initial fork, so a
                     //   recovered worker gets the node process title, the
                     //   monitor sink, the `Worker::Boot` event and per-worker
                     //   wiring (e.g. the WS cross-worker relay) — not a bare
                     //   TCP loop with a stale title.
                     $this->work($this->Process, $deadIndex);

                     exit(0);
                  }
                  // # Master process
                  else if ($newPID > 0) {
                     $this->Process->Children->push($newPID, $deadIndex);

                     $this->Logger->log(notice: "Worker #{$deadIndex} recovered (new PID: {$newPID})@.;");

                     $this->Process->State->save([
                        'master'  => Process::$master,
                        'workers' => $this->Process->Children->PIDs,
                        'host'    => $this->host ?? '0.0.0.0',
                        'port'    => $this->port ?? 0,
                        'started' => $this->started,
                        'type'    => 'WPI'
                     ]);
                  }
               }
            }
            break;

         // ! \Connection
         // ? @info
         // @ $connections
         case SIGIOT:  // 6
            CLI->Commands->find('connections', From: $this)?->run();
            break;
         // @ $stats
         case SIGIO:   // 29
            CLI->Commands->find('stats', From: $this)?->run();
            break;
      }
   }
   public function start (): bool
   {
      $this->Status = Status::Starting;

      // ! Capture the launch command NOW — before any daemon chdir — so reload()
      //   can re-exec a faithful copy of this master later (fresh PHP image =
      //   reloaded code, same PID). get_included_files()[0] is the absolute entry
      //   script, so it survives a working-directory change.
      $Included = get_included_files();
      self::$binary = PHP_BINARY;
      /** @var array<int,string> $argv */
      $argv = $_SERVER['argv'] ?? [];
      self::$argv = array_merge(
         [$Included[0] ?? ($argv[0] ?? '')],
         array_slice($argv, 1)
      );
      self::$directory = getcwd() ?: '';

      // ? Drop to the compact message line unless output is fully muted
      if (Display::$segments !== Display::NONE) {
         Display::show(Display::MESSAGE);
      }

      $this->Logger->log(notice: '@\;Starting Server...@.;');

      // @ Boot the application, or bail when no handler is wired (overridable).
      $this->loading();

      // ! Process
      // ? Pre-flight: verify socket can be bound before forking workers
      $probeCode = 0;
      $probeMessage = '';
      $probeContext = stream_context_create(['socket' => ['so_reuseport' => true, 'ipv6_v6only' => false]]);
      try {
         $probeSocket = @stream_socket_server(
            'tcp://' . ($this->host ?? '0.0.0.0') . ':' . ($this->port ?? 0),
            $probeCode,
            $probeMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $probeContext
         );
      }
      catch (Throwable) {
         $probeSocket = false;
      }
      if ($probeSocket === false) {
         $message = '@\;Could not bind to ' . ($this->host ?? '0.0.0.0') . ':' . ($this->port ?? 0) . ': ' . $probeMessage;
         if ($probeCode === 13 || str_contains($probeMessage ?? '', 'Permission denied')) {
            $message .= '@\;Ports below 1024 require elevated privileges. Try running with `sudo`.@.;';
         }
         $this->Logger->log(error: $message);
         exit(1);
      }
      fclose($probeSocket);

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
         SIGCHLD,  // recover
         SIGIOT,   // connection info
         SIGIO,    // connection stats
      ]);
      pcntl_signal(SIGPIPE, SIG_IGN, false);

      // @ Pre-fork setup hook (e.g. HTTP upload counter, WS broadcast bus).
      $this->booting();

      // @ Monitor mode: open the live-log pipe before forking so workers inherit it.
      $this->pipe();

      // @ Fork process workers — each runs the overridable worker() boot body.
      $this->Logger->log(notice: "Forking {$this->workers} workers... @.;");
      $this->Process->fork($this->workers, instance: function (Process $Process, int $index): void {
         $this->work($Process, $index);
      });

      // @ Set master process title
      $this->Process->title = $this->process . ': master process';

      // @ Save full process state (master + workers + host + port).
      //   Done while still privileged so the file exists before demote() can
      //   hand it off to the target user — the PID dir may not be writable
      //   by the demoted user, but chown on the existing file lets subsequent
      //   re-saves (e.g. in daemonize()) succeed as that user.
      $this->Process->State->save([
         'master'  => Process::$master,
         'workers' => $this->Process->Children->PIDs,
         'host'    => $this->host ?? '0.0.0.0',
         'port'    => $this->port ?? 0,
         'started' => time(),
         'type'    => 'WPI'
      ]);

      // @ Drop privileges on master post-fork + post-save. Workers kept their
      //   own bind as root (port <1024) and demote themselves in `instance()`.
      //   `demote()` also chowns state files so `project stop` from the
      //   demoted user can unlink them.
      $this->demote();

      // # Hook — the server is up and ready for connections (master, post-fork,
      //   post-demote). Set by the node `on(Events::ServerStarted, ...)`.
      if ($this->onServerStarted !== null) {
         ($this->onServerStarted)($this);
      }

      // ... Continue to master process:
      switch ($this->Mode) {
         case Modes::Daemon:
            $this->daemonize();
            break;
         case Modes::Foreground:
            $this->serve();
            break;
         case Modes::Interactive:
            $this->interacting();
            break;
         case Modes::Monitor:
            $this->monitoring();
            break;
      }

      return true;
   }

   /**
    * Boot the application before forking, or exit when no handler is wired.
    *
    * Overridable so nodes can honor `Modes::Test` and emit a node-specific
    * "no handler" message; the base boots `Production`.
    */
   protected function loading (): void
   {
      if (self::$Application) {
         self::$Application::boot(Environments::fetch(BOOTGLY_ENVIRONMENT));
      }
      else if (isSet(SAPI::$Handler) === false) {
         $this->Logger->log(error: '@\;No handler defined. Call on(Events::DataReceive, ...) before start().@\;');
         exit(1);
      }
   }

   /**
    * Pre-fork setup hook, run on the master after signals are installed and
    * before any worker is forked. Default: nothing. Nodes override it to set up
    * inherited resources (HTTP upload counter, WS cross-worker broadcast bus).
    */
   protected function booting (): void
   {
      // ...
   }

   /**
    * The per-worker boot body: process title, log routing, error handler,
    * `Worker::Boot` event, socket instance, per-worker wiring and the event
    * loop. Called by both the initial fork and the SIGCHLD recover path, so a
    * recovered worker is indistinguishable from an originally-forked one.
    */
   protected function work (Process $Process, int $index): void
   {
      // @ Process title (node-specific prefix).
      $Process->title = $this->process . ': child process (Worker #' . Process::$index . ')';

      // @ Monitor mode routes worker logs to the master viewer pipe; otherwise
      //   per-worker stdout.
      if ($this->Mode === Modes::Monitor) {
         $this->sink();
      }
      else {
         Display::show(Display::MESSAGE, Display::TIMESTAMP, Display::CHANNEL, Display::SEVERITY);
      }

      // @ Hot-path: restore the default error handler in the worker. The global
      //   Errors::collect handler is a userland callback hit on every suppressed
      //   warning (@fwrite/@fread EAGAIN under backpressure); the CLI default is
      //   a no-op for suppressed errors (zero cost).
      restore_error_handler();

      // @ Events — worker booted (guarded: zero-alloc when no listeners).
      $Emitter = Emitter::$Instance;
      $Emitter->check(Worker::Boot) && $Emitter->emit(Worker::Boot, $index);

      // @ Create the stream socket server.
      $this->instance();

      // @ Per-worker wiring hook (e.g. WS cross-worker relay). Default: nothing.
      $this->wire($index);

      // @ Event loop.
      self::$Event->add(
         $this->Socket,
         Select::EVENT_CONNECT,
         true
      );
      self::$Event->loop();

      // @ Close the stream socket server.
      $this->stop();
   }

   /**
    * Per-worker wiring hook, run inside each worker after `instance()` and
    * before the event loop. Default: nothing. Nodes override it to attach
    * per-worker event sources (e.g. the WS cross-worker relay socket).
    */
   protected function wire (int $index): void
   {
      // ...
   }

   /**
    * Create a new instance of the server.
    * 
    * @return resource|false
    */
   public function instance ()
   {
      $error_code = 0;
      $error_message = '';

      // @ Set context options
      self::$context = [];
      // Socket
      self::$context['socket'] = [
         // Used to limit the number of outstanding connections in the socket's listen queue.
         'backlog' => 102400,

         // Allows multiple bindings to a same ip:port pair, even from separate processes.
         'so_reuseport' => true,

         // Overrides the OS default regarding mapping IPv4 into IPv6.
         'ipv6_v6only' => false
      ];
      // SSL
      if ( ! empty($this->secure) ) {
         self::$context['ssl'] = $this->secure;
      }

      // @ Create context
      $Context = stream_context_create(self::$context);

      // @ Create server socket
      try {
         $Socket = @stream_socket_server(
            'tcp://' . $this->host . ':' . $this->port,
            $error_code,
            $error_message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $Context
         );
      }
      catch (Throwable) {
         $Socket = false;
      }

      if ($Socket === false) {
         $this->Logger->log(error: '@\;Could not create socket: ' . $error_message);
         exit(1);
      }
      /** @var resource $Socket */
      $this->Socket = $Socket;

      // @ On success

      // @ Disable Crypto in Main Socket
      if ( ! empty($this->secure) ) {
         stream_socket_enable_crypto($this->Socket, false);
      }
      // @ Enable Keep Alive if possible
      if (function_exists('socket_import_stream')) {
         try {
            $Socket = socket_import_stream($this->Socket);
         }
         catch (Throwable) {
            $Socket = false;
         }

         if ($Socket === false) {
            $this->Logger->log(error: '@\;Failed to import stream socket!@\;');
            exit(1);
         }

         socket_set_option($Socket, SOL_SOCKET, SO_KEEPALIVE, 1);
         socket_set_option($Socket, SOL_TCP, TCP_NODELAY, 1);
      }

      // @ Drop privileges if configured
      $this->demote();

      $this->Status = Status::Running;

      return $this->Socket;
   }

   /**
    * Demote the process from root to the configured user and group.
    * No-op if not running as root or no user is configured.
    *
    * This is a security best practice to minimize the potential impact of vulnerabilities in the server code.
    * Only takes effect when running as root with user/group configured.
    *
    * @return void
    */
   protected function demote (): void
   {
      if ($this->demoted || $this->user === null || posix_getuid() !== 0) {
         return;
      }

      $userInfo = posix_getpwnam($this->user);
      if ($userInfo === false) {
         $this->Logger->log(error: '@\;User "' . $this->user . '" not found. Cannot drop privileges.@\;');
         exit(1);
      }

      $uid = $userInfo['uid'];
      $gid = $userInfo['gid'];

      // @ Resolve group
      if ($this->group !== null) {
         $groupInfo = posix_getgrnam($this->group);
         if ($groupInfo === false) {
            $this->Logger->log(error: '@\;Group "' . $this->group . '" not found. Cannot drop privileges.@\;');
            exit(1);
         }
         $gid = $groupInfo['gid'];
      }

      // @ Hand over ownership of state files (pid/lock/command) to the
      //   demoted user, so `project stop` from that user can rewrite/unlink.
      $this->Process->State->own($uid, $gid);

      // @ Drop: group first, then user (order matters!)
      if (posix_setgid($gid) === false) {
         $this->Logger->log(error: '@\;Failed to set GID to ' . $gid . '.@\;');
         exit(1);
      }

      if (posix_initgroups($this->user, $gid) === false) {
         $this->Logger->log(error: '@\;Failed to init groups for user "' . $this->user . '".@\;');
         exit(1);
      }

      if (posix_setuid($uid) === false) {
         $this->Logger->log(error: '@\;Failed to set UID to ' . $uid . '.@\;');
         exit(1);
      }

      $this->demoted = true;
   }

   protected function daemonize (): void
   {
      $this->Status = Status::Running;

      $this->Logger->log(info: 'Running in Daemon mode (no UI)...@.;');

      // @ Fork: parent returns to terminal, child becomes daemon master
      $pid = pcntl_fork();

      if ($pid === -1) {
         $this->Logger->log(error: '@\;Failed to fork daemon process!@\;');
         exit(1);
      }

      // # Parent process (CLI caller): return control to terminal.
      //   The parent owns the worker children but transfers ownership to the
      //   daemon child by exiting — workers reparent to init (PID 1), which
      //   keeps the daemon lineage clean and avoids the parent staying alive
      //   waiting on workers (or catching SIGINT/SIGTERM and invoking
      //   `stop()`, which would wipe the freshly written PID file).
      if ($pid > 0) {
         $this->daemonized = true;
         $this->Logger->log(notice: '@\;Daemon started (PID: ' . $pid . ')@\;@.;');
         exit(0);
      }

      // # Child process (new daemon master): become session leader
      posix_setsid();

      // @ Update master PID to daemon child and re-save PID file
      Process::$master = posix_getpid();
      $this->Process->State->save([
         'master'  => Process::$master,
         'workers' => $this->Process->Children->PIDs,
         'host'    => $this->host ?? '0.0.0.0',
         'port'    => $this->port ?? 0,
         'started' => $this->started,
         'type'    => 'WPI'
      ]);

      // @ Daemon master loop (Status changes via signal handlers)
      while ($this->Status === Status::Running) { // @phpstan-ignore identical.alwaysTrue
         pcntl_signal_dispatch();

         // @ Reap any zombie children
         pcntl_waitpid(-1, $status, WNOHANG);

         usleep(500000); // 0.5s
      }
   }
   protected function serve (): void
   {
      $this->Status = Status::Running;

      $this->Logger->log(info: '@\;Running in Foreground mode (no UI)...@\;@.;');

      // @ Master loop (no fork): stay in the foreground as the container/service
      //   process. Logs go to stdout and SIGTERM/SIGINT stop via signal handlers.
      //   Master PID is already this process and was saved before the dispatch.
      while ($this->Status === Status::Running) { // @phpstan-ignore identical.alwaysTrue
         pcntl_signal_dispatch();

         // @ Reap any zombie children
         pcntl_waitpid(-1, $status, WNOHANG);

         usleep(500000); // 0.5s
      }
   }
   protected function interacting (): void
   {
      $this->Status = Status::Running;

      Display::show(Display::MESSAGE);

      $this->Logger->log(info: '@\;Entering in Interactive mode...@\;');
      $this->Logger->log(debug: '>_ Type `@#Green:stop@;` to stop the Server or `@#Green:help@;` to list commands.@\;');
      $this->Logger->log(debug: '>_ Type `@#Green:monitor@;` to enter in Monitor mode.@\;');
      $this->Logger->log(notice: '>_ Autocompletation and history enabled.@\\\;');

      while ($this->Mode === Modes::Interactive) {
         // @ Calls signal handlers for pending signals
         pcntl_signal_dispatch();

         // @ Suspends execution of the current process until a child has exited, or until a signal is delivered
         pcntl_wait($status, WNOHANG | WUNTRACED);

         // If child is running?
         if ($status === 0) {
            $interact = $this->Commands->interact();

            $this->Logger->log(debug: '@\;');

            // @ Wait for command output before looping
            if ($interact === false) {
               usleep(100000 * $this->workers); // @ wait 0.1 s * qt workers
            }
         }
         else if ($status > 0) { // If a child has already exited?
            $this->Logger->log(error: '@\;Process child exited!@\;');
            $this->Process->Signals->send(SIGINT);
            break;
         }
         else if ($status === -1) { // If error
            break;
         }
      }

      if ($this->Mode === Modes::Monitor) {
         $this->monitoring();
      }
   }
   /**
    * Open the live-log pipe before forking (Monitor mode only) so workers inherit it.
    */
   protected function pipe (): void
   {
      if ($this->Mode === Modes::Monitor) {
         $this->LogPipe = new IPCPipe;
         $this->LogPipe->open();
      }
   }
   /**
    * Route this process's logs into the Monitor pipe and silence stdout (Monitor mode only).
    *
    * Applied to both the master and every worker after fork, so all channels stream to the viewer.
    */
   protected function sink (): void
   {
      if ($this->Mode === Modes::Monitor && $this->LogPipe !== null) {
         Display::show(Display::NONE);
         Logger::$Tap = new PipeHandler($this->LogPipe);
      }
   }
   /**
    * Monitor mode: a full-screen, non-blocking live log viewer.
    *
    * Master + workers stream Records into a pipe; this loop drains them into a filterable viewer,
    * reads keystrokes, and redraws — replacing the old blocking `pcntl_wait` status dashboard.
    */
   protected function monitoring (): void
   {
      $this->Status = Status::Running;

      // @ Route master logs into the pipe + silence stdout
      //   NOTE: file-change auto-reload (watch the project on disk → reload()) is a
      //   follow-up; the previous SAPI::check() watcher was a dead no-op (it watched
      //   SAPI::$production, which no project ever sets). `project reload` (SIGUSR2)
      //   is the working, canonical trigger — see reload().
      $this->sink();

      $Output = CLI->Terminal->Output;
      $Input = CLI->Terminal->Input;

      // @ Enter full-screen TUI (alternate screen buffer)
      $Output->write("\e[?1049h\e[2J\e[H");
      $Output->Cursor->hide();
      $Input->configure(blocking: false, canonical: false, echo: false);

      // @ Always restore the terminal on exit — covers SIGINT/SIGTERM/`project stop` paths
      //   that terminate from the signal handler and never reach the teardown below.
      //   Without this the TTY stays in raw mode (no echo) after the server stops.
      register_shutdown_function(static function () use ($Input, $Output): void {
         $Input->configure(blocking: true, canonical: true, echo: true);
         $Output->Cursor->show();
         $Output->write("\e[?1049l");
      });

      // @ Refresh terminal size on resize (SIGWINCH)
      pcntl_signal(SIGWINCH, static function (): void {
         $columns = exec('tput cols 2>/dev/null');
         $lines = exec('tput lines 2>/dev/null');
         if (is_numeric($columns)) {
            Terminal::$width = (int) $columns;
         }
         if (is_numeric($lines)) {
            Terminal::$height = (int) $lines;
         }
      });

      $Viewer = new LogsViewer($Input, $Output);

      // @ Loop
      while ($this->Mode === Modes::Monitor && $this->Status === Status::Running) {
         // @ Dispatch pending signals (reforks, reload, shutdown, resize)
         pcntl_signal_dispatch();

         // @ Drain worker + master logs from the pipe
         if ($this->LogPipe !== null) {
            while (true) {
               $chunk = $this->LogPipe->read(65536);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $Viewer->feed($chunk);
            }
         }

         // @ Handle one keystroke (non-blocking)
         $key = $Input->read(8);
         if ($key !== false && $key !== '') {
            if ($Viewer->control($key) === false) {
               $this->Mode = Modes::Interactive;
               break;
            }
         }

         // @ Redraw + throttle (~30 fps)
         $Viewer->render();
         usleep(30000);
      }

      // @ Leave full-screen TUI + restore terminal
      pcntl_signal(SIGWINCH, SIG_DFL);
      $Input->configure(blocking: true, canonical: true, echo: true);
      $Output->Cursor->show();
      $Output->write("\e[?1049l");

      // @ Restore normal logging
      Logger::$Tap = null;
      Display::show(Display::MESSAGE);

      // @ Enter Interactive mode if requested
      if ($this->Mode === Modes::Interactive) {
         Timer::del(0);
         $this->interacting();
      }
   }

   public function close (): bool
   {
      try {
         $closed = @fclose($this->Socket);
      }
      catch (Throwable) {
         $closed = false;
      }

      if ($closed === false) {
         $this->Logger->log(debug: '@\;Failed to close $this->Socket!');
      }

      return $closed;
   }

   public function resume (): bool
   {
      if ($this->Status !== Status::Paused) {
         match ($this->Process->level) {
            'master' => $this->Logger->log(error: "Server needs to be paused to resume!@\\;"),
            'child' => null,
            default => null
         };

         return false;
      }

      $children = (string) count($this->Process->Children->PIDs);
      match ($this->Process->level) {
         'master' => $this->Logger->log(critical: "Resuming {$children} worker(s)... @\\;"),
         'child' => self::$Event->add($this->Socket, self::$Event::EVENT_CONNECT, true),
         default => null
      };

      $this->Status = Status::Running;

      return true;
   }
   public function pause (): bool
   {
      if ($this->Status !== Status::Running) {
         match ($this->Process->level) {
            'master' => $this->Logger->log(error: "Server needs to be running to pause!@\\;"),
            'child' => null,
            default => null
         };

         return false;
      }

      $children = (string) count($this->Process->Children->PIDs);
      match ($this->Process->level) {
         'master' => $this->Logger->log(critical: "Pausing {$children} worker(s)... @\\;"),
         'child' => self::$Event->del($this->Socket, self::$Event::EVENT_CONNECT),
         default => null
      };

      $this->Status = Status::Paused;

      return true;
   }
   public function stop (): void
   {
      // # Hook — the server is stopping (master only, before teardown). Set by
      //   the node `on(Events::ServerStopped, ...)`.
      if ($this->onServerStopped !== null && $this->Process->level === 'master') {
         ($this->onServerStopped)($this);
      }

      // @ Events — process shutting down (guarded: zero-alloc when no listeners)
      $Emitter = Emitter::$Instance;
      $Emitter->check(Worker::Shutdown) && $Emitter->emit(Worker::Shutdown, $this->Process->level);

      $this->Status = Status::Stopping;
      $this->Process->stopping = true;

      Display::show(Display::MESSAGE);

      switch ($this->Process->level) {
         case 'master':
            $children = (string) count($this->Process->Children->PIDs);
            $this->Logger->log(critical: "Stopping {$children} worker(s)...@\\;");

            $this->Process->Children->terminate();

            $this->Logger->log(critical: "{$children} worker(s) stopped!@\\;");

            // @ Clean all per-project state files
            $this->Process->State->clean();

            $CI_CD = Environment::get('GITHUB_ACTIONS')
               || Environment::get('TRAVIS')
               || Environment::get('CIRCLECI')
               || Environment::get('GITLAB_CI')
               || Environment::get('GIT_EXEC_PATH'); // Git Hooks?
            if ($this->Mode->value >= Modes::Test->value && $CI_CD) {
               break;
            }

            exit(0);
         case 'child':
            exit(0);
      }
   }

   /**
    * Gracefully drain a worker for reload: stop accepting new connections (leave
    * the SO_REUSEPORT group so nothing new is routed here), let the in-flight
    * connections finish, then break the event loop so the worker exits cleanly
    * via work() → stop(). Bounded by `self::$drainTimeout` so a stuck peer can
    * never pin the reload open.
    */
   protected function drain (): void
   {
      // ? Only a worker drains; the master orchestrates via reload().
      if ($this->Process->level !== 'child') {
         return;
      }

      $this->Status = Status::Stopping;

      // @ Leave the accept set + the SO_REUSEPORT group: no new connection is
      //   routed to a draining worker; established ones keep their own sockets.
      self::$Event->del($this->Socket, self::$Event::EVENT_CONNECT);
      @fclose($this->Socket);

      // ? Already idle — break the loop at once (the common lightly-loaded case).
      if (count(Connections::$Connections) === 0) {
         self::$Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
         return;
      }

      // @@ Otherwise poll once a second (SIGALRM-driven Timer): break the loop once
      //    the in-flight connections have drained or the budget expires. Breaking
      //    the loop returns from work() → stop() → the forked child exit(0)s.
      $deadline = time() + self::$drainTimeout;
      Timer::add(
         interval: 1,
         handler: function () use ($deadline): void {
            if (count(Connections::$Connections) === 0 || time() >= $deadline) {
               self::$Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
            }
         },
         persistent: true
      );
   }

   /**
    * Graceful hot-reload (master-only): drain every worker, then re-exec this
    * master into a fresh PHP image so the whole application — closures AND
    * autoloaded classes — is reloaded from disk. The master PID is preserved.
    *
    * In-flight connections are drained first (no dropped requests); there is a
    * brief window between the last old worker exiting and the fresh workers
    * binding where new connections are refused. Files loaded before fork reload
    * because the process image itself is replaced — unlike an in-place reboot,
    * which PHP cannot do for already-defined classes/closures.
    */
   protected function reload (): void
   {
      // ? Only the master reloads.
      if ($this->Process->level !== 'master') {
         return;
      }

      // ? Validate the captured launch command BEFORE tearing down workers, so a
      //   bad capture can never leave the service running without any worker.
      $entry = self::$argv[0] ?? '';
      if ($entry === '' || is_file($entry) === false) {
         $this->Logger->log(error: "Reload aborted: entry script '{$entry}' not found.@\\;");
         return;
      }

      $this->Logger->log(notice: '@\;Reloading (graceful re-exec)...@.;');

      // ! Mark reloading so recover() does not refork the workers we drain.
      $this->Process->reloading = true;

      // @ Drain every worker gracefully (SIGQUIT → worker drain()), then reap.
      //   Stragglers past the budget are force-killed by terminate()'s SIGKILL.
      $this->Process->Children->terminate(timeout: self::$drainTimeout + 5, signal: SIGQUIT);

      // @ Clear per-project state files; the fresh master rewrites them on start.
      $this->Process->State->clean();

      // @ Restore the launch directory (a daemon may have chdir'd to /), then
      //   replace this process image with a fresh one. pcntl_exec keeps the PID.
      if (self::$directory !== '') {
         @chdir(self::$directory);
      }
      pcntl_exec(self::$binary, self::$argv, getenv());

      // ? exec only returns on failure — workers are already gone, so surface the
      //   error and exit rather than linger as a workerless master.
      $this->Logger->log(error: 'Reload failed: could not re-exec the master.@\;');
      exit(1);
   }

   public function __destruct ()
   {
      // @ Reset Opcache?
      /*
      if (function_exists('opcache_reset') && $this->Process->level === 'master') {
         opcache_reset();
      }
      */
   }
}
