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

      $this->log('Starting Server... ', 1);

      // ! Process
      // $this->Process->Signal->install();
      $this->Process->installSignal();
      #$this->Process::lock();

      // @ Fork process workers...
      $this->Process->fork($this->workers);

      if ($this->Process->level === 'child') {
         #$this->log('Ops, child exited!' . PHP_EOL);
         #exit;
      }

      // @ Continue to master process:
      $this->log('Ok.' . PHP_EOL, 1);

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

            // Setting this option to true will set SOL_TCP,NO_DELAY=1 appropriately, thus disabling the TCP Nagle algorithm.
            'tcp_nodelay' => true,

            // Enables sending and receiving data to/from broadcast addresses.
            // 'so_broadcast' => false
         ]
      ]);

      $this->Socket = stream_socket_server(
         'tcp://' . $this->host . ':' . $this->port,
         $error_code, $error_message,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         $context
      );

      if ($this->Socket === false) {
         throw new \Exception("[$error_code] - " . 'Could not create socket: ' . $error_message);
         exit;
      }

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

      $this->log('Entering in CLI interaction mode...' . PHP_EOL, 4);
      $this->log('>_ Type `stop` to stop the Server or `help` to list commands.' . PHP_EOL . PHP_EOL);

      while (1) {
         // Calls signal handlers for pending signals
         pcntl_signal_dispatch();

         // Suspends execution of the current process until a child has exited, or until a signal is delivered
         $pid = pcntl_wait($status, WNOHANG | WUNTRACED);
         #$pid = pcntl_wait($status, WUNTRACED);

         // If child is running?
         if ($pid === 0) {
            $interact = $this->Console->interact();

            // Wait for command output before looping
            if ($interact === false) {
               $this->log(PHP_EOL);

               usleep(300000 * $this->workers); // @ wait 0.3 s * qt workers
            }

            continue;
         } else if ($pid > 0) { // If a child has already exited?
            $this->log(PHP_EOL . 'Child exited!' . PHP_EOL);

            #break;
         } else if ($pid === -1) { // If error
            break;
         }
      }
   }

   private function close ()
   {
      if ($this->Socket === null || $this->Socket === false) {
         $this->log(PHP_EOL . '$this->Socket is false or null!' . PHP_EOL);
         exit(1);
      }

      $resource = get_resource_type($this->Socket);
      if ($resource !== 'stream') {
         $this->log(PHP_EOL . 'Resource type of $this->Socket is not a stream!');
         exit(1);
      }

      $closed = false;
      try {
         $closed = @fclose($this->Socket);
      } catch (\Throwable $Throwable) {}

      if ($closed === false) {
         $this->log(PHP_EOL . 'Failed to close $this->Socket!');
      } else {
         #$this->log(PHP_EOL . 'Sockets closed successful.', 4);
      }

      $this->Socket = null;
   }

   private function resume ()
   {
      if (self::$status !== self::STATUS_PAUSED) {
         match ($this->Process->level) {
            'master' => $this->log("Server needs to be paused to resume!" . PHP_EOL . PHP_EOL, 3),
            'child' => null
         };
         
         return false;
      }

      self::$status = self::STATUS_RUNNING;

      match ($this->Process->level) {
         'master' => $this->log("Resuming {$this->Process->children} worker(s)... " . PHP_EOL . PHP_EOL, 2),
         'child' => self::$Event->add($this->Socket, self::$Event::EVENT_READ, 'accept')
      };

      return true;
   }
   private function pause ()
   {
      if (self::$status !== self::STATUS_RUNNING) {
         match ($this->Process->level) {
            'master' => $this->log("Server needs to be running to pause!" . PHP_EOL . PHP_EOL, 3),
            'child' => null
         };

         return false;
      }

      self::$status = self::STATUS_PAUSED;

      match ($this->Process->level) {
         'master' => $this->log("Pausing {$this->Process->children} worker(s)... " . PHP_EOL . PHP_EOL, 2),
         'child' => self::$Event->del($this->Socket, self::$Event::EVENT_READ)
      };

      return true;
   }
   private function stop ()
   {
      self::$status = self::STATUS_STOPING;

      match ($this->Process->level) {
         'master' => $this->log("Stopping {$this->Process->children} worker(s)... " . PHP_EOL . PHP_EOL, 2),
         'child' => $this->close()
      };

      exit(0);
   }
}
