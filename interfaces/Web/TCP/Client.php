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
use Bootgly\CLI\_\ {
   Logger\Logging
};
use Bootgly\Logger;
use Bootgly\Web\_\ {
   Events\Select
};
use Bootgly\Web\TCP\Client\_\ {
   CLI\Console,
   OS\Process
};
// inherit
use Bootgly\Web\TCP\ {
   Client\Connections
};


class Client
{
   use Logging;


   public $Socket;

   // ! Event
   public static string|object $Event = '\Bootgly\Web\_\Events\Select';

   // ! Process
   protected Process $Process;
   // ! Console
   protected Console $Console;

   // * Config
   #protected string $resource;
   protected ? string $host;
   protected ? int $port;
   protected int $workers;
   // @ Mode
   protected int $mode;
   public const MODE_DEFAULT = 1;
   public const MODE_MONITOR = 2;

   // * Data

   // * Meta
   public const VERSION = '0.0.1';
   // @ Error
   public array $error = [];
   // @ State
   protected static int $started = 0;
   // @ Status
   protected static int $status = 0;
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

      // * Meta
      // @ Error
      $this->error = [];
      // @ State
      static::$started = time();
      // @ Status
      self::$status = self::STATUS_BOOTING;

      // @ Configure Debugger
      Debugger::$debug = true;
      Debugger::$print = true;
      Debugger::$exit = false;

      // @ Instance Bootables
      // ! Connection(s)
      $this->Connections = new Connections($this);
      // ! Web\@\Events
      static::$Event = new Select($this->Connections);

      // ! @\CLI\Console
      $this->Console = new Console($this);
      // ! @\OS\Process
      $Process = $this->Process = new Process($this);

      // @ Register shutdown function to avoid orphaned children
      register_shutdown_function(function () use ($Process) {
         $Process->sendSignal(SIGINT);
      });
   }
   public function __get (string $name)
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
   }
   public function __call (string $name, array $arguments)
   {
      switch ($name) {
         case 'stop':
            return $this->stop(...$arguments);
      }
   }

   public function configure (string $host, int $port, int $workers)
   {
      self::$status = self::STATUS_CONFIGURING;

      // TODO validate configuration user data inputs

      $this->host = $host;
      $this->port = $port;
      $this->workers = $workers;

      return $this;
   }
   public function start ()
   {
      self::$status = self::STATUS_STARTING;

      $this->log('Starting Client... ', self::LOG_INFO_LEVEL);

      // ! Process
      // ? Signals
      // @ Install process signals
      $this->Process->installSignal();
      // @ Fork process workers...
      $this->Process->fork($this->workers);

      // ... Continue to master process:
      switch ($this->mode) {
         case self::MODE_DEFAULT:
            // TODO
            break;
         case self::MODE_MONITOR:
            $this->monitor();
            break;
      }

      return true;
   }

   private function monitor ()
   {
      self::$status = self::STATUS_RUNNING;

      Logger::$display = Logger::DISPLAY_MESSAGE;

      $this->log('@\\\;Entering in Monitor mode...@\;', self::LOG_SUCCESS_LEVEL);
      $this->log('>_ Type `CTRL + Z` to enter in Interactive mode or `CTRL + C` to stop the Server.@\;');

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
            continue;
         } else if ($pid > 0) { // If a child has already exited?
            $this->log('@\;Process child exited!@\;', self::LOG_ERROR_LEVEL);
            $this->Process->sendSignal(SIGINT);
            break;
         } else if ($pid === -1) { // If error ignore
            continue;
         }
      }
   }

   public function connect (int $index = 0)
   {
      $error = false;

      try {
         $context = stream_context_create([
            'socket' => [ 
               // Setting this option to true will set SOL_TCP, NO_DELAY=1 appropriately, 
               // thus disabling the TCP Nagle algorithm.
               'tcp_nodelay' => true,
   
               // Used to specify the IP address (either IPv4 or IPv6) and/or the port number
               // that PHP will use to access the network. The syntax is ip:port for IPv4 addresses,
               // and [ip]:port for IPv6 addresses. Setting the IP or the port to 0 will 
               // let the system choose the IP and/or port.
               'bindto' => $this->host . ':' . (55000 + $index)
            ]
         ]);

         // @ Set custom handler error
         set_error_handler(function ($code, $message, $file, $line) use (&$error) {
            if ($code === E_WARNING && strpos($message, 'stream_socket_client(): Failed to bind') !== false) {
               $error = true;

               return true;
            }

            return false;
         });

         $Socket = stream_socket_client(
            'tcp://' . $this->host . ':' . $this->port,
            $this->error['code'],
            $this->error['message'],
            timeout: 0,
            flags: STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
            context: $context
         );
      } catch (\Throwable) {
         $Socket = false;
      } finally {
         restore_error_handler();
      }

      if ($Socket === false) {
         $this->log('Unable to connect! Socket not created.@\\;', self::LOG_WARNING_LEVEL);

         return false;
      }

      $this->Socket = $Socket;

      if ($error === true) {
         $this->log('Unable to connect! Trying to connect in the future...@\\;', self::LOG_WARNING_LEVEL);

         // @ Add to Event loop to future connection
         self::$Event->add($Socket, Select::EVENT_CONNECT, true);

         return $Socket;
      }

      $this->Connections->connect($Socket);

      return $Socket;
   }

   private function stop ()
   {
      self::$status = self::STATUS_STOPING;

      Logger::$display = Logger::DISPLAY_MESSAGE;

      match ($this->Process->level) {
         'master' => $this->log("{$this->Process->children} worker(s) stopped!@\\;", 3),
         'child' => null
      };

      exit(0);
   }
}
