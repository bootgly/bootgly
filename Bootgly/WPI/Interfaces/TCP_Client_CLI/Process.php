<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI;


use const LOCK_EX;
use const LOCK_UN;
use const SIGALRM;
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
use function clearstatcache;
use function cli_set_process_title;
use function count;
use function fclose;
use function file_put_contents;
use function flock;
use function fopen;
use function is_file;
use function pcntl_fork;
use function pcntl_signal;
use function posix_getpid;
use function posix_getpwuid;
use function posix_getuid;
use function posix_kill;
use function unlink;
use function usleep;
use Exception;

use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI\Interfaces\TCP_Client_CLI as Client;


class Process // FIXME: extends Process
{
   use LoggableEscaped;


   public Client $Client;


   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // @ Id
   public static int $index;
   public static int $master;
   private string $level;
   /** @var array<int> */
   public static array $children = [];
   // File
   public static string $commandFile = BOOTGLY_WORKING_DIR . '/workdata/client.command';
   public static string $pidFile = BOOTGLY_WORKING_DIR . '/workdata/client.pid';
   public static string $pidLockFile = BOOTGLY_WORKING_DIR . '/workdata/client.pid.lock';


   public function __construct (Client &$Client)
   {
      $this->Client = $Client;


      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      self::$master = posix_getpid();


      static::lock();
      static::saveMasterPid();

      // @ Init Process Timer
      Timer::init([$this, 'handleSignal']);
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         case 'id':
            return posix_getpid();

         case 'master':
            return $this->__get("id") === self::$master;
         case 'child':
            return $this->__get("id") !== self::$master;

         case 'level':
            $this->level = $this->__get("master") ? 'master' : 'child';
            return $this->level;

         case 'children':
            return count(self::$children);
      }

      return null;
   }

   /**
    * Lock the process to prevent multiple instances.
    *
    * @param int<0,7> $flag
    *
    * @return void
    */
   protected static function lock (int $flag = LOCK_EX): void
   {
      static $file;

      $lock_file = static::$pidLockFile;

      $file = $file ? : fopen($lock_file, 'a+');

      if ($file) {
         flock($file, $flag);

         if ($flag === LOCK_UN) {
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
   public function installSignal (): void
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
   public function handleSignal (int $signal): void
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
   public function sendSignal (
      int $signal, bool $master = true, bool $children = true
   ): true
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

   public function fork (int $workers): void
   {
      $this->log("forking $workers workers... ", self::LOG_INFO_LEVEL);

      for ($i = 0; $i < $workers; $i++) {
         $pid = pcntl_fork();

         self::$children[$i] = $pid;

         if ($pid === 0) { // Child process
            // @ Set child index
            self::$index = $i + 1;

            cli_set_process_title('Bootgly_WPI_Client: child process (Worker #' . self::$index . ')');

            // @ Set Logging display
            Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

            // @ Call On Worker instance
            if (Client::$onInstance) {
               (Client::$onInstance)($this->Client);
            }

            $this->Client->stop();
            #exit(1);
         }
         else if ($pid > 0) { // Master process
            cli_set_process_title("Bootgly_WPI_Client: master process");
         }
         else if ($pid === -1) {
            die('Could not fork process!');
         }
      }
   }

   // @ User
   protected static function getCurrentUser (): string
   {
      $user_info = posix_getpwuid(posix_getuid());

      if ($user_info === false) {
         throw new Exception('Can not get current user information');
      }

      return $user_info['name'];
   }

   protected static function saveMasterPid (): void
   {
      if (file_put_contents(static::$pidFile, static::$master) === false) {
         throw new Exception('Can not save master pid to ' . static::$pidFile);
      }
   }

   public function __destruct ()
   {
      if ($this->__get("level") === 'master') {
         @unlink(static::$commandFile);
         @unlink(static::$pidFile);
         @unlink(static::$pidLockFile);
      }
   }
}
