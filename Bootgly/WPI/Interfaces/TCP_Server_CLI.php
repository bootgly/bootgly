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


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Debugging\Shutdown;
use Bootgly\ABI\IO\FS\File;

use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Logging;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\LoggableEscaped;

use Bootgly\API\Environment;
use Bootgly\API\Environments;
use Bootgly\API\Projects;
use Bootgly\API\Server as SAPI;

use Bootgly\CLI;
use Bootgly\WPI\Endpoints\Servers;
use Bootgly\WPI\Endpoints\Servers\Modes;
use Bootgly\WPI\Endpoints\Servers\Status;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Commands;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Process;


class TCP_Server_CLI implements Servers, Logging
{
   use LoggableEscaped;


   // !
   protected $Socket;

   public static Events & Loops $Event;

   protected Commands $Commands;
   protected Process $Process;

   // * Config
   protected ? string $domain;
   protected ? string $host;
   protected ? int $port;
   protected int $workers;
   protected ? array $ssl; // SSL Stream Context
   // @ Mode
   public Modes $Mode;
   // @ Verbosity

   // * Data
   // @ SAPI
   public static $Application = null; 
   public static $Decoder = null;
   public static $Encoder = null;

   // * Metadata
   public const VERSION = '0.0.1-alpha';
   // @ State
   protected int $started = 0;
   // @ Socket
   protected ? string $socket;
   public static array $context;
   // @ Status
   protected Status $Status = Status::Booting;
   // @ Stats
   protected static int $stat = -1;
   protected static array $stats = [];

   // .
   protected Connections $Connections;


   public function __construct ()
   {
      if (\PHP_SAPI !== 'cli') {
         return false;
      }

      // * Config
      $this->socket = 'tcp://';
      $this->host = null;
      $this->port = null;
      // $this->workers
      $this->ssl = null;
      // @ Mode
      $this->Mode = Modes::Monitor;

      // * Data
      // @ SAPI
      // Application
      if (__CLASS__ !== static::class) {
         self::$Application = static::class;
      }
      // Decoder

      // Encoder
      // ...

      // * Metadata
      // @ State
      $this->started = \time();
      // @ Status
      $this->Status = Status::Booting;


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
      static::$Event = new Select($this->Connections);

      // ! @\Process
      $Process = $this->Process = new Process($this);
      // ! @\Commands
      $this->Commands = new Commands($this);

      // @ Register shutdown function to avoid orphaned children
      \register_shutdown_function(function () use ($Process) {
         Shutdown::debug();
         $Process->sendSignal(SIGINT);
      });

      // @ Boot Server API
      if (self::$Application) {
         self::$Application::boot(Environments::Production);
      } 
      else {
         SAPI::$production = Projects::CONSUMER_DIR . 'Bootgly/WPI/TCP_Server_CLI.SAPI.php';
         SAPI::boot(reset: true, key: 'on.Package.Receive');
      }
   }
   public function __get (string $name)
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

         case '@test init':
            SAPI::$Environment = Environments::Test;

            if (self::$Application) {
               self::$Application::boot(Environments::Test);
            }

            break;
         case '@test':
            if ($this->Process->level === 'master' && self::$Application && \method_exists(self::$Application, 'test')) {
               self::$Application::test($this);
            }

            break;
         case '@test end':
            SAPI::$Environment = Environments::Production;

            if (self::$Application) {
               self::$Application::boot(Environments::Production);
               break;
            }

            SAPI::boot(reset: true);

            break;
      }

      // ! @info
      // @ Set log display none
      $display = Logger::$display;
      Logger::$display = Logger::DISPLAY_MESSAGE;

      // @ Output info server
      $Info = new File(
         BOOTGLY_ROOT_DIR . __CLASS__ . '/info.php'
      );
      if ($Info->exists) {
         // @ Clear cache of file info
         if (\function_exists('opcache_invalidate')) {
            \opcache_invalidate($Info->file, true);
         }
         \clearstatcache(false, $Info->file);

         // @ Load file info
         try {
            require $Info->file;
         }
         catch (\Throwable) {
            // ...
         }
      }

      // @ Restore log display
      Logger::$display = $display;
   }
   
   public function __call (string $name, array $arguments)
   {
      switch ($name) {
         case 'instance':
            return $this->instance(...$arguments);

         case 'stop':
            return $this->stop(...$arguments);
         case 'pause':
            return $this->pause(...$arguments);
         case 'resume':
            return $this->resume(...$arguments);
      }
   }

   public function configure (
      string $host,
      int $port,
      int $workers,
      ? array $ssl = null
   )
   {
      $this->Status = Status::Configuring;

      // TODO validate configuration user data inputs

      #$this->domain = $domain;

      $this->host = $host;
      $this->port = $port;
      $this->workers = $workers;

      $this->ssl = $ssl;

      return $this;
   }
   public function on (string $name, \Closure $handler) : bool
   {
      switch ($name) {
         case 'encode':
            break;
      }

      return true;
   }
   public function start ()
   {
      $this->Status = Status::Starting;

      Logger::$display = Logger::$display === 0 ? 0 : Logger::DISPLAY_MESSAGE;

      $this->log('@\;Starting Server...', self::LOG_NOTICE_LEVEL);

      // ! Process
      // ? Signals
      // @ Install process signals
      // $this->Process->Signal->install();
      $this->Process->installSignal();
      #$this->Process::lock();

      // @ Fork process workers...
      $this->Process->fork($this->workers);

      // ... Continue to master process:
      switch ($this->Mode) {
         case Modes::Daemon:
            $this->daemonize();
            break;
         case Modes::Interactive:
            $this->interact();
            break;
         case Modes::Monitor:
            new CLI; // TODO remove (temp: use Script exec)
            $this->monitor();
            break;
      }

      return true;
   }

   private function instance ()
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
      $Context = \stream_context_create(self::$context);

      // @ Create server socket
      try {
         $this->Socket = @\stream_socket_server(
            'tcp://' . $this->host . ':' . $this->port,
            $error_code,
            $error_message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $Context
         );
      }
      catch (\Throwable) {
         $this->Socket ??= false;
      }

      if ($this->Socket === false) {
         $this->log('@\;Could not create socket: ' . $error_message, self::LOG_ERROR_LEVEL);
         exit(1);
      }

      // @ On success

      // @ Disable Crypto in Main Socket
      if ( ! empty($this->ssl) ) {
         \stream_socket_enable_crypto($this->Socket, false);
      }
      // @ Enable Keep Alive if possible
      if (\function_exists('socket_import_stream')) {
         $Socket = \socket_import_stream($this->Socket);
         \socket_set_option($Socket, SOL_SOCKET, SO_KEEPALIVE, 1);
      }

      $this->Status = Status::Running;

      return $this->Socket;
   }

   private function daemonize ()
   {
      $this->Status = Status::Running;

      // TODO

      exit(0);
   }
   private function interact ()
   {
      $this->Status = Status::Running;

      Logger::$display = Logger::DISPLAY_MESSAGE;

      $this->log('@\;Entering in Interactive mode...@\;', self::LOG_INFO_LEVEL);
      $this->log('>_ Type `@#Green:quit@;` to stop the Server or `@#Green:help@;` to list commands.@\;');
      $this->log('>_ Type `@#Green:monitor@;` to enter in Monitor mode.@\;');
      $this->log('>_ Autocompletation and history enabled.@\\\;', self::LOG_NOTICE_LEVEL);

      while ($this->Mode === Modes::Interactive) {
         // @ Calls signal handlers for pending signals
         \pcntl_signal_dispatch();

         // @ Suspends execution of the current process until a child has exited, or until a signal is delivered
         \pcntl_wait($status, WNOHANG | WUNTRACED);

         // If child is running?
         if ($status === 0) {
            $interact = $this->Commands->interact();

            $this->log('@\;');

            // @ Wait for command output before looping
            if ($interact === false) {
               \usleep(100000 * $this->workers); // @ wait 0.1 s * qt workers
            }
         }
         else if ($status > 0) { // If a child has already exited?
            $this->log('@\;Process child exited!@\;', self::LOG_ERROR_LEVEL);
            $this->Process->sendSignal(SIGINT);
            break;
         }
         else if ($status === -1) { // If error
            break;
         }
      }

      if ($this->Mode === Modes::Monitor) {
         $this->monitor();
      }
   }
   private function monitor ()
   {
      $this->Status = Status::Running;

      $this->log('@\;Entering in Monitor mode...@\;', self::LOG_INFO_LEVEL);

      // @ Set time to hot reloading
      Timer::add(2, function () {
         $modified = SAPI::check();

         if ($modified) {
            $this->Process->sendSignal(SIGUSR2, master: false); // @ Send signal to all children to reload
         }
      });

      // @ Set Logger to display messages, datetime and level
      Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

      $Output = CLI::$Terminal->Output;
      $Output->Cursor->hide();
      $Output->clear();
      $this->{'@status'};

      // @ Loop
      while ($this->Mode === Modes::Monitor) {
         // @ Calls signal handlers for pending signals
         \pcntl_signal_dispatch();

         // @ Suspends execution of the current process until a child has exited, or until a signal is delivered
         \pcntl_wait($status, WUNTRACED);

         // @ Calls signal handlers for pending signals again
         \pcntl_signal_dispatch();

         echo ' '; // This fix content blinking on Windows Terminal (LoL)
         // If child is running?
         if ($status === 0) {
            // ...
         }
         else if ($status > 0) { // If a child has already exited?
            $this->log('@\;Process child exited!@\;', self::LOG_ERROR_LEVEL);
            $this->Process->sendSignal(SIGINT);
            break;
         }
         else if ($status === -1) { // If error ignore
            // ...
         }

         $this->{'@status'};
      }

      $Output->Cursor->show();

      // @ Enter in CLI mode
      if ($this->Mode === Modes::Interactive) {
         Timer::del(0); // @ Delete all timers
         $Output->clear();
         $this->interact();
      }
   }

   private function close ()
   {
      if ($this->Socket === null || $this->Socket === false) {
         #$this->log('@\;$this->Socket is already closed?@\;');
         exit(1);
      }

      try {
         $closed = @\fclose($this->Socket);
      }
      catch (\Throwable) {
         $closed = false;
      }

      if ($closed === false) {
         $this->log('@\;Failed to close $this->Socket!');
      }
      else {
         // TODO $this->alert?
         #$this->log('@\;Sockets closed successful.', self::LOG_INFO_LEVEL);
      }

      $this->Socket = null;
   }

   private function resume () : bool
   {
      if ($this->Status !== Status::Paused) {
         match ($this->Process->level) {
            'master' => $this->log("Server needs to be paused to resume!@\\;", 4),
            'child' => null
         };

         return false;
      }

      match ($this->Process->level) {
         'master' => $this->log("Resuming {$this->Process->children} worker(s)... @\\;", 3),
         'child' => self::$Event->add($this->Socket, self::$Event::EVENT_CONNECT, true)
      };

      $this->Status = Status::Running;

      return true;
   }
   private function pause () : bool
   {
      if ($this->Status !== Status::Running) {
         match ($this->Process->level) {
            'master' => $this->log("Server needs to be running to pause!@\\;", 4),
            'child' => null
         };

         return false;
      }

      match ($this->Process->level) {
         'master' => $this->log("Pausing {$this->Process->children} worker(s)... @\\;", 3),
         'child' => self::$Event->del($this->Socket, self::$Event::EVENT_CONNECT)
      };

      $this->Status = Status::Paused;

      return true;
   }
   private function stop () : void
   {
      $this->Status = Status::Stopping;

      Logger::$display = Logger::DISPLAY_MESSAGE;

      $CI_CD = (
         Environment::get('GITHUB_ACTIONS')
         || Environment::get('TRAVIS')
         || Environment::get('CIRCLECI')
         || Environment::get('GITLAB_CI')
      );
      if ($this->Mode >= Modes::Test && $CI_CD) {
         return;
      }

      switch ($this->Process->level) {
         case 'master':
            $this->log("{$this->Process->children} worker(s) stopped!@\\;", 3);
            \pcntl_wait($status);
            exit(0);
         case 'child':
            $this->close();
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
