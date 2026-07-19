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
use const LOCK_EX;
use const LOCK_NB;
use const PHP_BINARY;
use const PHP_SAPI;
use const SCM_RIGHTS;
use const SIG_DFL;
use const SIG_IGN;
use const SIG_SETMASK;
use const SIGALRM;
use const SIGCHLD;
use const SIGCONT;
use const SIGHUP;
use const SIGINT;
use const SIGIO;
use const SIGIOT;
use const SIGKILL;
use const SIGPIPE;
use const SIGQUIT;
use const SIGTERM;
use const SIGTSTP;
use const SIGURG;
use const SIGUSR1;
use const SIGUSR2;
use const SIGWINCH;
use const SO_KEEPALIVE;
use const SO_TYPE;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;
use const STDERR;
use const STDIN;
use const STDOUT;
use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_TLS_CLIENT;
use const STREAM_CRYPTO_METHOD_TLS_SERVER;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const STREAM_SOCK_STREAM;
use const TCP_NODELAY;
use const WNOHANG;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_pad;
use function array_shift;
use function array_slice;
use function array_values;
use function basename;
use function bin2hex;
use function chdir;
use function chmod;
use function count;
use function defined;
use function explode;
use function fclose;
use function feof;
use function fflush;
use function file_get_contents;
use function fopen;
use function fread;
use function fsync;
use function function_exists;
use function fwrite;
use function get_included_files;
use function getcwd;
use function getenv;
use function glob;
use function hash;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_numeric;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function lstat;
use function max;
use function method_exists;
use function microtime;
use function min;
use function mkdir;
use function net_get_interfaces;
use function openssl_x509_check_private_key;
use function openssl_x509_fingerprint;
use function pcntl_exec;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_sigprocmask;
use function pcntl_waitpid;
use function posix_getgrnam;
use function posix_getpid;
use function posix_getppid;
use function posix_getpwnam;
use function posix_getuid;
use function posix_initgroups;
use function posix_kill;
use function posix_setgid;
use function posix_setsid;
use function posix_setuid;
use function preg_match;
use function putenv;
use function random_bytes;
use function range;
use function readline_callback_handler_install;
use function readline_callback_handler_remove;
use function readline_callback_read_char;
use function register_shutdown_function;
use function restore_error_handler;
use function rmdir;
use function rtrim;
use function scandir;
use function socket_cmsg_space;
use function socket_export_stream;
use function socket_get_option;
use function socket_import_stream;
use function socket_recvmsg;
use function socket_sendmsg;
use function socket_set_option;
use function str_contains;
use function stream_context_create;
use function stream_context_get_options;
use function stream_context_set_options;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function time;
use function umask;
use function unlink;
use function usleep;
use BackedEnum;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Socket;
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
use Bootgly\CLI\Terminal\Screen;
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
   private const string RELOAD_HANDOFF_PATH = 'BOOTGLY_RELOAD_HANDOFF_PATH';
   private const string RELOAD_HANDOFF_TOKEN = 'BOOTGLY_RELOAD_HANDOFF_TOKEN';
   private const string RELOAD_HANDOFF_PID = 'BOOTGLY_RELOAD_HANDOFF_PID';
   // Linux caps one SCM_RIGHTS control message at 253 descriptors. Keep room
   // for node-owned listeners (for example Auto-TLS' HTTP-01 gate).
   private const int RELOAD_HANDOFF_MAX_RESOURCES = 240;

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
   /** @var array<int,resource> Master-owned SO_REUSEPORT listener pool. */
   protected array $Listeners = [];
   /** @var array<int,int> Worker indexes reaped by the SIGCHLD dispatch, awaiting refork. */
   private array $casualties = [];

   public static Events & Loops & Scheduler $Event;

   protected Commands $Commands;
   protected Process $Process;

   // * Config
   protected null|string $domain;
   protected null|string $host;
   protected null|int $port;
   protected int $workers;
   /** @var array<string,mixed> */
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
   //   Maximum simultaneously-admitted connections per worker, including
   //   pending TLS peers. New connections accepted past this ceiling are
   //   immediately shed (accepted then closed) to bound FD/memory under a
   //   connection-flood DoS. 0 =
   //   unlimited. Evaluated once per accept — never on the per-request hot
   //   path — so it does not affect throughput on established connections.
   public static int $maxConnections = 10000;
   //   Maximum simultaneously-established connections from a single peer IP.
   //   Opt-in (0 = unlimited) because reverse-proxy deployments collapse every
   //   client onto one source IP; enable it only when the peer IP is the real
   //   client. When > 0, accepts past it are shed.
   public static int $maxConnectionsPerIP = 0;
   // # TLS admission
   //   Absolute wall-time budget for a nonblocking TLS handshake. The
   //   Connection converts it to a monotonic deadline when the peer is
   //   accepted, so wall-clock adjustments cannot extend the negotiation.
   public static float $handshakeTimeout = 10.0;
   //   Maximum TLS handshakes that may be pending concurrently per worker.
   //   This is intentionally lower than the general connection ceiling:
   //   an unauthenticated peer must not reserve the full connection pool by
   //   opening sockets without sending a ClientHello. 0 does not disable the
   //   bound; Connection admission clamps it to one as a fail-closed policy.
   public static int $maxPendingHandshakes = 256;
   // # Reload — graceful drain budget (seconds) each worker gets to finish its
   //   in-flight connections before the master force-kills it during a reload.
   public static int $drainTimeout = 30;
   /** @var array<string,true> */
   protected array $Events = [];
   // # Hooks — server lifecycle callbacks, set by the node `on()` overrides.
   /** Launch banner hook — fired on the process that owns the terminal (the launcher on Daemon mode) */
   protected null|Closure $onServerAdvertised = null;
   protected null|Closure $onServerStarted = null;
   protected null|Closure $onServerStopped = null;

   // * Metadata
   /** The first non-loopback IPv4 of the machine — null when none is resolvable */
   public null|string $network {
      get {
         if (function_exists('net_get_interfaces') === true) {
            foreach (net_get_interfaces() ?: [] as $interface) {
               foreach ($interface['unicast'] ?? [] as $unicast) {
                  $address = (string) ($unicast['address'] ?? '');
                  // ? family 2 = IPv4 (AF_INET, literal: the sockets extension is optional)
                  if (($unicast['family'] ?? 0) === 2 && $address !== '' && $address !== '127.0.0.1') {
                     return $address;
                  }
               }
            }
         }

         // :
         return null;
      }
   }
   // # State
   protected int $started = 0;
   protected bool $daemonized = false;
   /** @var array<int,resource> Keep /dev/null descriptors alive after detach(). */
   protected array $daemonStreams = [];
   /**
    * Per-process credential artifact retained for the active SSL context.
    *
    * @var null|array{directory:string,files:array<int,string>,handles:array<int,resource>}
    */
   protected null|array $credential = null;
   /** @var resource|null Launcher readiness channel, daemon child only. */
   protected $daemonReady = null;
   /** Why the startup readiness barrier failed — node-set, shipped to the daemon launcher. */
   protected string $fault = '';
   /**
    * Inside the startup readiness barrier. A worker lost here is a definitive
    * startup failure: it is reaped but never reforked (reforking would rebuild
    * the same failing boot), and node fallbacks stay inert — the launcher
    * reports the failure instead.
    */
   protected bool $starting = false;
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
   /** @var array<array<mixed>|string> */
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
      // ? State stays unqualified (and unlocked) until start() knows the bound
      //   port — the port is the instance qualifier of the PID/lock files.
      $processId = defined('BOOTGLY_PROJECT') ? Projects::encode(BOOTGLY_PROJECT->folder) : static::class;
      $Process = $this->Process = new Process(id: $processId);
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
   * @param array<string,mixed>|null $secure Secure SSL/TLS Stream Context
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
            $line = $this->Commands->read();

            if ($line !== null) {
               [$command, $context] = array_pad(explode(':', $line, 2), 2, '');

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

                  // @ The reaped worker can no longer release its own private
                  //   credential artifact — a hard kill never runs its exit
                  //   path. The reaper owns that cleanup.
                  $this->sweep();

                  // ? A worker lost inside the startup barrier is reaped, not
                  //   reforked: the replacement would rebuild the same failing
                  //   boot. `ready()` sees the lost worker and fails startup.
                  if ($this->starting) {
                     $this->Logger->log(
                        warning: "Worker #{$deadIndex} (PID: {$deadPID}) died before startup completed.@.;"
                     );

                     continue;
                  }

                  $this->Logger->log(warning: "Worker #{$deadIndex} (PID: {$deadPID}) crashed, reforking...@.;");

                  // ! Never fork inside this dispatch: pcntl flags its
                  //   dispatcher busy while a user handler runs, and a child
                  //   forked in that window inherits the flag — its own
                  //   signal handlers would never fire again. tick() reforks
                  //   from plain loop context via revive().
                  $this->casualties[] = $deadIndex;
               }
            }
            break;

         // ! \Connection
         // ? @info
         // @ $connections
         case SIGIOT:  // 6
            try {
               CLI->Commands->find('connections', From: $this)?->run();
            }
            catch (Throwable) {
               // ! Signal callbacks must never let a diagnostic failure
               //   terminate the worker. Do not log exception-controlled
               //   text here: it may contain the remote state being guarded.
            }
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

      // ! The state identity must be final before a reload descriptor is
      //   inspected: State::adopt() validates the received regular file
      //   against this exact qualified pathname and inode.
      $State = $this->Process->State;
      $State->qualify((string) ($this->port ?? 0));

      // @ A reload never attempts to regain root. The old, already-demoted
      //   master hands its bound listeners and acquired instance lock to this
      //   fresh image through a short-lived same-UID SCM_RIGHTS relay.
      $handoff = $this->receive();
      if ($handoff === false) {
         $this->Logger->log(error: '@\;Reload aborted: the inherited listener handoff was invalid.@\;');
         exit(1);
      }

      // ? On an ordinary launch there is no handoff, so retain the early bind
      //   probe and its useful permission diagnostic.
      if ($this->Listeners === []) {
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
      }

      // ! Daemonize BEFORE any worker or auxiliary fork. The child that
      //   continues from here is the final master and therefore the real
      //   parent/reaper of every process created below.
      if ($this->Mode === Modes::Daemon && $handoff !== true) {
         $this->detach();
      }

      // ! Process
      // ? Acquire the qualified instance lock only after daemonization chose
      //   the final master PID. Project controls authenticate that PID as the
      //   kernel-reported owner of this exact flock; workers inherit the same
      //   descriptor but can never impersonate the master role.
      if ($State->lock(LOCK_EX | LOCK_NB) === false) {
         $this->Logger->log(
            error: '@\;Another instance is already running on port ' . ($this->port ?? 0) . '.@\;Use `project stop <name> <port>` to stop it or start this one on another port (PORT env).@.;'
         );
         exit(1);
      }
      if ($this->Commands->erase() === false) {
         $this->Logger->log(error: '@\;The process command channel could not be initialized safely.@.;');
         exit(1);
      }

      // ? Signals
      // @ Install process signals
      $this->Process->Signals->install([
         SIGALRM,  // Timer
         SIGUSR1,  // Custom command
         SIGURG,   // Node-specific out-of-band wake-up (Auto-TLS)
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

      // ! Pre-bind one SO_REUSEPORT listener per worker in the master while it
      //   still has the launch privileges. Each worker inherits only its own
      //   accept queue; the demoted master retains the pool for crash recovery
      //   and a privilege-safe reload handoff.
      if ($this->Listeners === [] && $this->listen() === false) {
         exit(1);
      }

      // @ Monitor mode: open the live-log pipe before forking so workers inherit it.
      $this->pipe();

      // @ Fork process workers — each runs the overridable worker() boot body.
      $this->Logger->log(notice: "Forking {$this->workers} workers... @.;");
      $this->Process->fork($this->workers, instance: function (Process $Process, int $index): void {
         $this->work($Process, $index);
      });

      // ? Test mode keeps the master inside the shared test-runner process and
      //   never hot-reloads. Retaining the pre-bound pool there would pin the
      //   port for every later suite in the same process; a respawned worker
      //   falls back to a fresh SO_REUSEPORT bind in instance().
      if ($this->Mode === Modes::Test) {
         foreach ($this->Listeners as $Listener) {
            fclose($Listener);
         }
         $this->Listeners = [];
      }

      // @ Set master process title
      $this->Process->title = $this->process . ': master process';

      // @ Save full process state (master + workers + host + port). In Daemon
      //   mode detach() already installed the final master PID before workers
      //   were created, so every listed PID is its actual child.
      $this->Process->State->save($this->describe());

      // @ Drop privileges on master post-fork + post-save. Each worker already
      //   inherited one listener that the privileged master pre-bound, then
      //   demoted itself in `instance()`. The master now demotes permanently;
      //   reload transfers those descriptors instead of regaining privilege.
      $this->demote();

      // ! Node-specific readiness is a barrier, not a notification. A node
      //   may require every worker to prove local initialization (for example
      //   an Auto-TLS credential activation) before startup can be advertised.
      $this->starting = true;
      $crossed = $this->ready();
      $this->starting = false;

      if ($crossed === false) {
         $message = 'Server workers did not cross the startup readiness barrier.';
         if ($this->fault !== '') {
            $message .= " {$this->fault}";
         }

         $this->Process->stopping = true;
         $this->Process->Children->terminate();
         foreach ($this->Listeners as $Listener) {
            fclose($Listener);
         }
         $this->Listeners = [];
         $this->Process->State->clean();
         if (is_resource($this->daemonReady)) {
            // ! Daemon mode detached the terminal — ship the real reason to
            //   the waiting launcher through the readiness channel
            fwrite($this->daemonReady, "fail!{$message}");
            fclose($this->daemonReady);
            $this->daemonReady = null;
         }

         throw new RuntimeException($message);
      }

      // # Hook — the server is up and ready for connections (master, post-fork,
      //   post-demote). Set by the node `on(Events::ServerStarted, ...)`.
      if ($this->onServerStarted !== null) {
         ($this->onServerStarted)($this);
      }

      // @ A daemon launcher reports success only after state, workers,
      //   privilege drop and the node-specific startup hook all completed.
      $this->announce();

      // @ Launch banner on the modes that keep the terminal — on Daemon mode
      //   the launcher fires the same hook on its own side, before detaching
      if ($this->Mode !== Modes::Daemon && $this->onServerAdvertised !== null) {
         ($this->onServerAdvertised)($this);
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

   /** @return array<string,mixed> Persisted master/worker topology. */
   protected function describe (): array
   {
      return [
         'master'  => Process::$master,
         'workers' => $this->Process->Children->PIDs,
         'host'    => $this->host ?? '0.0.0.0',
         'port'    => $this->port ?? 0,
         'started' => $this->started,
         'type'    => 'WPI'
      ];
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
    * Node-specific post-fork startup barrier. The default TCP server has no
    * extra worker proof beyond a successful fork; nodes may override it.
    */
   protected function ready (): bool
   {
      return true;
   }

   /**
    * The per-worker boot body: process title, log routing, error handler,
    * `Worker::Boot` event, socket instance, per-worker wiring and the event
    * loop. Called by both the initial fork and the SIGCHLD recover path, so a
    * recovered worker is indistinguishable from an originally-forked one.
    */
   protected function work (Process $Process, int $index): void
   {
      // ! A worker reforked from the master's SIGCHLD dispatch inherits the
      //   dispatcher's full signal block mask across pcntl_fork(); a serving
      //   worker must field its own SIGQUIT/SIGTERM lifecycle signals.
      pcntl_sigprocmask(SIG_SETMASK, []);

      // The launcher handshake belongs only to the final daemon master. A
      // worker retaining the inherited descriptor would hide master failure.
      if (is_resource($this->daemonReady)) {
         fclose($this->daemonReady);
         $this->daemonReady = null;
      }

      // @ Fork hygiene — drop Timer tasks inherited from the parent: POSIX
      //   clears pending alarms on fork, so inherited tasks can never fire
      //   here, yet a non-empty inherited task map would stop the next
      //   `Timer::add()` from arming its alarm — leaving every timer this
      //   worker installs (e.g. the SSE supervisor) silently dead.
      Timer::del();

      // ! A hard-killed daemon master cannot signal its workers. Detect
      //   reparenting and stop locally so stale workers never outlive their
      //   supervisor or retain its inherited process-state lock forever.
      $master = Process::$master;
      Timer::add(
         interval: 1,
         handler: function () use ($master): void {
            if (posix_getppid() !== $master) {
               $this->stop();
            }
         },
         persistent: true
      );

      // @ Process title (node-specific prefix).
      $Process->title = $this->process . ': child process (Worker #' . Process::$index . ')';

      // @ Monitor mode routes worker logs to the master viewer pipe; Daemon
      //   detaches from the terminal — workers keep the inherited tty fd, so
      //   console display must be off (global sinks still persist records);
      //   otherwise per-worker stdout.
      if ($this->Mode === Modes::Monitor) {
         $this->sink();
      }
      else if ($this->Mode === Modes::Daemon) {
         Display::show(Display::NONE);
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

      // @ Adopt this worker's pre-bound SO_REUSEPORT listener.
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
      if ($this->Listeners !== []) {
         $index = max(0, Process::$index - 1);
         $selected = $this->Listeners[$index % count($this->Listeners)];
         foreach ($this->Listeners as $Listener) {
            if ($Listener !== $selected) {
               fclose($Listener);
            }
         }
         $this->Listeners = [];
         $this->Socket = $selected;
      }
      else if ($this->open() === false) {
         exit(1);
      }

      if ($this->prepare($this->Socket) === false) {
         $this->Logger->log(error: '@\;Failed to prepare the inherited listener.@\;');
         exit(1);
      }

      // @ Drop privileges if configured
      $this->demote();

      $this->Status = Status::Running;

      return $this->Socket;
   }

   /** Bind the master-owned SO_REUSEPORT pool used by workers and reload. */
   private function listen (): bool
   {
      $this->Listeners = [];
      for ($index = 0; $index < max(1, $this->workers); $index++) {
         $Listener = $this->open(assign: false);
         if ($Listener === false) {
            foreach ($this->Listeners as $Bound) {
               fclose($Bound);
            }
            $this->Listeners = [];

            return false;
         }
         $this->Listeners[] = $Listener;
      }

      return true;
   }

   /** @return resource|false */
   private function open (bool $assign = true)
   {
      self::$context = [
         'socket' => [
            'backlog' => 102400,
            'so_reuseport' => true,
            'ipv6_v6only' => false
         ]
      ];
      if ( ! empty($this->secure) ) {
         self::$context['ssl'] = $this->secure;
      }

      $errorCode = 0;
      $errorMessage = '';
      try {
         $Listener = @stream_socket_server(
            'tcp://' . $this->host . ':' . $this->port,
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(self::$context)
         );
      }
      catch (Throwable) {
         $Listener = false;
      }
      if ($Listener === false) {
         $this->Logger->log(error: '@\;Could not create socket: ' . $errorMessage);

         return false;
      }
      if ($this->prepare($Listener) === false) {
         fclose($Listener);
         $this->Logger->log(error: '@\;Failed to prepare stream socket!@\;');

         return false;
      }
      if ($assign) {
         $this->Socket = $Listener;
      }

      return $Listener;
   }

   /** @param resource $Listener */
   private function prepare ($Listener): bool
   {
      if (self::$context === []) {
         self::$context = [
            'socket' => [
               'backlog' => 102400,
               'so_reuseport' => true,
               'ipv6_v6only' => false
            ]
         ];
         if ( ! empty($this->secure) ) {
            self::$context['ssl'] = $this->secure;
         }
      }
      if (stream_context_set_options($Listener, self::$context) === false) { // @phpstan-ignore identical.alwaysFalse
         return false;
      }
      if ( ! empty($this->secure) ) {
         stream_socket_enable_crypto($Listener, false);
      }
      if (function_exists('socket_import_stream')) {
         try {
            $Socket = socket_import_stream($Listener);
         }
         catch (Throwable) {
            return false;
         }
         if ($Socket === false) {
            return false;
         }
         if (
            socket_set_option($Socket, SOL_SOCKET, SO_KEEPALIVE, 1) === false
            || socket_set_option($Socket, SOL_TCP, TCP_NODELAY, 1) === false
         ) {
            return false;
         }
      }

      return true;
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

      $UID = $userInfo['uid'];
      $GID = $userInfo['gid'];

      // @ Resolve group
      if ($this->group !== null) {
         $groupInfo = posix_getgrnam($this->group);
         if ($groupInfo === false) {
            $this->Logger->log(error: '@\;Group "' . $this->group . '" not found. Cannot drop privileges.@\;');
            exit(1);
         }
         $GID = $groupInfo['gid'];
      }

      // @ Only the final master publishes process state. Workers can reach
      //   demote() while the root master is atomically replacing its PID file;
      //   letting every child chown/post-check that pathname creates a real
      //   save-vs-own inode race. Helpers and workers still drop privileges,
      //   while the master performs the one ownership handoff after save().
      if (
         $this->Process->level === 'master'
         && $this->Process->State->own($UID, $GID) === false
      ) {
         $this->Logger->log(error: '@\;Failed to hand process state to the configured runtime identity.@\;');
         exit(1);
      }

      // @ Drop: group first, then user (order matters!)
      if (posix_setgid($GID) === false) {
         $this->Logger->log(error: '@\;Failed to set GID to ' . $GID . '.@\;');
         exit(1);
      }

      if (posix_initgroups($this->user, $GID) === false) {
         $this->Logger->log(error: '@\;Failed to init groups for user "' . $this->user . '".@\;');
         exit(1);
      }

      if (posix_setuid($UID) === false) {
         $this->Logger->log(error: '@\;Failed to set UID to ' . $UID . '.@\;');
         exit(1);
      }

      $this->demoted = true;
   }

   /**
    * Master supervision tick — run once per master-loop iteration in every
    * mode (Daemon, Foreground, Interactive, Monitor). Default: nothing.
    * Nodes override it for periodic master-side duties (e.g. the HTTP
    * Auto-TLS renewal pump). Deliberately not Timer-based: Monitor mode
    * wipes the master timers (`Timer::del(0)`) when dropping to
    * Interactive, which would silently kill a Timer-driven check.
    */
   protected function tick (): void
   {
      $this->revive();
   }

   /**
    * Refork the workers reaped by the SIGCHLD dispatch. Runs from the master
    * loops' tick() — plain execution context — because a child forked inside
    * `pcntl_signal_dispatch()` inherits its busy flag and goes signal-deaf.
    */
   protected function revive (): void
   {
      while ($this->casualties !== []) {
         $deadIndex = array_shift($this->casualties);

         $newPID = pcntl_fork();

         // # Child process (new worker)
         if ($newPID === 0) {
            $this->Process->Children->push($this->Process->id, $deadIndex);
            Process::$index = $deadIndex + 1;

            // @ Run the SAME boot body as the initial fork, so a recovered
            //   worker gets the node process title, the monitor sink, the
            //   `Worker::Boot` event and per-worker wiring (e.g. the WS
            //   cross-worker relay) — not a bare TCP loop with a stale title.
            $this->work($this->Process, $deadIndex);

            exit(0);
         }
         // # Master process
         else if ($newPID > 0) {
            $this->Process->Children->push($newPID, $deadIndex);

            $this->Logger->log(notice: "Worker #{$deadIndex} recovered (new PID: {$newPID})@.;");

            $this->Process->State->save($this->describe());
         }
      }
   }

   /**
    * Fork the final daemon master before any server child exists.
    * The launcher exits; only the session-leading child returns.
    */
   protected function detach (): void
   {
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($Pair === false) {
         $this->Logger->log(error: '@\;Failed to create the daemon readiness channel!@\;');
         exit(1);
      }

      $PID = pcntl_fork();
      if ($PID === -1) {
         fclose($Pair[0]);
         fclose($Pair[1]);
         $this->Logger->log(error: '@\;Failed to fork daemon process!@\;');
         exit(1);
      }

      if ($PID > 0) {
         $this->daemonized = true;
         fclose($Pair[1]);
         stream_set_blocking($Pair[0], false);

         $ready = '';
         $deadline = microtime(true) + 30.0;
         while (strlen($ready) < 5 && microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
               break;
            }
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $read = [$Pair[0]];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
               continue;
            }
            if ($selected === 0) {
               break;
            }
            $chunk = fread($Pair[0], 5 - strlen($ready));
            if ($chunk === false || ($chunk === '' && feof($Pair[0]))) {
               break;
            }
            $ready .= $chunk;
         }

         // ? A failing master ships the reason right after the 5-byte verdict
         $fault = '';
         if ($ready === 'fail!') {
            $drain = microtime(true) + 2.0;
            while (strlen($fault) < 1024 && microtime(true) < $drain) {
               $remaining = $drain - microtime(true);
               if ($remaining <= 0) {
                  break;
               }
               $seconds = (int) $remaining;
               $microseconds = (int) (($remaining - $seconds) * 1_000_000);
               $read = [$Pair[0]];
               $write = null;
               $except = null;
               $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
               if ($selected !== 1) {
                  break;
               }
               $chunk = fread($Pair[0], 1024 - strlen($fault));
               if ($chunk === false || ($chunk === '' && feof($Pair[0]))) {
                  break;
               }
               $fault .= $chunk;
            }
         }
         fclose($Pair[0]);

         if ($ready === 'ready') {
            $this->Logger->log(notice: '@\;Daemon started (PID: ' . $PID . ')@\;@.;');

            // @ Launch banner — the daemon terminal is detached, so the
            //   launcher renders it (the project hook owns the presentation)
            if ($this->onServerAdvertised !== null) {
               ($this->onServerAdvertised)($this);
            }

            exit(0);
         }

         $groupAlive = posix_kill(-$PID, 0);
         $masterAlive = posix_kill($PID, 0);
         if ($groupAlive) {
            posix_kill(-$PID, SIGTERM);
         }
         else if ($masterAlive) {
            posix_kill($PID, SIGTERM);
         }
         $reaped = pcntl_waitpid($PID, $status, WNOHANG);
         $stopDeadline = microtime(true) + 2.0;
         while ($reaped === 0 && microtime(true) < $stopDeadline) {
            usleep(50000);
            $reaped = pcntl_waitpid($PID, $status, WNOHANG);
         }
         if (posix_kill(-$PID, 0)) {
            posix_kill(-$PID, SIGKILL);
         }
         else if ($reaped === 0 && posix_kill($PID, 0)) {
            posix_kill($PID, SIGKILL);
         }
         if ($reaped === 0) {
            pcntl_waitpid($PID, $status);
         }
         // ! This launcher never owns the post-detach child lock. Acquire the
         //   exact stable inode before cleanup so a losing duplicate launch
         //   cannot tombstone the already-running winner's state.
         if ($this->Process->State->lock(LOCK_EX | LOCK_NB)) {
            $this->Process->State->clean();
         }
         if ($fault !== '') {
            $this->Logger->log(error: "@\;{$fault}@\;");
         }
         $this->Logger->log(error: '@\;Daemon startup failed before readiness was acknowledged.@\;');
         exit(1);
      }

      fclose($Pair[0]);
      $this->daemonReady = $Pair[1];

      if (posix_setsid() === -1) {
         $this->Logger->log(error: '@\;Failed to create daemon session!@\;');
         exit(1);
      }

      Process::$master = posix_getpid();
      Display::show(Display::NONE);

      fclose(STDIN);
      fclose(STDOUT);
      fclose(STDERR);
      $stdin = fopen('/dev/null', 'r');
      $stdout = fopen('/dev/null', 'w');
      $stderr = fopen('/dev/null', 'w');
      foreach ([$stdin, $stdout, $stderr] as $Stream) {
         if (is_resource($Stream)) {
            $this->daemonStreams[] = $Stream;
         }
      }
   }

   /**
    * Advertise the bound endpoint, user friendly: the Local URL always and
    * the Network URL when the bind covers external interfaces. Call it from
    * an `Events::ServerAdvertised` hook to compose the project banner. Muted
    * on the Test runner and on fully muted output.
    */
   public function advertise (): void
   {
      // ? The Test runner and muted output keep the stream clean
      if ($this->Mode === Modes::Test || Display::$segments === Display::NONE) {
         return;
      }

      // ! Scheme — nodes set `socket` as `http`/`https://`/`ws`...; normalize
      $scheme = rtrim($this->socket ?? 'tcp', ':/');
      $host = $this->host ?? '0.0.0.0';
      $port = $this->port ?? 0;

      // ? Specific bind — a single address line
      if ($host !== '0.0.0.0' && $host !== '::') {
         $this->Logger->log(info: "  Listening on @#cyan:{$scheme}://{$host}:{$port}@;@.;");

         return;
      }

      // @ Wildcard bind — Local plus the machine Network address
      $this->Logger->log(info: "  Local:   @#cyan:{$scheme}://localhost:{$port}@;@.;");
      if ($this->network !== null) {
         $this->Logger->log(info: "  Network: @#cyan:{$scheme}://{$this->network}:{$port}@;@.;");
      }
   }

   /** Acknowledge complete daemon startup to the waiting launcher. */
   protected function announce (): void
   {
      if (is_resource($this->daemonReady) === false) {
         return;
      }

      $written = fwrite($this->daemonReady, 'ready');
      fclose($this->daemonReady);
      $this->daemonReady = null;
      if ($written !== 5) {
         $this->Logger->log(error: '@\;Daemon readiness acknowledgement failed.@\;');
         $this->stop();
      }
   }

   protected function daemonize (): void
   {
      $this->Status = Status::Running;

      $this->Logger->log(info: 'Running in Daemon mode (no UI)...@.;');

      // @ Daemon master loop (Status changes via signal handlers).
      //   Child exits are reaped exclusively by the SIGCHLD handler
      //   (`recover()` drains every reapable child) — a raw waitpid here
      //   would RACE it and could steal a worker exit, losing the refork.
      while ($this->Status === Status::Running) { // @phpstan-ignore identical.alwaysTrue
         pcntl_signal_dispatch();

         // @ Master supervision tick (node overrides)
         $this->tick();

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
      //   Child exits are reaped exclusively by the SIGCHLD handler
      //   (`recover()` drains every reapable child) — a raw waitpid here
      //   would RACE it and could steal a worker exit, losing the refork.
      while ($this->Status === Status::Running) { // @phpstan-ignore identical.alwaysTrue
         pcntl_signal_dispatch();

         // @ Master supervision tick (node overrides)
         $this->tick();

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

      // ! readline() blocks the master and used to suspend Auto-TLS renewal
      //   indefinitely while the operator was idle. Its callback API keeps
      //   line editing/history while letting this loop dispatch signals and
      //   run tick() every 500ms.
      $this->Commands->prepare();
      $input = null;
      $available = false;
      $installed = false;
      $Install = static function () use (&$input, &$available, &$installed): void {
         readline_callback_handler_install(
            '>_: ',
            static function (null|string $line) use (&$input, &$available): void {
               $input = $line;
               $available = true;
            }
         );
         $installed = true;
      };
      $Install();

      try {
         while ($this->Mode === Modes::Interactive && $this->Status === Status::Running) {
            pcntl_signal_dispatch();
            $this->tick();

            $read = [STDIN];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, 0, 500000);
            if ($selected > 0) {
               readline_callback_read_char();
            }
            if ($available === false) {
               continue;
            }

            readline_callback_handler_remove();
            $installed = false;
            $available = false;

            // Ctrl-D/EOF means stop, rather than spinning forever on EOF.
            if ($input === null) {
               $this->stop();
               break;
            }

            $interact = $this->Commands->execute($input);
            $input = null;
            $this->Logger->log(debug: '@\;');
            if ($interact === false) {
               usleep(100000 * $this->workers);
            }

            if ($this->Mode === Modes::Interactive && $this->Status === Status::Running) {
               $Install();
            }
         }
      }
      finally {
         if ($installed) {
            readline_callback_handler_remove();
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
         [$columns, $lines] = Screen::measure();

         Terminal::$width = $columns;
         Terminal::$height = $lines;
      });

      $Viewer = new LogsViewer($Input, $Output);

      // @ Loop
      while ($this->Mode === Modes::Monitor && $this->Status === Status::Running) {
         // @ Dispatch pending signals (reforks, reload, shutdown, resize)
         pcntl_signal_dispatch();

         // @ Master supervision tick (node overrides)
         $this->tick();

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

   /**
    * Replace the SSL context options of the live listening socket
    * (hot certificate swap).
    *
    * `stream_socket_enable_crypto()` reads the listening socket's context
    * at every handshake, so subsequent accepted connections present the new
    * credentials; established connections keep the old ones until they
    * reconnect. In the master (no bound socket) only the stored
    * configuration is synchronized.
    *
    * @param array<string,mixed> $secure New SSL stream-context options.
    * @param null|array<string,mixed> $hashes Expected
    *        SHA-256 digests from a fully validated credential generation.
    */
   public function swap (array $secure, null|array $hashes = null): bool
   {
      if (
         $hashes !== null
         && (
            is_string($hashes['certificate'] ?? null) === false
            || array_key_exists('key', $hashes) === false
            || (($hashes['key'] ?? null) !== null && is_string($hashes['key']) === false)
         )
      ) {
         return false;
      }
      /** @var null|array{certificate:string,key:null|string} $hashes */

      // ! The validated store path is never installed directly. PHP retains
      //   `local_cert`/`local_pk` as pathnames and opens them again for future
      //   handshakes, so replacing a store file after ACK would otherwise
      //   replace the live identity without another swap. Seal exact bounded
      //   bytes into a private, generation-local artifact and keep its
      //   read-only descriptors alive for the complete local generation.
      $sealed = null;
      if (is_string($secure['local_cert'] ?? null)) {
         $sealed = $this->seal($secure, $hashes);
         if ($sealed === null) {
            return false;
         }
         $secure = $sealed['secure'];
      }

      // ? Master / unbound socket: synchronize only after validating the
      //   exact bytes selected by the generation snapshot above.
      if (is_resource($this->Socket ?? null) === false) { // @phpstan-ignore nullCoalesce.property
         $this->secure = $secure;
         if (isset(self::$context) === false) {
            self::$context = [];
         }
         self::$context['ssl'] = $secure;
         $previous = $this->credential;
         $this->credential = $sealed['credential'] ?? null;
         $this->release($previous);

         return true;
      }

      // @ Apply on the live listening socket in ONE call — the stored
      //   configuration only advances when the application landed
      $previousOptions = stream_context_get_options($this->Socket)['ssl'] ?? [];
      if (stream_context_set_options($this->Socket, ['ssl' => $secure]) === false) { // @phpstan-ignore identical.alwaysFalse (defensive: the stub pins `true`, the engine may still refuse)
         $this->release($sealed['credential'] ?? null);
         return false;
      }
      $applied = stream_context_get_options($this->Socket)['ssl'] ?? null;
      if (
         is_array($applied) === false
         || ($applied['local_cert'] ?? null) !== ($secure['local_cert'] ?? null)
         || ($applied['local_pk'] ?? null) !== ($secure['local_pk'] ?? null)
      ) {
         stream_context_set_options($this->Socket, ['ssl' => $previousOptions]);
         $this->release($sealed['credential'] ?? null);
         return false;
      }

      // @ Synchronize the stored configuration
      $this->secure = $secure;
      self::$context['ssl'] = $secure;
      $previous = $this->credential;
      $this->credential = $sealed['credential'] ?? null;
      $this->release($previous);

      // :
      return true;
   }

   /**
    * Copy, verify and probe one credential into a private per-process
    * artifact. Reads use the same caps as the certificate store.
    *
    * @param array<string,mixed> $secure
    * @param null|array{certificate:string,key:null|string} $hashes
    * @return null|array{
    *    secure:array<string,mixed>,
    *    credential:array{directory:string,files:array<int,string>,handles:array<int,resource>}
    * }
    */
   private function seal (array $secure, null|array $hashes): null|array
   {
      $certificate = $secure['local_cert'] ?? null;
      if (is_string($certificate) === false) {
         return null;
      }
      $leaf = @file_get_contents($certificate, false, null, 0, 1048577);
      if (is_string($leaf) === false || strlen($leaf) > 1048576) {
         return null;
      }

      $key = $secure['local_pk'] ?? null;
      if ($key !== null && is_string($key) === false) {
         return null;
      }
      $private = is_string($key)
         ? @file_get_contents($key, false, null, 0, 65537)
         : $leaf;
      if (
         is_string($private) === false
         || (is_string($key) && strlen($private) > 65536)
      ) {
         return null;
      }
      if (
         $hashes !== null
         && (
            hash('sha256', $leaf) !== $hashes['certificate']
            || (is_string($key) && hash('sha256', $private) !== $hashes['key'])
            || (is_string($key) === false && $hashes['key'] !== null)
         )
      ) {
         return null;
      }

      $passphrase = $secure['passphrase'] ?? null;
      $pair = is_string($passphrase) && $passphrase !== ''
         ? [$private, $passphrase]
         : $private;
      if (openssl_x509_check_private_key($leaf, $pair) === false) {
         return null;
      }

      try {
         $this->sweep();
         $directory = rtrim(sys_get_temp_dir(), '/')
            . '/bootgly-tls-' . posix_getpid() . '-' . bin2hex(random_bytes(16));
      }
      catch (Throwable) {
         return null;
      }
      if (mkdir($directory, 0700) === false) {
         return null;
      }

      $files = ["{$directory}/certificate.pem"];
      $Handles = [];
      $CertificateHandle = $this->persist($files[0], $leaf);
      if (is_resource($CertificateHandle) === false) {
         $this->release(['directory' => $directory, 'files' => $files, 'handles' => []]);
         return null;
      }
      $Handles[] = $CertificateHandle;
      $sealed = $secure;
      $sealed['local_cert'] = $files[0];

      if (is_string($key)) {
         $files[] = "{$directory}/private-key.pem";
         $KeyHandle = $this->persist($files[1], $private);
         if (is_resource($KeyHandle) === false) {
            $this->release(['directory' => $directory, 'files' => $files, 'handles' => $Handles]);
            return null;
         }
         $Handles[] = $KeyHandle;
         $sealed['local_pk'] = $files[1];
      }

      if (chmod($directory, 0500) === false || $this->probe($sealed, $leaf) === false) {
         $this->release(['directory' => $directory, 'files' => $files, 'handles' => $Handles]);
         return null;
      }

      return [
         'secure' => $sealed,
         'credential' => [
            'directory' => $directory,
            'files' => $files,
            'handles' => $Handles
         ]
      ];
   }

   /** Remove private credential artifacts left by dead local processes. */
   private function sweep (): void
   {
      $base = rtrim(sys_get_temp_dir(), '/') . '/';
      foreach (glob("{$base}bootgly-tls-*") ?: [] as $directory) {
         $name = basename($directory);
         if (
            preg_match('/^bootgly-tls-(\d+)-[a-f0-9]{32}$/', $name, $matches) !== 1
            || is_link($directory)
            || is_dir($directory) === false
         ) {
            continue;
         }
         $metadata = @lstat($directory);
         if (is_array($metadata) === false || $metadata['uid'] !== posix_getuid()) {
            continue;
         }

         $PID = (int) $matches[1];
         $status = @file_get_contents("/proc/{$PID}/status", false, null, 0, 4097);
         if (
            (is_string($status) && preg_match('/^State:\s+Z/m', $status) !== 1)
            || (is_string($status) === false && posix_kill($PID, 0))
         ) {
            continue;
         }

         $entries = @scandir($directory);
         if ($entries === false) {
            continue;
         }
         $files = [];
         $safe = true;
         foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            if ($entry !== 'certificate.pem' && $entry !== 'private-key.pem') {
               $safe = false;
               break;
            }
            $files[] = "{$directory}/{$entry}";
         }
         if ($safe === false) {
            continue;
         }

         @chmod($directory, 0700);
         foreach ($files as $file) {
            @unlink($file);
         }
         @rmdir($directory);
      }
   }

   /**
    * Create one complete read-only artifact and retain a read descriptor.
    *
    * @return resource|false
    */
   private function persist (string $file, string $contents)
   {
      // 0666 & ~0266 = 0400. The writable descriptor can still receive the
      // verified bytes, while no pathname-based chmod race is introduced.
      $previousMask = umask(0266);
      try {
         $Handle = @fopen($file, 'x+b');
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         return false;
      }

      $complete = false;
      try {
         $length = strlen($contents);
         $offset = 0;
         while ($offset < $length) {
            $written = fwrite($Handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
               break;
            }
            $offset += $written;
         }
         $complete = $offset === $length
            && fflush($Handle)
            && (!function_exists('fsync') || fsync($Handle));
      }
      finally {
         fclose($Handle);
      }

      if ($complete === false) {
         @unlink($file);
         return false;
      }

      return @fopen($file, 'rb');
   }

   /**
    * Release one superseded per-process credential artifact.
    *
    * @param null|array{directory:string,files:array<int,string>,handles:array<int,resource>} $credential
    */
   private function release (null|array $credential): void
   {
      if ($credential === null) {
         return;
      }
      foreach ($credential['handles'] as $Handle) {
         is_resource($Handle) && fclose($Handle);
      }
      @chmod($credential['directory'], 0700);
      foreach ($credential['files'] as $file) {
         @chmod($file, 0600);
         @unlink($file);
      }
      @rmdir($credential['directory']);
   }

   /**
    * Complete a local loopback TLS handshake with the candidate context.
    * This proves OpenSSL can load and present the selected credentials; a
    * successful context mutation alone does not prove the next handshake.
    *
    * @param array<string,mixed> $secure
    */
   private function probe (array $secure, string $certificate): bool
   {
      $ServerContext = stream_context_create(['ssl' => $secure]);
      $ClientContext = stream_context_create([
         'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'capture_peer_cert' => true
         ]
      ]);
      $Listener = @stream_socket_server(
         'tcp://127.0.0.1:0',
         $code,
         $message,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         $ServerContext
      );
      if ($Listener === false) {
         return false;
      }

      $Server = false;
      $Client = false;
      try {
         $address = stream_socket_get_name($Listener, false);
         if (is_string($address) === false || $address === '') {
            return false;
         }
         $Client = @stream_socket_client(
            "tcp://{$address}",
            $code,
            $message,
            2.0,
            STREAM_CLIENT_CONNECT,
            $ClientContext
         );
         $Server = @stream_socket_accept($Listener, 2.0);
         if ($Client === false || $Server === false) {
            return false;
         }

         stream_set_blocking($Server, false);
         stream_set_blocking($Client, false);

         $served = 0;
         $connected = 0;
         $deadline = microtime(true) + 2.0;
         while (microtime(true) < $deadline) {
            if ($served !== true) {
               $served = @stream_socket_enable_crypto(
                  $Server,
                  true,
                  STREAM_CRYPTO_METHOD_TLS_SERVER
               );
               if ($served === false) {
                  return false;
               }
            }
            if ($connected !== true) {
               $connected = @stream_socket_enable_crypto(
                  $Client,
                  true,
                  STREAM_CRYPTO_METHOD_TLS_CLIENT
               );
               if ($connected === false) {
                  return false;
               }
            }
            if ($served === true && $connected === true) {
               break;
            }
            usleep(1000);
         }
         if ($served !== true || $connected !== true) {
            return false;
         }

         $Peer = stream_context_get_options($Client)['ssl']['peer_certificate'] ?? null;
         $expected = openssl_x509_fingerprint($certificate, 'sha256');
         $actual = $Peer !== null
            ? openssl_x509_fingerprint($Peer, 'sha256')
            : false;

         return is_string($expected) && $expected !== '' && $actual === $expected;
      }
      finally {
         fclose($Listener);
         is_resource($Server) && fclose($Server);
         is_resource($Client) && fclose($Client);
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

            foreach ($this->Listeners as $Listener) {
               fclose($Listener);
            }
            $this->Listeners = [];

            // @ Clean all per-project state files
            $this->Process->State->clean();

            // ? Test servers share the process with the native suite runner.
            //   Stopping one must return control instead of terminating every
            //   suite that follows it.
            if ($this->Mode === Modes::Test) {
               break;
            }

            $CI_CD = Environment::get('GITHUB_ACTIONS')
               || Environment::get('TRAVIS')
               || Environment::get('CIRCLECI')
               || Environment::get('GITLAB_CI')
               || Environment::get('GIT_EXEC_PATH'); // Git Hooks?
            if ($this->Mode === Modes::Foreground && $CI_CD) {
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

      // @ Leave the accept set and close this worker's SO_REUSEPORT descriptor:
      //   no new connection is routed here; established sockets remain open.
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
    * Node-owned listeners transferred together with the TCP listener pool.
    * Keys are authenticated metadata; values must be listening stream sockets.
    *
    * @return array<string,resource>
    */
   protected function export (): array
   {
      return [];
   }

   /**
    * Validate and adopt node-owned descriptors received by SCM_RIGHTS.
    *
    * @param array<string,resource> $Resources
    */
   protected function import (array $Resources): bool
   {
      return $Resources === [];
   }

   /** Validate that a transferred descriptor is a listener on the exact port. */
   protected function validate (mixed $Listener, int $port): bool
   {
      if (is_resource($Listener) === false) {
         return false;
      }
      $address = @stream_socket_get_name($Listener, false);
      $separator = is_string($address) ? strrpos($address, ':') : false;
      if (
         $separator === false
         || is_numeric(substr($address, $separator + 1)) === false
         || (int) substr($address, $separator + 1) !== $port
      ) {
         return false;
      }
      try {
         $Socket = socket_import_stream($Listener);
      }
      catch (Throwable) {
         return false;
      }
      if ($Socket === false) {
         return false;
      }

      return @stream_socket_get_name($Listener, true) === false
         && socket_get_option($Socket, SOL_SOCKET, SO_TYPE) === SOCK_STREAM;
   }

   /**
    * Start a bounded same-UID relay that owns the listeners across pcntl_exec.
    * The relay has no elevated privilege: it only retains already-bound FDs.
    */
   private function relay (): bool
   {
      $Lock = $this->Process->State->export();
      if (is_resource($Lock) === false) {
         return false;
      }

      // ! `state.lock` is transport-owned and reserved before node hooks are
      //   consulted. A node cannot replace/collide with the instance lock.
      $Resources = ['state.lock' => $Lock];
      foreach ($this->Listeners as $index => $Listener) {
         if (is_resource($Listener) === false) {
            return false;
         }
         $Resources["listener.{$index}"] = $Listener;
      }
      foreach ($this->export() as $name => $Resource) {
         if (
            preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name) !== 1
            || isset($Resources[$name])
            || is_resource($Resource) === false
         ) {
            return false;
         }
         $Resources[$name] = $Resource;
      }
      if (count($Resources) > self::RELOAD_HANDOFF_MAX_RESOURCES) {
         return false;
      }

      try {
         $token = bin2hex(random_bytes(32));
      }
      catch (Throwable) {
         return false;
      }
      $master = posix_getpid();
      $prefix = substr($token, 0, 16);
      $directory = sys_get_temp_dir() . "/bootgly-reload-{$master}-{$prefix}";
      if (mkdir($directory, 0700) === false) {
         return false;
      }
      if (chmod($directory, 0700) === false) {
         @rmdir($directory);
         return false;
      }
      $path = "{$directory}/handoff.sock";
      $code = 0;
      $message = '';
      $Relay = @stream_socket_server(
         "unix://{$path}",
         $code,
         $message,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
      );
      if ($Relay === false || chmod($path, 0600) === false) {
         is_resource($Relay) && fclose($Relay);
         @unlink($path);
         @rmdir($directory);

         return false;
      }

      $PID = pcntl_fork();
      if ($PID < 0) {
         fclose($Relay);
         @unlink($path);
         @rmdir($directory);

         return false;
      }
      if ($PID > 0) {
         fclose($Relay);
         putenv(self::RELOAD_HANDOFF_PATH . '=' . $path);
         putenv(self::RELOAD_HANDOFF_TOKEN . '=' . $token);
         putenv(self::RELOAD_HANDOFF_PID . '=' . $PID);

         return true;
      }

      // # Relay child — retain the inherited State descriptor: it is the sole
      //   continuous lock bridge after the old master detaches before exec.
      //   Reset inherited handlers and unrelated launcher state only.
      if (is_resource($this->daemonReady)) {
         fclose($this->daemonReady);
         $this->daemonReady = null;
      }
      foreach ([
         SIGALRM, SIGUSR1, SIGURG, SIGHUP, SIGINT, SIGQUIT, SIGTERM,
         SIGTSTP, SIGCONT, SIGUSR2, SIGCHLD, SIGIOT, SIGIO
      ] as $signal) {
         pcntl_signal($signal, SIG_DFL);
      }
      // ! Forked from the SIGUSR2 dispatch: drop its inherited block mask.
      pcntl_sigprocmask(SIG_SETMASK, []);

      // ! Do not let an orphaned relay pin the stable instance lock/listeners
      //   for the full reload timeout. Poll readiness at one-second maximum
      //   intervals and verify the exact execing parent on every iteration.
      stream_set_blocking($Relay, false);
      $Client = false;
      $deadline = microtime(true) + self::$drainTimeout + 15.0;
      do {
         if (posix_getppid() !== $master) {
            break;
         }
         $remaining = $deadline - microtime(true);
         if ($remaining <= 0) {
            break;
         }
         $wait = min(1.0, $remaining);
         $seconds = (int) $wait;
         $microseconds = (int) (($wait - $seconds) * 1_000_000);
         $read = [$Relay];
         $write = null;
         $except = null;
         $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
         if ($selected === false) {
            usleep(10000);
            continue;
         }
         if ($selected === 1) {
            $Client = @stream_socket_accept($Relay, 0);
         }
      } while ($Client === false);
      fclose($Relay);
      if ($Client === false || posix_getppid() !== $master) {
         is_resource($Client) && fclose($Client);
         @unlink($path);
         @rmdir($directory);
         exit(1);
      }
      stream_set_blocking($Client, true);
      stream_set_timeout($Client, 5);
      $received = '';
      while (strlen($received) < 65 && str_contains($received, "\n") === false) {
         $chunk = fread($Client, 65 - strlen($received));
         if ($chunk === false || $chunk === '') {
            break;
         }
         $received .= $chunk;
      }
      if ($received !== "{$token}\n") {
         fclose($Client);
         @unlink($path);
         @rmdir($directory);
         exit(1);
      }
      try {
         $Control = socket_import_stream($Client);
      }
      catch (Throwable) {
         fclose($Client);
         @unlink($path);
         @rmdir($directory);
         exit(1);
      }
      if ($Control === false) {
         fclose($Client);
         @unlink($path);
         @rmdir($directory);
         exit(1);
      }
      $payload = json_encode([
         'resources' => array_keys($Resources),
         // Bound endpoint and demotion identity are immutable across a hot
         // reload. Changing either requires a full stop/start so the new
         // privileged bind and ownership transition can be performed.
         'contract' => [
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'group' => $this->group,
            'mode' => $this->Mode->value
         ]
      ]);
      $sent = is_string($payload) ? @socket_sendmsg($Control, [
         'iov' => [$payload],
         'control' => [[
            'level' => SOL_SOCKET,
            'type' => SCM_RIGHTS,
            'data' => array_values($Resources)
         ]]
      ], 0) : false;
      $ack = $sent === strlen((string) $payload) ? fread($Client, 2) : false;
      fclose($Client);
      @unlink($path);
      @rmdir($directory);
      exit($ack === 'ok' ? 0 : 1);
   }

   /**
    * Receive a reload relay when present: null = ordinary launch, true =
    * validated reload, false = advertised handoff failed closed.
    */
   private function receive (): null|bool
   {
      $path = getenv(self::RELOAD_HANDOFF_PATH);
      $token = getenv(self::RELOAD_HANDOFF_TOKEN);
      $relayPID = getenv(self::RELOAD_HANDOFF_PID);
      foreach ([
         self::RELOAD_HANDOFF_PATH,
         self::RELOAD_HANDOFF_TOKEN,
         self::RELOAD_HANDOFF_PID
      ] as $name) {
         putenv($name);
      }
      if ($path === false && $token === false && $relayPID === false) {
         return null;
      }
      if (
         is_string($path) === false || $path === ''
         || is_string($token) === false || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
         || is_string($relayPID) === false
         || preg_match('/^[1-9][0-9]*$/D', $relayPID) !== 1
         || (int) $relayPID < 2
      ) {
         return false;
      }

      $Client = @stream_socket_client("unix://{$path}", $code, $message, 5.0);
      if ($Client === false) {
         return false;
      }
      stream_set_blocking($Client, true);
      stream_set_timeout($Client, 5);
      if (fwrite($Client, "{$token}\n") !== 65) {
         fclose($Client);
         return false;
      }
      try {
         $Control = socket_import_stream($Client);
      }
      catch (Throwable) {
         fclose($Client);
         return false;
      }
      if ($Control === false) {
         fclose($Client);
         return false;
      }
      $Message = [
         'name' => [],
         'buffer_size' => 16384,
         'controllen' => socket_cmsg_space(
            SOL_SOCKET,
            SCM_RIGHTS,
            self::RELOAD_HANDOFF_MAX_RESOURCES
         )
      ];
      $received = @socket_recvmsg($Control, $Message, 0);
      if (is_int($received) === false || $received < 2) {
         fclose($Client);
         return false;
      }
      $payload = implode('', is_array($Message['iov'] ?? null) ? $Message['iov'] : []);
      $decoded = json_decode($payload, true);
      $names = is_array($decoded) ? ($decoded['resources'] ?? null) : null;
      $contract = is_array($decoded) ? ($decoded['contract'] ?? null) : null;
      $Descriptors = [];
      foreach (is_array($Message['control'] ?? null) ? $Message['control'] : [] as $control) {
         if (
            is_array($control)
            && ($control['level'] ?? null) === SOL_SOCKET
            && ($control['type'] ?? null) === SCM_RIGHTS
            && is_array($control['data'] ?? null)
         ) {
            foreach ($control['data'] as $Descriptor) {
               // ! The sockets extension maps a received socket fd to a Socket
               //   object while a non-socket fd (the lock) arrives as a plain
               //   stream. Normalize to streams for the validations below.
               if ($Descriptor instanceof Socket) {
                  $Descriptor = socket_export_stream($Descriptor);
               }
               $Descriptors[] = $Descriptor;
            }
         }
      }
      if (
         is_array($names) === false
         || $contract !== [
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'group' => $this->group,
            'mode' => $this->Mode->value
         ]
         || count($names) !== count($Descriptors)
         || count($names) < 1
         || count($names) > self::RELOAD_HANDOFF_MAX_RESOURCES
      ) {
         fclose($Client);
         foreach ($Descriptors as $Descriptor) {
            is_resource($Descriptor) && fclose($Descriptor);
         }
         return false;
      }
      $Resources = [];
      foreach ($names as $index => $name) {
         if (
            is_string($name) === false
            || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name) !== 1
            || isset($Resources[$name])
            || is_resource($Descriptors[$index]) === false
         ) {
            fclose($Client);
            foreach ($Descriptors as $Descriptor) {
               is_resource($Descriptor) && fclose($Descriptor);
            }
            return false;
         }
         $Resources[$name] = $Descriptors[$index];
      }

      $Listeners = [];
      foreach ($Resources as $name => $Resource) {
         if (preg_match('/^listener\.(\d+)$/D', $name, $matches) === 1) {
            $Listeners[(int) $matches[1]] = $Resource;
            unset($Resources[$name]);
         }
      }
      $StateLock = $Resources['state.lock'] ?? null;
      unset($Resources['state.lock']);
      if (is_resource($StateLock) === false) {
         fclose($Client);
         foreach ([...$Listeners, ...$Resources] as $Descriptor) {
            is_resource($Descriptor) && fclose($Descriptor);
         }
         return false;
      }
      ksort($Listeners);
      if (
         $Listeners === []
         || array_keys($Listeners) !== range(0, count($Listeners) - 1)
      ) {
         fclose($Client);
         foreach ([$StateLock, ...$Listeners, ...$Resources] as $Descriptor) {
            is_resource($Descriptor) && fclose($Descriptor);
         }
         return false;
      }
      foreach ($Listeners as $Listener) {
         if ($this->validate($Listener, $this->port ?? 0) === false) {
            fclose($Client);
            foreach ([$StateLock, ...$Listeners, ...$Resources] as $Descriptor) {
               is_resource($Descriptor) && fclose($Descriptor);
            }
            return false;
         }
      }
      // A reload may lower the worker count. Descriptors with no consumer
      // must be closed before the fork; retaining an unused SO_REUSEPORT
      // queue in the master would black-hole a share of incoming connections.
      $needed = max(1, $this->workers);
      if (count($Listeners) > $needed) {
         foreach (array_slice($Listeners, $needed) as $Unused) {
            fclose($Unused);
         }
         $Listeners = array_slice($Listeners, 0, $needed);
      }

      self::$context = [
         'socket' => [
            'backlog' => 102400,
            'so_reuseport' => true,
            'ipv6_v6only' => false
         ]
      ];
      if ( ! empty($this->secure) ) {
         self::$context['ssl'] = $this->secure;
      }
      foreach ($Listeners as $Listener) {
         if ($this->prepare($Listener) === false) {
            fclose($Client);
            foreach ([$StateLock, ...$Listeners, ...$Resources] as $Descriptor) {
               is_resource($Descriptor) && fclose($Descriptor);
            }
            return false;
         }
      }
      if ($this->Process->State->adopt($StateLock) === false) {
         fclose($Client);
         foreach ([$StateLock, ...$Listeners, ...$Resources] as $Descriptor) {
            is_resource($Descriptor) && fclose($Descriptor);
         }
         return false;
      }
      if ($this->import($Resources) === false) {
         $this->Process->State->detach();
         fclose($Client);
         foreach ([...$Listeners, ...$Resources] as $Descriptor) {
            is_resource($Descriptor) && fclose($Descriptor);
         }
         return false;
      }

      $this->Listeners = array_values($Listeners);
      $acknowledged = fwrite($Client, 'ok') === 2;
      fclose($Client);
      if ($acknowledged === false) {
         $this->Process->State->detach();
         foreach ($this->Listeners as $Listener) {
            fclose($Listener);
         }
         $this->Listeners = [];
         foreach ($Resources as $Resource) {
            is_resource($Resource) && fclose($Resource);
         }
         return false;
      }

      // The relay exits immediately after ACK; reap it before signal handlers
      // are installed so the fresh master never begins with a zombie child.
      $PID = (int) $relayPID;
      $deadline = microtime(true) + 2.0;
      do {
         $reaped = pcntl_waitpid($PID, $status, WNOHANG);
         if ($reaped === $PID || $reaped === -1) {
            break;
         }
         usleep(10000);
      } while (microtime(true) < $deadline);
      if ($reaped === 0) {
         $process = @file_get_contents("/proc/{$PID}/status");
         if (
            is_string($process)
            && preg_match('/^PPid:\t(\d+)$/m', $process, $matches) === 1
            && (int) $matches[1] === posix_getpid()
         ) {
            posix_kill($PID, SIGKILL);
            pcntl_waitpid($PID, $status);
         }
      }

      return true;
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

      // ! Establish the authenticated descriptor relay BEFORE draining a
      //   single worker. Failure leaves the current service fully intact.
      if ($this->relay() === false) {
         $this->Logger->log(error: '@\;Reload aborted: could not establish the listener handoff relay.@\;');
         return;
      }

      // ! Mark reloading so recover() does not refork the workers we drain.
      $this->Process->reloading = true;

      // @ Drain every worker gracefully (SIGQUIT → worker drain()), then reap.
      //   Stragglers past the budget are force-killed by terminate()'s SIGKILL.
      $this->Process->Children->terminate(timeout: self::$drainTimeout + 5, signal: SIGQUIT);

      // The relay is now the sole bridge across exec. Close the old master's
      // copies so no inaccessible descriptor leaks into the fresh PHP image.
      foreach ($this->Listeners as $Listener) {
         fclose($Listener);
      }
      $this->Listeners = [];
      foreach ($this->export() as $Resource) {
         fclose($Resource);
      }

      // ! Close only the old master's duplicate. LOCK_UN would affect the
      //   relay's shared open-file description and recreate the very startup
      //   race this handoff prevents. The fresh image adopts the descriptor;
      //   Commands::erase() and State::save() supersede old state in place.
      $this->Process->State->detach();

      // @ Restore the launch directory (a daemon may have chdir'd to /), then
      //   replace this process image with a fresh one. pcntl_exec keeps the PID.
      if (self::$directory !== '') {
         @chdir(self::$directory);
      }
      // ! reload() runs inside the SIGUSR2 dispatch, whose full block mask
      //   SURVIVES execve — without a reset the fresh master would be born
      //   deaf to every lifecycle signal (SIGTERM/SIGCHLD/SIGUSR2).
      pcntl_sigprocmask(SIG_SETMASK, []);
      pcntl_exec(self::$binary, self::$argv, getenv());

      // ? exec only returns on failure — workers are already gone, so surface the
      //   error, tombstone the stale topology and exit rather than linger as a
      //   workerless master. detach() made clean() a no-unlock operation here.
      $this->Process->State->clean();
      $this->Logger->log(error: 'Reload failed: could not re-exec the master.@\;');
      exit(1);
   }

   public function __destruct ()
   {
      foreach ($this->Listeners as $Listener) {
         is_resource($Listener) && fclose($Listener);
      }
      $this->Listeners = [];

      $this->release($this->credential);
      $this->credential = null;

      // @ Reset Opcache?
      /*
      if (function_exists('opcache_reset') && $this->Process->level === 'master') {
         opcache_reset();
      }
      */
   }
}
