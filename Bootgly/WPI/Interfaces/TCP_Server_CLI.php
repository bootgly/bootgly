<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces;


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
use const SOL_SOCKET;
use const SOL_TCP;
use const SO_KEEPALIVE;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const TCP_NODELAY;
use const WNOHANG;
use const WUNTRACED;
use function defined;
use function fclose;
use function function_exists;
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
use function count;
use function explode;
use function register_shutdown_function;
use function rtrim;
use function socket_import_stream;
use function socket_set_option;
use function stream_context_create;
use function stream_socket_enable_crypto;
use function stream_socket_server;
use function time;
use function usleep;
use Closure;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Debugging\Shutdown;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Logging;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Process;
use Bootgly\API\Environment;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use const Bootgly\CLI;
use Bootgly\WPI\Endpoints\Servers\Decoder;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Endpoints\Servers;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Commands;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


class TCP_Server_CLI implements Servers, Logging
{
   use LoggableEscaped;


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
   protected null|array $ssl; // SSL Stream Context
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

   // * Metadata
   public const string VERSION = '0.1.2-beta';
   // # State
   protected int $started = 0;
   protected bool $daemonized = false;
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
      $this->socket = 'tcp://';
      $this->host = null;
      $this->port = null;
      // $workers
      $this->ssl = null;
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
      $processId = defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : static::class;
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

         Shutdown::debug();

         $Process->Signals->send(SIGINT, master: true, children: true);
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
         case 'ssl':
            return $this->ssl;
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
            SAPI::$Environment = Environments::Production;

            if (self::$Application) {
               self::$Application::boot(Environments::Production);
               return true;
            }

            SAPI::boot(reset: true);

            return true;
         case '@status':
            // @ Set log display none
            $display = Logger::$display;
            Logger::$display = Logger::DISPLAY_MESSAGE;

            CLI->Commands->find('status', From: $this)?->run();

            // @ Restore log display
            Logger::$display = $display;
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
    * @param array<string>|null $ssl SSL Stream Context
    * @param string|null $user User to drop privileges to after socket binding
    * @param string|null $group Group to drop privileges to after socket binding
    * 
    * @return self
    */
   public function configure (
      string $host,
      int $port,
      int $workers,
      null|array $ssl = null,
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

      $this->ssl = $ssl;

      $this->user = $user;
      $this->group = $group;

      return $this;
   }
   /**
    * Register the package handler for the TCP Server.
    *
    * @param Closure $package The package receive handler.
    *
    * @return self
    */
   public function on (Closure $package): self
   {
      // @
      SAPI::$Handler = $package;

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
         // @ reload()
         case SIGUSR2: // 12
            if ($this->Process->level === 'master') {
               $this->Process->Signals->send(SIGUSR2, master: false);
            }
            else if (self::$Application) {
               self::$Application::boot(SAPI::$Environment);
            }
            else {
               SAPI::boot(reset: true);
            }
            break;

         // @ recover()
         case SIGCHLD: // 17
            if ($this->Process->level === 'master') {
               while ($dead = $this->Process->recover()) {
                  [$deadIndex, $deadPID] = $dead;

                  $this->log("Worker #{$deadIndex} (PID: {$deadPID}) crashed, reforking...", self::LOG_WARNING_LEVEL);

                  $newPID = pcntl_fork();

                  // # Child process (new worker)
                  if ($newPID === 0) {
                     $this->Process->Children->push($this->Process->id, $deadIndex);
                     Process::$index = $deadIndex + 1;

                     $this->Process->title = 'Bootgly_WPI_Server: child process (Worker #' . Process::$index . ')';

                     Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

                     $this->instance();

                     self::$Event->add(
                        $this->Socket,
                        Select::EVENT_CONNECT,
                        true
                     );
                     self::$Event->loop();

                     $this->stop();

                     exit(0);
                  }
                  // # Master process
                  else if ($newPID > 0) {
                     $this->Process->Children->push($newPID, $deadIndex);

                     $this->log("Worker #{$deadIndex} recovered (new PID: {$newPID})", self::LOG_NOTICE_LEVEL);

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

      Logger::$display = Logger::$display === 0 ? 0 : Logger::DISPLAY_MESSAGE;

      $this->log('@\;Starting Server...', self::LOG_NOTICE_LEVEL);

      // @ Boot Server API
      if (self::$Application) {
         self::$Application::boot(Environments::Production);
      }
      else if (isSet(SAPI::$Handler) === false) {
         $this->log('@\;No handler defined. Call on(package:) before start().@\;', self::LOG_ERROR_LEVEL);
         exit(1);
      }

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
         SIGCHLD,  // recover
         SIGIOT,   // connection info
         SIGIO,    // connection stats
      ]);
      pcntl_signal(SIGPIPE, SIG_IGN, false);

      // @ Fork process workers...
      $this->Process->fork($this->workers, instance: function (Process $Process, int $index): void {
         $Process->title = 'Bootgly_WPI_Server: child process (Worker #' . Process::$index . ')';

         Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

         // @ Create stream socket server
         $this->instance();

         // Event Loop
         self::$Event->add(
            $this->Socket,
            Select::EVENT_CONNECT,
            true
         );
         self::$Event->loop();

         // @ Close stream socket server
         $this->stop();
      });

      // @ Set master process title
      $this->Process->title = 'Bootgly_WPI_Server: master process';

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
         // Used to limit the number of outstanding connections in the socket's listen queue.
         'backlog' => 102400,

         // Allows multiple bindings to a same ip:port pair, even from separate processes.
         'so_reuseport' => true,

         // Overrides the OS default regarding mapping IPv4 into IPv6.
         'ipv6_v6only' => false
      ];
      // SSL
      if ( ! empty($this->ssl) ) {
         self::$context['ssl'] = $this->ssl;
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
         $this->log('@\;Could not create socket: ' . $error_message, self::LOG_ERROR_LEVEL);
         exit(1);
      }
      /** @var resource $Socket */
      $this->Socket = $Socket;

      // @ On success

      // @ Disable Crypto in Main Socket
      if ( ! empty($this->ssl) ) {
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
            $this->log('@\;Failed to import stream socket!@\;', self::LOG_ERROR_LEVEL);
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
      if ($this->user === null || posix_getuid() !== 0) {
         return;
      }

      $userInfo = posix_getpwnam($this->user);
      if ($userInfo === false) {
         $this->log('@\;User "' . $this->user . '" not found. Cannot drop privileges.@\;', self::LOG_ERROR_LEVEL);
         exit(1);
      }

      $uid = $userInfo['uid'];
      $gid = $userInfo['gid'];

      // @ Resolve group
      if ($this->group !== null) {
         $groupInfo = posix_getgrnam($this->group);
         if ($groupInfo === false) {
            $this->log('@\;Group "' . $this->group . '" not found. Cannot drop privileges.@\;', self::LOG_ERROR_LEVEL);
            exit(1);
         }
         $gid = $groupInfo['gid'];
      }

      // @ Drop: group first, then user (order matters!)
      if (posix_setgid($gid) === false) {
         $this->log('@\;Failed to set GID to ' . $gid . '.@\;', self::LOG_ERROR_LEVEL);
         exit(1);
      }

      if (posix_initgroups($this->user, $gid) === false) {
         $this->log('@\;Failed to init groups for user "' . $this->user . '".@\;', self::LOG_ERROR_LEVEL);
         exit(1);
      }

      if (posix_setuid($uid) === false) {
         $this->log('@\;Failed to set UID to ' . $uid . '.@\;', self::LOG_ERROR_LEVEL);
         exit(1);
      }

      $this->log('Dropped privileges to user "' . $this->user . '" (uid=' . $uid . ', gid=' . $gid . ')', self::LOG_INFO_LEVEL);
   }

   protected function daemonize (): void
   {
      $this->Status = Status::Running;

      $this->log('Running in Daemon mode (no UI)...', self::LOG_INFO_LEVEL);

      // @ Fork: parent returns to terminal, child becomes daemon master
      $pid = pcntl_fork();

      if ($pid === -1) {
         $this->log('@\;Failed to fork daemon process!@\;', self::LOG_ERROR_LEVEL);
         exit(1);
      }

      // # Parent process (CLI caller): return control to terminal
      if ($pid > 0) {
         $this->daemonized = true;
         $this->log('@\;Daemon started (PID: ' . $pid . ')@\;@.;', self::LOG_NOTICE_LEVEL);
         return;
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
   protected function interacting (): void
   {
      $this->Status = Status::Running;

      Logger::$display = Logger::DISPLAY_MESSAGE;

      $this->log('@\;Entering in Interactive mode...@\;', self::LOG_INFO_LEVEL);
      $this->log('>_ Type `@#Green:stop@;` to stop the Server or `@#Green:help@;` to list commands.@\;');
      $this->log('>_ Type `@#Green:monitor@;` to enter in Monitor mode.@\;');
      $this->log('>_ Autocompletation and history enabled.@\\\;', self::LOG_NOTICE_LEVEL);

      while ($this->Mode === Modes::Interactive) {
         // @ Calls signal handlers for pending signals
         pcntl_signal_dispatch();

         // @ Suspends execution of the current process until a child has exited, or until a signal is delivered
         pcntl_wait($status, WNOHANG | WUNTRACED);

         // If child is running?
         if ($status === 0) {
            $interact = $this->Commands->interact();

            $this->log('@\;');

            // @ Wait for command output before looping
            if ($interact === false) {
               usleep(100000 * $this->workers); // @ wait 0.1 s * qt workers
            }
         }
         else if ($status > 0) { // If a child has already exited?
            $this->log('@\;Process child exited!@\;', self::LOG_ERROR_LEVEL);
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

      $this->log('@\;Entering in Monitor mode...@\;', self::LOG_INFO_LEVEL);

      // @ Set time to hot reloading
      Timer::add(2, function () {
         $modified = SAPI::check();

         if ($modified) {
            $this->Process->Signals->send(SIGUSR2, master: false); // @ Send signal to all children to reload
         }
      });

      // @ Set Logger to display messages, datetime and level
      Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

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
            $this->log('@\;Process child exited!@\;', self::LOG_ERROR_LEVEL);
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
         $this->log('@\;Failed to close $this->Socket!');
      }

      return $closed;
   }

   public function resume (): bool
   {
      if ($this->Status !== Status::Paused) {
         match ($this->Process->level) {
            'master' => $this->log("Server needs to be paused to resume!@\\;", 4),
            'child' => null,
            default => null
         };

         return false;
      }

      $children = (string) count($this->Process->Children->PIDs);
      match ($this->Process->level) {
         'master' => $this->log("Resuming {$children} worker(s)... @\\;", 3),
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
            'master' => $this->log("Server needs to be running to pause!@\\;", 4),
            'child' => null,
            default => null
         };

         return false;
      }

      $children = (string) count($this->Process->Children->PIDs);
      match ($this->Process->level) {
         'master' => $this->log("Pausing {$children} worker(s)... @\\;", 3),
         'child' => self::$Event->del($this->Socket, self::$Event::EVENT_CONNECT),
         default => null
      };

      $this->Status = Status::Paused;

      return true;
   }
   public function stop (): void
   {
      $this->Status = Status::Stopping;
      $this->Process->stopping = true;

      Logger::$display = Logger::DISPLAY_MESSAGE;

      switch ($this->Process->level) {
         case 'master':
            $children = (string) count($this->Process->Children->PIDs);
            $this->log("{$children} worker(s) stopped!@\\;", 3);
            pcntl_wait($status);

            $CI_CD = Environment::get('GITHUB_ACTIONS')
               || Environment::get('TRAVIS')
               || Environment::get('CIRCLECI')
               || Environment::get('GITLAB_CI')
               || Environment::get('GIT_EXEC_PATH'); // Git Hooks?
            $closable = true;
            if ($this->Mode->value >= Modes::Test->value && $CI_CD) {
               $closable = false;
            }

            $this->Process->Children->terminate();

            // @ Clean all per-project state files
            $this->Process->State->clean();

            if ($closable) {
               exit(0);
            }
         case 'child':
            $this->Process->Children->terminate();
            exit(0);
      }
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
