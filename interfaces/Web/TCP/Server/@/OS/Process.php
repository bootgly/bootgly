<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Server\_\OS;


// use
use const Bootgly\HOME_DIR;
use Bootgly\Logger;
use Bootgly\SAPI;
use Bootgly\OS\Process\Timer;
use Bootgly\Web\_\Events\Select;
// ?
use Bootgly\Web\TCP\ {
   Server
};
// extend
use Bootgly\CLI\Terminal\_\ {
   Logger\Logging
};


class Process
{
   use Logging;


   public Server $Server;

   // * Config
   // ...

   // * Data
   // ...

   // * Meta
   // @ Id
   public static int $index;
   public static int $master;
   public static array $children = [];
   // File
   public static $commandFile = HOME_DIR . '/workspace/server.command';
   public static $pidFile = HOME_DIR . '/workspace/server.pid';
   public static $pidLockFile = HOME_DIR . '/workspace/server.pid.lock';


   public function __construct (Server &$Server)
   {
      $this->Server = $Server;


      // * Config
      // ...

      // * Data
      // ...

      // * Meta
      self::$master = posix_getpid();


      static::lock();
      static::saveMasterPid();

      // @ Init Process Timer
      Timer::init([$this, 'handleSignal']);
   }
   public function __get ($name)
   {
      switch ($name) {
         case 'id':
            return posix_getpid();

         case 'master':
            return $this->id === self::$master;
         case 'child':
            return $this->id !== self::$master;

         case 'level':
            return $this->master ? 'master' : 'child';

         case 'children':
            return count(self::$children);
      }
   }

   protected static function lock ($flag = \LOCK_EX)
   {
      static $file;

      $lock_file = static::$pidLockFile;

      $file = $file ? : \fopen($lock_file, 'a+');

      if ($file) {
         flock($file, $flag);

         if ($flag === \LOCK_UN) {
            fclose($file);

            $file = null;

            clearstatcache();

            if ( is_file($lock_file) ) {
               unlink($lock_file);
            }
         }
      }
   }

   // @ Signal
   public function installSignal ()
   {
      $signalHandler = [$this, 'handleSignal'];

      // * Custom command
      pcntl_signal(SIGUSR1, $signalHandler, false); // 10

      // ! Server
      // @ stop()
      pcntl_signal(SIGHUP, $signalHandler, false);  // 1
      pcntl_signal(SIGINT, $signalHandler, false);  // 2 (CTRL + C)
      pcntl_signal(SIGQUIT, $signalHandler, false); // 3
      pcntl_signal(SIGTERM, $signalHandler, false); // 15
      // @ pause()
      pcntl_signal(SIGTSTP, $signalHandler, false); // 20 (CTRL + Z)
      // @ resume()
      pcntl_signal(SIGCONT, $signalHandler, false); // 18
      // @ reload()
      pcntl_signal(SIGUSR2, $signalHandler, false); // 12

      // ! \Connection
      // ? @info
      // @ $stats
      pcntl_signal(SIGIOT, $signalHandler, false);  // 6
      // @ $peers
      pcntl_signal(SIGIO, $signalHandler, false);   // 29

      // ignore
      pcntl_signal(SIGPIPE, SIG_IGN, false);
   }
   public function handleSignal ($signal)
   {
      #$this->log($signal . PHP_EOL);

      switch ($signal) {
         // * Timer
         case SIGALRM:
            Timer::tick(); // TODO move to Event-loop ?
            break;

         // * Custom command
         case SIGUSR1:  // 10
            // TODO review security concious (+1)
            $lines = @file(static::$commandFile);

            if ($lines) {
               $line = $lines[count($lines) - 1];

               [$command, $context] = explode(':', rtrim($line));
   
               // @ Prepend command
               $command = '@' . $command;
   
               // @ Match context
               match ($context) {
                  'Connections' => $this->Server->Connections->{$command},
                  default => $this->Server->{$command}
               };
            }

            break;

         // ! Server
         // @ stop()
         case SIGHUP:  // 1
         case SIGINT:  // 2 (CTRL + C)
         case SIGQUIT: // 3
         case SIGTERM: // 15
            $this->Server->stop();
            break;
         // @ pause()
         case SIGTSTP: // 20 (CTRL + Z)
            match ($this->Server->mode) {
               Server::MODE_MONITOR => $this->Server->mode = Server::MODE_INTERACTIVE,
               Server::MODE_INTERACTIVE => $this->Server->pause()
            };
            break;
         // @ resume()
         case SIGCONT: // 18
            $this->Server->resume();
            break;
         // @ reload()
         case SIGUSR2: // 12
            SAPI::boot(true);
            break;

         // ! \Connection
         // ? @info
         // @ $peers
         // Show info of active remote accepted connections (remote ip + remote port, ...)
         case SIGIOT:  // 6
            $this->Server->Connections->{'@peers'};
            break;
         // @ $stats
         // Show stats of server socket connections (reads, writes, errors...)
         case SIGIO:   // 29
            $this->Server->Connections->{'@stats'};
            break;
      }
   }
   public function sendSignal (int $signal, bool $master = true, bool $children = true)
   {
      if ($master) {
         // Send signal to master process
         posix_kill(static::$master, $signal);

         if ($children === false) {
            pcntl_signal_dispatch();
         }
      }

      if ($children) {
         // Send signal to children process
         foreach (self::$children as $id) {
            posix_kill($id, $signal);
            usleep(100000); // Wait 0,1 s
         }
      }

      return true;
   }

   public function fork (int $workers)
   {
      $this->log("forking $workers workers... ", self::LOG_NOTICE_LEVEL);

      $script = HOME_DIR . $_SERVER['PHP_SELF'];

      for ($i = 0; $i < $workers; $i++) {
         $pid = pcntl_fork();

         self::$children[$i] = $pid;

         if ($pid === 0) { // Child process
            // @ Set child index
            self::$index = $i + 1;

            cli_set_process_title('BootglyWebServer: child process (Worker #' . self::$index . ')');

            // @ Set Logging display
            Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

            // @ Create stream socket server
            $this->Server->instance();

            // Event Loop
            $this->Server::$Event->add($this->Server->Socket, Select::EVENT_CONNECT, true);
            $this->Server::$Event->loop();

            $this->Server->stop();
            #exit(1);
         } else if ($pid > 0) { // Master process
            cli_set_process_title("BootglyWebServer: master process ($script)");
         } else if ($pid === -1) {
            die('Could not fork process!');
         }
      }
   }

   // @ User
   protected static function getCurrentUser ()
   {
      $user_info = posix_getpwuid(posix_getuid());

      return $user_info['name'];
   }

   protected static function saveMasterPid () // Save process master id to file
   {
      if (file_put_contents(static::$pidFile, static::$master) === false) {
         throw new \Exception('Can not save master pid to ' . static::$pidFile);
      }
   }

   public function __destruct ()
   {
      if ($this->level === 'master') {
         @unlink(static::$commandFile);
         @unlink(static::$pidFile);
         @unlink(static::$pidLockFile);
      }
   }
}
