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

use const E_WARNING;
use const PHP_SAPI;
use const SIGINT;
use const STREAM_CLIENT_ASYNC_CONNECT;
use const STREAM_CLIENT_CONNECT;
use const WUNTRACED;
use function count;
use function pcntl_signal_dispatch;
use function pcntl_wait;
use function register_shutdown_function;
use function restore_error_handler;
use function set_error_handler;
use function stream_context_create;
use function stream_socket_client;
use function strpos;
use function time;
use Closure;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Commands;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Process;


class TCP_Client_CLI
{
   use LoggableEscaped;


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
   // @ Mode
   protected int $mode;
   public const MODE_DEFAULT = 1;
   public const MODE_MONITOR = 2;

   // * Data
   // ! On
   // @ on Worker
   public static null|Closure $onInstance = null;
   // @ on Connection
   public static null|Closure $onConnect = null;
   public static null|Closure $onDisconnect = null;
   // @ on Packages
   public static null|Closure $onRead = null;
   public static null|Closure $onWrite = null;

   // * Metadata
   public const VERSION = '0.0.1';
   // @ Error
   /** @var array<int|string|null> */
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


   public function __construct (int $mode = self::MODE_DEFAULT)
   {
      if (PHP_SAPI !== 'cli') {
         return;
      }

      // * Config
      // @ Mode
      $this->mode = $mode;

      // * Data
      // ...

      // * Metadata
      // @ Error
      $this->error = [];
      // @ State
      static::$started = time();
      // @ Status
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

      // ! @\Process
      $Process = $this->Process = new Process($this);
      // ! @\Commands
      $this->Commands = new Commands($this);

      // @ Register shutdown function to avoid orphaned children
      register_shutdown_function(function () use ($Process) {
         $Process->sendSignal(SIGINT);
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
   public function on
   (
      null|Closure $instance = null,
      null|Closure $connect = null, null|Closure $disconnect = null,
      null|Closure $read = null, null|Closure $write = null
   ): void
   {
      // @ Worker
      self::$onInstance = $instance;
      // @ Connection(s)
      self::$onConnect = $connect;
      self::$onDisconnect = $disconnect;
      // @ Packages
      self::$onRead = $read;
      self::$onWrite = $write;
   }
   public function start (): bool
   {
      self::$status = self::STATUS_STARTING;

      if ($this->workers) {
         $this->log('Starting Client... ', self::LOG_INFO_LEVEL);

         // ! Process
         // ? Signals
         // @ Install process signals
         $this->Process->installSignal();
         // @ Fork process workers...
         $this->Process->fork($this->workers);
      }

      $this->log('@\;@\;');

      // ... Continue to master process:
      switch ($this->mode) {
         case self::MODE_DEFAULT:
            if (self::$onInstance) {
               (self::$onInstance)($this);
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

      if (Logger::$display !== Logger::DISPLAY_NONE) {
         Logger::$display = Logger::DISPLAY_MESSAGE;
      }

      $this->log('Entering in Monitor mode...@\;', self::LOG_INFO_LEVEL);
      $this->log('>_ Type `CTRL + C` to stop the Client.@\;');

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
            $this->log('@\;Process child exited!@\;', self::LOG_ERROR_LEVEL);
            $this->Process->sendSignal(SIGINT);
            break;
         }
         else if ($pid === -1) { // If error ignore
            // ...
         }
      }
   }

   /**
    * Open connection with server / Connect with server
    *
    * @return resource|false
    */
   public function connect ()
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
               #'bindto' => $this->host . ':' . (55000 + $index)
            ]
         ]);

         // @ Set custom handler error
         // function ($code, $message, $file, $line)
         set_error_handler(function ($code, $message) use (&$error) {
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
      }
      catch (Throwable) {
         $Socket = false;
      }
      finally {
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

      $this->Connections->connect();

      return $Socket;
   }

   public function stop (): void
   {
      self::$status = self::STATUS_STOPING;

      Logger::$display = Logger::DISPLAY_MESSAGE;

      $children = (string) count($this->Process::$children);
      match ($this->Process->level) {
         'master' => $this->log("{$children} worker(s) stopped!@\\;", 3),
         'child' => null,
         default => null
      };

      exit(0);
   }
}
