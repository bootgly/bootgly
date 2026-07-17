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
use const STDERR;
use const STDIN;
use const STDOUT;
use const STREAM_SERVER_BIND;
use const WNOHANG;
use const WUNTRACED;
use function array_merge;
use function array_slice;
use function chdir;
use function count;
use function defined;
use function explode;
use function fclose;
use function file;
use function fopen;
use function get_included_files;
use function getcwd;
use function getenv;
use function is_file;
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
use function rtrim;
use function str_contains;
use function stream_context_create;
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
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Process;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\API\Environment;
use Bootgly\API\Environments;
use Bootgly\API\Projects;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Endpoints\Servers;
use Bootgly\WPI\Endpoints\Servers\Decoder;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Event;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Commands;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;


class UDP_Server_CLI implements Servers
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
   protected null|string $user = null;
   protected null|string $group = null;
   // # Mode
   public Modes $Mode;
   // # Verbosity

   // * Data
   // # SAPI
   public static null|string $Application = null;
   public static null|Decoder $Decoder = null;
   public static null|Encoder $Encoder = null;
   /** @var array<string,true> */
   protected array $Events = [];

   // * Metadata
   // # State
   protected int $started = 0;
   protected bool $daemonized = false;
   // # Reload — launch command captured at start(), replayed by reload() via
   //   pcntl_exec so the master re-execs into a fresh image (same PID). UDP is
   //   connectionless, so reload has no in-flight connections to drain.
   protected static string $binary = '';
   /** @var array<int,string> */
   protected static array $argv = [];
   protected static string $directory = '';
   // # Socket
   protected null|string $socket;
   /** @var array<array<bool|int|string>|string> */
   public static array $context;
   // # Status
   protected Status $Status = Status::Booting;

   // /
   protected Connections $Connections;


   public function __construct (Modes $Mode = Modes::Monitor)
   {
      if (PHP_SAPI !== 'cli') {
         return;
      }


      // * Config
      // $domain
      $this->socket = 'udp://';
      $this->host = null;
      $this->port = null;
      // $workers
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
      $this->Logger = new Logger(channel: 'UDP.Server.CLI');
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
    * Configure the UDP Server.
    *
    * @param string $host Domain name or IP address
    * @param int $port Port number
    * @param int $workers Number of workers
    * @param string|null $user User to drop privileges to after socket binding
    * @param string|null $group Group to drop privileges to after socket binding
    *
    * @return self
    */
   public function configure (
      string $host,
      int $port,
      int $workers,
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

      $this->user = $user;
      $this->group = $group;

      return $this;
   }
   /**
    * Register an event handler for the UDP Server.
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
      if ($Event instanceof UDP_Server_CLI\Events === false) {
         throw new InvalidArgumentException('Invalid UDP Server event.');
      }

      if (isset($this->Events[$Event->value])) {
         throw new InvalidArgumentException("The event '{$Event->value}' is already registered.");
      }

      $this->Events[$Event->value] = true;

      // @
      // on Datagram
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
         // @ stop()
         case SIGHUP:  // 1
         case SIGINT:  // 2 (CTRL + C)
         case SIGQUIT: // 3
         case SIGTERM: // 15
            $this->stop();
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
         // @ reload() — master-only: graceful re-exec (replace the master image so
         //   all code reloads, same PID). UDP is connectionless, so there is no
         //   in-flight drain; workers are stopped and the master re-execs.
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
                     // @ Fork hygiene — drop Timer tasks inherited from the parent: POSIX
                     //   clears pending alarms on fork, so inherited tasks can never
                     //   fire here, yet a non-empty inherited task map would stop the
                     //   next `Timer::add()` from arming its alarm — leaving every
                     //   timer this worker installs silently dead.
                     Timer::del();

                     $this->Process->Children->push($this->Process->id, $deadIndex);
                     Process::$index = $deadIndex + 1;

                     $this->Process->title = 'Bootgly_UDP_Server_CLI: child process (Worker #' . Process::$index . ')';

                     Display::show(Display::MESSAGE, Display::TIMESTAMP, Display::CHANNEL, Display::SEVERITY);

                     $this->instance();

                     self::$Event->add(
                        $this->Socket,
                        Select::EVENT_READ,
                        $this->Connections->Router
                     );
                     self::$Event->loop();

                     $this->stop();

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
      //   reloaded code, same PID). get_included_files()[0] is the absolute entry.
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

      $this->Logger->log(notice: '@\;Starting Server...');

      // @ Boot Server API
      if (self::$Application) {
         self::$Application::boot(Environments::fetch(BOOTGLY_ENVIRONMENT));
      }
      else if (isSet(SAPI::$Handler) === false) {
         $this->Logger->log(error: '@\;No handler defined. Call on(Events::DatagramReceive, ...) before start().@\;');
         exit(1);
      }

      // ! Process
      // ? Late instance guard: qualify the state files with the bound port and
      //   take a non-blocking lock — the bind itself uses SO_REUSEPORT, so two
      //   Bootgly servers CAN share a port; this lock is what rejects the second.
      $State = $this->Process->State;
      $State->qualify((string) ($this->port ?? 0));
      if ($State->lock(LOCK_EX | LOCK_NB) === false) {
         $this->Logger->log(
            error: '@\;Another instance is already running on port ' . ($this->port ?? 0) . '.@\;Use `project stop <name> <port>` to stop it or start this one on another port (PORT env).@.;'
         );
         exit(1);
      }

      // ? Pre-flight: verify socket can be bound before forking workers
      $probeCode = 0;
      $probeMessage = '';
      $probeContext = stream_context_create(['socket' => ['so_reuseport' => true, 'ipv6_v6only' => false]]);
      try {
         $probeSocket = @stream_socket_server(
            'udp://' . ($this->host ?? '0.0.0.0') . ':' . ($this->port ?? 0),
            $probeCode,
            $probeMessage,
            STREAM_SERVER_BIND,
            $probeContext
         );
      }
      catch (Throwable) {
         $probeSocket = false;
      }
      if ($probeSocket === false) {
         $message = '@\;Could not bind to ' . ($this->host ?? '0.0.0.0') . ':' . ($this->port ?? 0) . ': ' . $probeMessage;
         if ($probeCode === 13 || str_contains((string) $probeMessage, 'Permission denied')) {
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

      // @ Fork process workers...
      $this->Process->fork($this->workers, instance: function (Process $Process, int $index): void {
         // @ Fork hygiene — drop Timer tasks inherited from the parent (see the
         //   SIGCHLD recovery fork): inherited tasks can never fire in the child
         //   and would stop the next `Timer::add()` from arming its alarm.
         Timer::del();

         $Process->title = 'Bootgly_UDP_Server_CLI: child process (Worker #' . Process::$index . ')';

         Display::show(Display::MESSAGE, Display::TIMESTAMP, Display::CHANNEL, Display::SEVERITY);

         // @ Create stream socket server
         $this->instance();

         // Event Loop
         self::$Event->add(
            $this->Socket,
            Select::EVENT_READ,
            $this->Connections->Router
         );
         self::$Event->loop();

         // @ Close stream socket server
         $this->stop();
      });

      // @ Set master process title
      $this->Process->title = 'Bootgly_UDP_Server_CLI: master process';

      // @ Save full process state (master + workers + host + port)
      $this->Process->State->save([
         'master'  => Process::$master,
         'workers' => $this->Process->Children->PIDs,
         'host'    => $this->host ?? '0.0.0.0',
         'port'    => $this->port ?? 0,
         'started' => time(),
         'type'    => 'WPI'
      ]);

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
         // Allows multiple bindings to a same ip:port pair, even from separate processes.
         // With UDP this lets the kernel distribute datagrams across worker processes.
         'so_reuseport' => true,

         // Overrides the OS default regarding mapping IPv4 into IPv6.
         'ipv6_v6only' => false
      ];

      // @ Create context
      $Context = stream_context_create(self::$context);

      // @ Create server socket
      try {
         $Socket = @stream_socket_server(
            'udp://' . $this->host . ':' . $this->port,
            $error_code,
            $error_message,
            STREAM_SERVER_BIND,
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
      if ($this->user === null || posix_getuid() !== 0) {
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
   }

   protected function daemonize (): void
   {
      $this->Status = Status::Running;

      $this->Logger->log(info: 'Running in Daemon mode (no UI)...');

      // @ Fork: parent returns to terminal, child becomes daemon master
      $pid = pcntl_fork();

      if ($pid === -1) {
         $this->Logger->log(error: '@\;Failed to fork daemon process!@\;');
         exit(1);
      }

      // # Parent process (CLI caller): return control to terminal
      if ($pid > 0) {
         $this->daemonized = true;
         $this->Logger->log(notice: '@\;Daemon started (PID: ' . $pid . ')@\;@.;');
         return;
      }

      // # Child process (new daemon master): become session leader
      posix_setsid();

      // @ Detach the standard descriptors from the launching terminal: pin fds
      //   0-2 on /dev/null so nothing in the daemon lineage — including the
      //   fresh image reload() re-execs, which inherits these descriptors —
      //   ever writes to the caller's TTY. The locals hold the streams open for
      //   the daemon's lifetime (this method only returns when Status leaves
      //   Running); console display is muted like the daemonized workers.
      Display::show(Display::NONE);
      fclose(STDIN);
      fclose(STDOUT);
      fclose(STDERR);
      $stdin = fopen('/dev/null', 'r');
      $stdout = fopen('/dev/null', 'w');
      $stderr = fopen('/dev/null', 'w');

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
   protected function monitoring (): void
   {
      $this->Status = Status::Running;

      $this->Logger->log(info: '@\;Entering in Monitor mode...@\;');

      // NOTE: file-change auto-reload (watch the project on disk → reload()) is a
      //   follow-up; the previous SAPI::check() watcher was a dead no-op (it watched
      //   SAPI::$production, which no project ever sets). `project reload` (SIGUSR2)
      //   is the working, canonical trigger — see reload().

      // @ Set Logger to display messages, datetime and level
      Display::show(Display::MESSAGE, Display::TIMESTAMP, Display::CHANNEL, Display::SEVERITY);

      $Output = CLI->Terminal->Output;
      $Output->Cursor->hide();
      $Output->clear();
      $this->__get('@status');

      // @ Loop
      while ($this->Mode === Modes::Monitor) {
         // @ Calls signal handlers for pending signals
         pcntl_signal_dispatch();

         // @ Suspends execution of the current process until a child has exited, or until a signal is delivered
         pcntl_wait($status, WUNTRACED);

         // @ Calls signal handlers for pending signals again
         pcntl_signal_dispatch();

         // If child is running?
         if ($status === 0) {
            // ...
         }
         else if ($status > 0) { // If a child has already exited?
            $this->Logger->log(error: '@\;Process child exited!@\;');
            $this->Process->Signals->send(SIGINT);
            break;
         }
         else if ($status === -1) { // If error ignore
            // ...
         }

         $this->__get('@status');
      }

      $Output->Cursor->show();

      // @ Enter in CLI mode
      if ($this->Mode === Modes::Interactive) {
         Timer::del(0); // @ Delete all timers
         $Output->clear();
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
         'child' => self::$Event->add($this->Socket, self::$Event::EVENT_READ, $this->Connections->Router),
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
         'child' => self::$Event->del($this->Socket, self::$Event::EVENT_READ),
         default => null
      };

      $this->Status = Status::Paused;

      return true;
   }
   public function stop (): void
   {
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
    * Graceful hot-reload (master-only): stop the workers, then re-exec this
    * master into a fresh PHP image so the whole application reloads from disk.
    * The master PID is preserved. UDP is connectionless, so there is no in-flight
    * request drain — workers are stopped and the fresh master re-binds under
    * SO_REUSEPORT. Files loaded before fork reload because the process image is
    * replaced (PHP cannot redefine already-loaded classes/closures in place).
    */
   protected function reload (): void
   {
      // ? Only the master reloads.
      if ($this->Process->level !== 'master') {
         return;
      }

      // ? Validate the captured launch command BEFORE tearing down workers.
      $entry = self::$argv[0] ?? '';
      if ($entry === '' || is_file($entry) === false) {
         $this->Logger->log(error: "Reload aborted: entry script '{$entry}' not found.@\\;");
         return;
      }

      $this->Logger->log(notice: '@\;Reloading (graceful re-exec)...@.;');

      // ! Mark reloading so recover() does not refork the workers we stop.
      $this->Process->reloading = true;

      // @ Stop the workers (connectionless — no drain), then reap.
      $this->Process->Children->terminate();

      // @ Clear per-project state files; the fresh master rewrites them on start.
      $this->Process->State->clean();

      // @ Restore the launch directory (a daemon may have chdir'd to /), then
      //   replace this process image with a fresh one. pcntl_exec keeps the PID.
      if (self::$directory !== '') {
         @chdir(self::$directory);
      }
      pcntl_exec(self::$binary, self::$argv, getenv());

      // ? exec only returns on failure.
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
