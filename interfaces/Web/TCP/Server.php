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
use Bootgly\Debugger\Backtrace;
// extend
use Bootgly\CLI\_\ {
   Logger\Logging
};
use Bootgly\Web\_\ {
   Events\Select
};
use Bootgly\Web\TCP\_\ {
   CLI\Console,
   OS\Process
};
// inherit
use Bootgly\Web\TCP\ {
   Server\Connection
};


class Server
{
   use Logging;


   protected $Socket;

   // * Config
   #protected string $resource;
   protected ? string $host;
   protected ? int $port;
   protected int $workers;

   protected ? \Closure $handler;
   // @ Mode
   protected int $mode;
   protected const MODE_INTERACTIVE = 1;
   protected const MODE_DAEMON = 2;
   // * Meta
   public const VERSION = '0.0.1';
   protected static int $started = 0;
   // @ Status
   protected static int $status = 0;
   protected const STATUS_BOOTING = 1;
   protected const STATUS_CONFIGURING = 2;
   protected const STATUS_STARTING = 4;
   protected const STATUS_RUNNING = 8;
   protected const STATUS_PAUSED = 16;
   protected const STATUS_STOPING = 32;

   // ! Connection
   protected Connection $Connection;
   // ! Event
   public static $Event = null;
   // ! Process
   protected Process $Process;
   // ! Console
   protected Console $Console;


   public function __construct ()
   {
      if (\PHP_SAPI !== 'cli') {
         return false;
      }

      // * Config
      // @ Mode
      $this->mode = self::MODE_INTERACTIVE;
      // * Data
      // * Meta
      static::$started = time();
      // @ Status
      self::$status = self::STATUS_BOOTING;

      // ! Connection(s)
      $this->Connection = new Connection($this);
      $this->Connection->Data = require __DIR__ . '/Server/Connections/Data.php';
      if (__CLASS__ !== static::class) {
         $this->Connection->Data = require (new Backtrace)->dir . '/Server/@/Connections/Data.php';
      }

      // ! Web\@\Events
      static::$Event = new Select($this->Connection);
      // ! @\CLI\Console
      $this->Console = new Console($this);
      // ! @\OS\Process
      $this->Process = new Process($this);
   }
   public function __get (string $name)
   {
      switch ($name) {
         case 'Socket':
            return $this->Socket;

         case 'Connection':
            return $this->Connection;
         case 'Process':
            return $this->Process;

         case 'handler':
            return $this->handler;

         // TODO move to Info class?
         case '@status':
            // ! Server
            // @
            $server = (new \ReflectionClass($this))->getName();
            $version = self::VERSION;
            $php = PHP_VERSION;
            // Runtime
            $runtime = [];
            $uptime = time() - static::$started;
            $runtime['started'] = date('Y-m-d H:i:s', static::$started);
            // @ uptime (d = days, h = hours, m = minutes)
            if ($uptime > 60) $uptime += 30;
            $runtime['d'] = (int) ($uptime / (24 * 60 * 60)) . 'd ';
            $uptime %= (24 * 60 * 60);
            $runtime['h'] = (int) ($uptime / (60 * 60)) . 'h ';
            $uptime %= (60 * 60);
            $runtime['m'] = (int) ($uptime / 60) . 'm ';
            $uptime %= 60;
            $runtime['s'] = (int) ($uptime) . 's ';
            $uptimes = $runtime['d'] . $runtime['h'] . $runtime['m'] . $runtime['s'];
            // Load Average
            $load = ['-', '-', '-'];
            if ( function_exists('sys_getloadavg') ) {
               $load = array_map('round', sys_getloadavg(), [2, 2, 2]);
            }
            $load = "{$load[0]}, {$load[1]}, {$load[2]}";
            // Workers
            $workers = $this->workers;
            // Socket
            $address = 'tcp://' . $this->host . ':' . $this->port;
            // Event-loop
            $event = (new \ReflectionClass(self::$Event))->getName();

            $this->log(<<<OUTPUT
            =========================== Server Status ===========================
            @:i: Bootgly Server: @; {$server}
            @:i: Bootgly Server version: @; {$version}\t\t@:i: PHP version: @; {$php}

            @:i: Started time: @; {$runtime['started']}\t@:i: Uptime: @; {$uptimes}
            @:i: Load average: @; $load\t\t@:i: Workers count: @; {$workers}
            @:i: Socket address: @; {$address}

            @:i: Event-loop: @; {$event}
            =====================================================================@\\\;
            OUTPUT);

            break;
      }
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

   public function configure (string $host, int $port, int $workers, ? \Closure $handler = null)
   {
      self::$status = self::STATUS_CONFIGURING;

      // TODO validate configuration user data inputs

      $this->host = $host;
      $this->port = $port;
      $this->workers = $workers;

      $this->handler = $handler;

      return $this;
   }
   public function start ()
   {
      self::$status = self::STATUS_STARTING;

      $this->log('Starting Server... ', self::LOG_INFO_LEVEL);

      // ! Process
      // $this->Process->Signal->install();
      $this->Process->installSignal();
      #$this->Process::lock();

      // @ Fork process workers...
      $this->Process->fork($this->workers);

      if ($this->Process->level === 'child') {
         #$this->log('Ops, child exited!@\;');
         #exit;
      }

      // @ Continue to master process:
      switch ($this->mode) {
         case self::MODE_DAEMON:
            $this->daemonize();
            break;
         case self::MODE_INTERACTIVE:
            $this->interact();
      }

      return true;
   }

   private function instance ()
   {
      $error_code = 0;
      $error_message = '';

      $context = stream_context_create([
         'socket' => [ 
            // Used to limit the number of outstanding connections in the socket's listen queue.
            'backlog' => 102400,

            // Allows multiple bindings to a same ip:port pair, even from separate processes.
            'so_reuseport' => true,

            // Setting this option to true will set SOL_TCP, NO_DELAY=1 appropriately, 
            // thus disabling the TCP Nagle algorithm.
            'tcp_nodelay' => false,

            // Enables sending and receiving data to/from broadcast addresses.
            'so_broadcast' => false
         ]
      ]);

      $this->Socket = false;
      try {
         $this->Socket = @stream_socket_server(
            'tcp://' . $this->host . ':' . $this->port,
            $error_code, $error_message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
         );
      } catch (\Throwable) {};

      if ($this->Socket === false) {
         $this->log('@\;Could not create socket: ' . $error_message, self::LOG_ERROR_LEVEL);
         exit(1);
      }

      $Socket = socket_import_stream($this->Socket);
      socket_set_option($Socket, SOL_SOCKET, SO_KEEPALIVE, 1);

      self::$status = self::STATUS_RUNNING;
   }

   private function daemonize ()
   {
      self::$status = self::STATUS_RUNNING;

      // TODO

      exit(0);
   }
   private function interact ()
   {
      self::$status = self::STATUS_RUNNING;

      $this->log('@\\\;Entering in CLI interaction mode...@\;', self::LOG_SUCCESS_LEVEL);
      $this->log('>_ Type `CTRL+C`[2x] to stop the Server or `help` to list commands.@\;');
      $this->log('>_ Autocompletation and history enabled.@\\\;', self::LOG_NOTICE_LEVEL);

      while (1) {
         // Calls signal handlers for pending signals
         pcntl_signal_dispatch();

         // Suspends execution of the current process until a child has exited, or until a signal is delivered
         $pid = pcntl_wait($status, WNOHANG | WUNTRACED);
         #$pid = pcntl_wait($status, WUNTRACED);

         // If child is running?
         if ($pid === 0) {
            $interact = $this->Console->interact();

            $this->log('@\;');

            // Wait for command output before looping
            if ($interact === false) {
               usleep(100000 * $this->workers); // @ wait 0.1 s * qt workers
            }

            continue;
         } else if ($pid > 0) { // If a child has already exited?
            $this->log('@\;Child exited!@\;', self::LOG_ERROR_LEVEL);
            $this->stop();
            break;
         } else if ($pid === -1) { // If error
            break;
         }
      }
   }

   private function close ()
   {
      if ($this->Socket === null || $this->Socket === false) {
         #$this->log('@\;$this->Socket is false or null!@\;');
         exit(1);
      }

      $resource = get_resource_type($this->Socket);
      if ($resource !== 'stream') {
         #$this->log('@\;Resource type of $this->Socket is not a stream!');
         exit(1);
      }

      $closed = false;
      try {
         $closed = @fclose($this->Socket);
      } catch (\Throwable) {}

      if ($closed === false) {
         #$this->log('@\;Failed to close $this->Socket!');
      } else {
         #$this->log('@\;Sockets closed successful.', 5);
      }

      $this->Socket = null;
   }

   private function resume ()
   {
      if (self::$status !== self::STATUS_PAUSED) {
         match ($this->Process->level) {
            'master' => $this->log("Server needs to be paused to resume!@\\;", 4),
            'child' => null
         };
         
         return false;
      }

      self::$status = self::STATUS_RUNNING;

      match ($this->Process->level) {
         'master' => $this->log("Resuming {$this->Process->children} worker(s)... @\\;", 3),
         'child' => self::$Event->add($this->Socket, self::$Event::EVENT_READ, 'accept')
      };

      return true;
   }
   private function pause ()
   {
      if (self::$status !== self::STATUS_RUNNING) {
         match ($this->Process->level) {
            'master' => $this->log("Server needs to be running to pause!@\\;", 4),
            'child' => null
         };

         return false;
      }

      self::$status = self::STATUS_PAUSED;

      match ($this->Process->level) {
         'master' => $this->log("Pausing {$this->Process->children} worker(s)... @\\;", 3),
         'child' => self::$Event->del($this->Socket, self::$Event::EVENT_READ)
      };

      return true;
   }
   private function stop ()
   {
      self::$status = self::STATUS_STOPING;

      match ($this->Process->level) {
         'master' => $this->log("{$this->Process->children} worker(s) stopped!@\\;", 3),
         'child' => $this->close()
      };

      exit(0);
   }
}
