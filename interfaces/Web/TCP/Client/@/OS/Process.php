<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Client\_\OS;


// use
use const Bootgly\HOME_DIR;
use Bootgly\Logger;
use Bootgly\OS\Process\Timer;
// ?
use Bootgly\Web\TCP\ {
   Client
};
// extend
use Bootgly\CLI\Terminal\_\ {
   Logger\Logging
};


class Process
{
   use Logging;


   public Client $Client;


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
   public static $commandFile = HOME_DIR . '/workspace/client.command';
   public static $pidFile = HOME_DIR . '/workspace/client.pid';
   public static $pidLockFile = HOME_DIR . '/workspace/client.pid.lock';


   public function __construct (Client &$Client)
   {
      $this->Client = $Client;


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

      // ! Client
      // @ stop()
      pcntl_signal(SIGHUP, $signalHandler, false);  // 1
      pcntl_signal(SIGINT, $signalHandler, false);  // 2 (CTRL + C)
      pcntl_signal(SIGQUIT, $signalHandler, false); // 3
      pcntl_signal(SIGTERM, $signalHandler, false); // 15

      pcntl_signal(SIGTSTP, $signalHandler, false); // 20 (CTRL + Z)
      pcntl_signal(SIGCONT, $signalHandler, false); // 18
      pcntl_signal(SIGUSR2, $signalHandler, false); // 12

      pcntl_signal(SIGIOT, $signalHandler, false);  // 6
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
            break;

         // ! Client
         // @ stop()
         case SIGHUP:  // 1
         case SIGINT:  // 2 (CTRL + C)
         case SIGQUIT: // 3
         case SIGTERM: // 15
            $this->Client->stop();
            break;

         case SIGTSTP: // 20 (CTRL + Z)
            break;
         case SIGCONT: // 18
            break;
         case SIGUSR2: // 12
            break;

         case SIGIOT:  // 6
            break;
         case SIGIO:   // 29
            break;
      }
   }
   public function sendSignal (int $signal, bool $master = true, bool $children = true)
   {
      if ($master) {
         // Send signal to master process
         posix_kill(static::$master, $signal);
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
      $this->log("forking $workers workers... ", self::LOG_INFO_LEVEL);

      $script = HOME_DIR . $_SERVER['PHP_SELF'];

      for ($i = 0; $i < $workers; $i++) {
         $pid = pcntl_fork();

         self::$children[$i] = $pid;

         if ($pid === 0) { // Child process
            // @ Set child index
            self::$index = $i + 1;

            cli_set_process_title('BootglyWebClient: child process (Worker #' . self::$index . ')');

            // @ Set Logging display
            Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

            // @ Call On Worker instance
            if (Client::$onInstance) {
               (Client::$onInstance)($this->Client);
            }

            $this->Client->stop();
            #exit(1);
         } else if ($pid > 0) { // Master process
            cli_set_process_title("BootglyWebClient: master process ($script)");
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
