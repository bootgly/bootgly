<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP;


// use
use Bootgly\Debugger;
use Bootgly\Logger;
use Bootgly\Logs;
use Bootgly\SAPI;
use Bootgly\OS\Process\Timer;
// extend
use Bootgly\CLI\Terminal\_\ {
   Logger\Logging
};
use Bootgly\Web\_\ {
   Events\Select
};
use Bootgly\Web\Servers;
use Bootgly\Web\TCP\Server\_\ {
   CLI\Terminal,
   OS\Process
};
// inherit
use Bootgly\Web\TCP\ {
   Server\Connections
};


class Server implements Servers, Logs
{
   use Logging;


   protected $Socket;

   // ! Event
   public static string|object $Event = '\Bootgly\Web\_\Events\Select';

   // ! Process
   protected Process $Process;
   // ! Terminal
   protected Terminal $Terminal;


   // * Config
   #protected ? string $domain;
   protected ? string $host;
   protected ? int $port;
   protected int $workers;
   protected ? array $ssl; // SSL Stream Context
   // @ Mode
   protected int $mode;
   public const MODE_DAEMON = 1;
   public const MODE_INTERACTIVE = 2;
   public const MODE_MONITOR = 3;

   // * Data
   public static $Application = null; // OSI Application

   // * Meta
   public const VERSION = '0.0.1';
   // @ State
   protected int $started = 0;
   // @ Socket
   public static array $context;
   // @ Status
   protected int $status = 0;
   protected const STATUS_BOOTING = 1;
   protected const STATUS_CONFIGURING = 2;
   protected const STATUS_STARTING = 4;
   protected const STATUS_RUNNING = 8;
   protected const STATUS_PAUSED = 16;
   protected const STATUS_STOPING = 32;


   // ! Connection(s)
   protected Connections $Connections;


   public function __construct ()
   {
      if (\PHP_SAPI !== 'cli') {
         return false;
      }


      // * Config
      // @ Mode
      $this->mode = self::MODE_MONITOR;

      // * Data
      // ...

      // * Meta
      // @ State
      $this->started = time();
      // @ Status
      $this->status = self::STATUS_BOOTING;


      // @ Configure Logger
      $this->Logger = new Logger(channel: 'Server');
      // @ Configure Debugger
      Debugger::$debug = true;
      Debugger::$print = true;
      Debugger::$exit = false;

      // @ Instance Bootables
      // ! Connection(s)
      $this->Connections = new Connections($this);
      if (__CLASS__ !== static::class) {
         self::$Application = static::class;
      }
      // ! Web\@\Events
      static::$Event = new Select($this->Connections);

      // ! @\CLI\Terminal
      $this->Terminal = new Terminal($this);
      // ! @\OS\Process
      $Process = $this->Process = new Process($this);

      // @ Register shutdown function to avoid orphaned children
      register_shutdown_function(function () use ($Process) {
         $Process->sendSignal(SIGINT);
      });

      // @ Boot SAPI
      if (self::$Application) {
         self::$Application::boot();
      } else {
         SAPI::$production = \Bootgly\HOME_DIR . 'projects/sapi.constructor.php';
         SAPI::boot(true);
      }
   }
   public function __get (string $name)
   {
      switch ($name) {
         case 'Socket':
            return $this->Socket;

         case 'Connections':
            return $this->Connections;

         case 'Process':
            return $this->Process;

         case 'mode':
            return $this->mode;

         case '@test init':
            SAPI::$mode = SAPI::MODE_TEST;

            if (self::$Application) {
               self::$Application::boot(production: false, test: true);
            }

            break;
         case '@test':
            if ($this->Process->level === 'master' && self::$Application && method_exists(self::$Application, 'test')) {
               self::$Application::test($this);
            }

            break;
         case '@test end':
            SAPI::$mode = SAPI::MODE_PRODUCTION;
            SAPI::boot(true);

            break;
      }

      // ! @info
      $info = __DIR__ . '/Server/@/info.php';

      // @ Clear cache of file info
      if ( function_exists('opcache_invalidate') ) {
         opcache_invalidate($info, true);
      }

      clearstatcache(false, $info);

      // @ Load file info
      try {
         require $info;
      } catch (\Throwable) {
         // ...
      }
   }
   public function __set (string $name, $value)
   {
      switch ($name) {
         case 'mode':
            $this->mode = $value;
            break;
      }
   }
   public function __call (string $name, array $arguments)
   {
      switch ($name) {
         case 'instance':
            return $this->instance(...$arguments);

         case 'stop':
            $this->stop(...$arguments);
            break;
         case 'pause':
            return $this->pause(...$arguments);
         case 'resume':
            return $this->resume(...$arguments);
      }
   }

   public function configure (string $host, int $port, int $workers, ? array $ssl = null)
   {
      $this->status = self::STATUS_CONFIGURING;

      // TODO validate configuration user data inputs

      #$this->domain = $domain;

      $this->host = $host;
      $this->port = $port;
      $this->workers = $workers;

      $this->ssl = $ssl;

      return $this;
   }
   public function start ()
   {
      $this->status = self::STATUS_STARTING;

      Logger::$display = Logger::DISPLAY_MESSAGE;

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
      $this->log('@\;');
      $this->{'@status'};

      switch ($this->mode) {
         case self::MODE_DAEMON:
            $this->daemonize();
            break;
         case self::MODE_INTERACTIVE:
            $this->interact();
            break;
         case self::MODE_MONITOR:
            $this->monitor();
            break;
      }

      return true;
   }
   public function on (string $name, \Closure $handler)
   {
      switch ($name) {
         case 'data': // DEPRECATED -> moved to projects/sapi.*.constructor.php
            break;
      }
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
      $Context = stream_context_create(self::$context);

      // @ Create server socket
      try {
         $this->Socket = @stream_socket_server(
            'tcp://' . $this->host . ':' . $this->port,
            $error_code,
            $error_message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $Context
         );
      } catch (\Throwable) {
         $this->Socket = false;
      }

      if ($this->Socket === false) {
         $this->log('@\;Could not create socket: ' . $error_message, self::LOG_ERROR_LEVEL);
         exit(1);
      }

      // @ On success

      // @ Disable Crypto in Main Socket
      if ( ! empty($this->ssl) ) {
         stream_socket_enable_crypto($this->Socket, false);
      }
      // @ Enable Keep Alive if possible
      if (function_exists('socket_import_stream')) {
         $Socket = socket_import_stream($this->Socket);
         socket_set_option($Socket, SOL_SOCKET, SO_KEEPALIVE, 1);
      }

      $this->status = self::STATUS_RUNNING;

      return $this->Socket;
   }

   private function daemonize ()
   {
      $this->status = self::STATUS_RUNNING;

      // TODO

      exit(0);
   }
   private function interact ()
   {
      $this->status = self::STATUS_RUNNING;

      Logger::$display = Logger::DISPLAY_MESSAGE;

      $this->log('@\;Entering in CLI mode...@\;', self::LOG_INFO_LEVEL);
      $this->log('>_ Type `quit` to stop the Server or `help` to list commands.@\;');
      $this->log('>_ Autocompletation and history enabled.@\\\;', self::LOG_NOTICE_LEVEL);

      while ($this->mode === self::MODE_INTERACTIVE) {
         // @ Calls signal handlers for pending signals
         pcntl_signal_dispatch();

         // @ Suspends execution of the current process until a child has exited, or until a signal is delivered
         $pid = pcntl_wait($status, WNOHANG | WUNTRACED);

         // If child is running?
         if ($pid === 0) {
            $interact = $this->Terminal->interact();

            $this->log('@\;');

            // @ Wait for command output before looping
            if ($interact === false) {
               usleep(100000 * $this->workers); // @ wait 0.1 s * qt workers
            }
         } else if ($pid > 0) { // If a child has already exited?
            $this->log('@\;Process child exited!@\;', self::LOG_ERROR_LEVEL);
            $this->Process->sendSignal(SIGINT);
            break;
         } else if ($pid === -1) { // If error
            break;
         }
      }

      if ($this->mode === self::MODE_MONITOR) {
         $this->monitor();
      }
   }
   private function monitor ()
   {
      $this->status = self::STATUS_RUNNING;

      $this->log('@\;Entering in Monitor mode...@\;', self::LOG_INFO_LEVEL);
      $this->log('>_ Type `CTRL + Z` to enter in Interactive mode or `CTRL + C` to stop the Server.@\;');

      // @ Set time to hot reloading of sapi.*.constructor.php file
      Timer::add(2, function () {
         $modified = SAPI::check();

         if ($modified) {
            $this->Process->sendSignal(SIGUSR2, master: false); // @ Send signal to all children to reload
         }
      });

      // @ Set Logger to display messages, datetime and level
      Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

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
         } else if ($pid > 0) { // If a child has already exited?
            $this->log('@\;Process child exited!@\;', self::LOG_ERROR_LEVEL);
            $this->Process->sendSignal(SIGINT);
            break;
         } else if ($pid === -1) { // If error ignore
            // ...
         }
      }

      // @ Enter in CLI mode
      if ($this->mode === self::MODE_INTERACTIVE) {
         Timer::del(0); // @ Delete all timers

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
         $closed = @fclose($this->Socket);
      } catch (\Throwable) {
         $closed = false;
      }

      if ($closed === false) {
         $this->log('@\;Failed to close $this->Socket!');
      } else {
         // TODO $this->alert?
         $this->log('@\;Sockets closed successful.', self::LOG_INFO_LEVEL);
      }

      $this->Socket = null;
   }

   private function resume ()
   {
      if ($this->status !== self::STATUS_PAUSED) {
         match ($this->Process->level) {
            'master' => $this->log("Server needs to be paused to resume!@\\;", 4),
            'child' => null
         };

         return false;
      }

      $this->status = self::STATUS_RUNNING;

      match ($this->Process->level) {
         'master' => $this->log("Resuming {$this->Process->children} worker(s)... @\\;", 3),
         'child' => self::$Event->add($this->Socket, self::$Event::EVENT_CONNECT, true)
      };

      return true;
   }
   private function pause ()
   {
      if ($this->status !== self::STATUS_RUNNING) {
         match ($this->Process->level) {
            'master' => $this->log("Server needs to be running to pause!@\\;", 4),
            'child' => null
         };

         return false;
      }

      $this->status = self::STATUS_PAUSED;

      match ($this->Process->level) {
         'master' => $this->log("Pausing {$this->Process->children} worker(s)... @\\;", 3),
         'child' => self::$Event->del($this->Socket, self::$Event::EVENT_CONNECT)
      };

      return true;
   }
   private function stop ()
   {
      $this->status = self::STATUS_STOPING;

      Logger::$display = Logger::DISPLAY_MESSAGE;

      match ($this->Process->level) {
         'master' => $this->log("{$this->Process->children} worker(s) stopped!@\\;", 3),
         'child' => $this->close()
      };

      exit(0);
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
