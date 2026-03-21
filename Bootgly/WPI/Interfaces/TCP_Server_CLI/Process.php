<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const LOCK_EX;
use const LOCK_UN;
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
use const WNOHANG;
use function array_search;
use function clearstatcache;
use function cli_get_process_title;
use function cli_set_process_title;
use function count;
use function explode;
use function fclose;
use function file;
use function file_put_contents;
use function flock;
use function fopen;
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_getpwuid;
use function posix_getuid;
use function posix_kill;
use function rtrim;
use function time;
use function unlink;
use function usleep;
use Exception;

use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\LoggableEscaped;
use const Bootgly\CLI;
use Bootgly\API\Projects\Project;
use Bootgly\API\Server as SAPI;
use Bootgly\WPI\Endpoints\Servers\Modes;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Process\Children;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;


// FIXME: Definition: move to ABI (as Trait or Abstract class?)
// FIXME: Implementation: use or extends (on TCP_Server_CLI)
class Process
{
   use LoggableEscaped;


   public Server $Server;

   public protected(set) Children $Children;

   // * Config
   // ...

   // * Data
   public string $title {
      get {
         $title = cli_get_process_title();

         if (!$title) {
            $title = 'Bootgly_WPI_Server: unknown process';
         }

         return $title;
      }
      set (string $value) {
         cli_set_process_title($value);
      }
   }

   // * Metadata
   public int $id {
      get => posix_getpid();
   }
   public string $level {
      get => $this->id === self::$master
         ? 'master'
         : 'child';
   }
   // # Id
   public static int $index;
   public static int $master;
   // # File (per-project state files)
   public string $commandFile;
   public string $pidFile;
   public string $pidLockFile;
   // # Server context (set on save)
   private string $serverHost = '0.0.0.0';
   private int $serverPort = 0;
   // # Lifecycle
   public bool $stopping = false;

   /** @var string */
   private string $pidsDir;


   public function __construct (Server &$Server)
   {
      $this->Server = $Server;

      $this->Children = new Children;

      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      self::$master = posix_getpid();

      // @ Resolve per-project state directory
      $project = Project::$current;
      $projectName = $project !== null && $project->folder !== '' ? $project->folder : 'default';
      $this->pidsDir = BOOTGLY_WORKING_DIR . '/workdata/pids/';

      if (is_dir($this->pidsDir) === false) {
         @mkdir($this->pidsDir, 0755, true);
      }

      $this->pidFile     = $this->pidsDir . $projectName . '.json';
      $this->pidLockFile = $this->pidsDir . $projectName . '.lock';
      $this->commandFile = $this->pidsDir . $projectName . '.command';

      $this->lock();
      $this->saveMasterPid();

      // @ Init Process Timer
      Timer::init([$this, 'handleSignal']);
   }

   /**
    * Lock the process to prevent multiple instances.
    *
    * @param int<0,7> $flag The lock flag (default: LOCK_EX).
    *
    * @return void
    */
   protected function lock (int $flag = LOCK_EX): void
   {
      static $file;

      $lock_file = $this->pidLockFile;

      $file = $file ?: fopen($lock_file, 'a+');

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
      // @ recover()
      pcntl_signal(SIGCHLD, $signalHandler, false); // 17

      // ! \Connection
      // ? @info
      // @ $stats
      pcntl_signal(SIGIOT, $signalHandler, false);  // 6
      // @ $peers
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
            // TODO review security concious (+1)
            $lines = @file($this->commandFile);

            if ($lines) {
               $line = $lines[count($lines) - 1];

               [$command, $context] = explode(':', rtrim($line));
   
               // @ Prepend command
               $command = '@' . $command;
   
               // @ Match context
               match ($context) { // @phpstan-ignore-line
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
            match ($this->Server->Mode) {
               Modes::Monitor => $this->Server->Mode = Modes::Interactive,
               Modes::Interactive => $this->Server->pause(),
               default => null
            };
            break;
         // @ resume()
         case SIGCONT: // 18
            $this->Server->resume();
            break;
         // @ reload()
         case SIGUSR2: // 12
            if ($this->level === 'master') {
               $this->sendSignal(SIGUSR2, master: false);
            }
            else if (Server::$Application) {
               Server::$Application::boot(SAPI::$Environment);
            }
            else {
               SAPI::boot(reset: true);
            }
            break;

         // @ recover()
         case SIGCHLD: // 17
            if ($this->level === 'master') {
               $this->recover($this->Server);
            }
            break;

         // ! \Connection
         // ? @info
         // @ $connections
         // Show info of active remote accepted connections (remote ip + remote port, ...)
         case SIGIOT:  // 6
            CLI->Commands->find('connections', From: $this->Server)?->run();
            break;
         // @ $stats
         // Show stats of server socket connections (reads, writes, errors...)
         case SIGIO:   // 29
            CLI->Commands->find('stats', From: $this->Server)?->run();
            break;
      }
   }
   public function sendSignal (
      int $signal, bool $master = true, bool $children = true
   ): bool
   {
      if ($master) {
         // @ Send signal to master process
         posix_kill(static::$master, $signal);

         if ($children === false) {
            pcntl_signal_dispatch();
         }
      }

      if ($children) {
         // @ Send signal to children process
         foreach ($this->Children->PIDs as $id) {
            posix_kill($id, $signal);
            usleep(100000); // Wait 0,1 s
         }
      }

      return true;
   }

   public function fork (int $workers): void
   {
      $this->log("forking $workers workers... ", self::LOG_NOTICE_LEVEL);

      for ($index = 0; $index < $workers; $index++) {
         $PID = pcntl_fork();

         // # Child process
         if ($PID === 0) {
            // ! Set Child PID (in context of Child Process)
            $this->Children->push($this->id, $index);
            // ! Set child index
            self::$index = $index + 1;

            // ! Set child process title
            $this->title = 'Bootgly_WPI_Server: child process (Worker #' . self::$index . ')';

            // ! Set Logging display
            Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

            // @ Create stream socket server
            $this->Server->instance();

            // Event Loop
            $this->Server::$Event->add(
               $this->Server->Socket,
               Select::EVENT_CONNECT,
               true
            );
            $this->Server::$Event->loop();

            // @ Close stream socket server
            $this->Server->stop();

            // @ Exit child process
            exit(0);
         }
         // # Master process
         else if ($PID > 0) {
            // ! Set Child PID (in context of Master Process)
            $this->Children->push($PID, $index);

            $this->title = 'Bootgly_WPI_Server: master process';
         }
         // Error
         else if ($PID === -1) {
            die('Could not fork process!');
         }
      }
   }

   // @ User
   protected static function getCurrentUser (): string
   {
      $user_info = posix_getpwuid(posix_getuid());

      if ($user_info === false) {
         throw new Exception('Can not get current user');
      }

      return $user_info['name'];
   }

   /**
    * Save the master pid to a file.
    *
    * @return void
    */
   protected function saveMasterPid (): void
   {
      if (file_put_contents($this->pidFile, (string) self::$master) === false) {
         throw new Exception('Can not save master pid to ' . $this->pidFile);
      }
   }

   /**
    * Save full process state (master + workers + host + port) to the PID file.
    *
    * @param string $host The server host address.
    * @param int $port The server port number.
    *
    * @return void
    */
   public function save (string $host, int $port): void
   {
      $this->serverHost = $host;
      $this->serverPort = $port;

      $data = [
         'master'  => self::$master,
         'workers' => $this->Children->PIDs,
         'host'    => $host,
         'port'    => $port,
         'started' => time(),
         'type'    => 'WPI'
      ];

      $json = json_encode($data);
      if ($json === false || file_put_contents($this->pidFile, $json) === false) {
         throw new Exception('Can not save process state to ' . $this->pidFile);
      }
   }

   /**
    * Recover crashed workers by reforking at the same index.
    *
    * @param Server $Server The server instance.
    *
    * @return void
    */
   public function recover (Server $Server): void
   {
      // ? Skip during shutdown
      if ($this->stopping) {
         return;
      }

      // @ Reap all dead children
      while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
         // @ Find the index of the dead worker
         $deadIndex = array_search($pid, $this->Children->PIDs, true);

         if ($deadIndex === false) {
            continue;
         }

         $this->log("Worker #{$deadIndex} (PID: {$pid}) crashed, reforking...", self::LOG_WARNING_LEVEL);

         // @ Fork a replacement worker at the same index
         $newPID = pcntl_fork();

         // # Child process (new worker)
         if ($newPID === 0) {
            $this->Children->push($this->id, (int) $deadIndex);
            self::$index = (int) $deadIndex + 1;

            $this->title = 'Bootgly_WPI_Server: child process (Worker #' . self::$index . ')';

            Logger::$display = Logger::DISPLAY_MESSAGE_WHEN_ID;

            $Server->instance();

            $Server::$Event->add(
               $Server->Socket,
               Select::EVENT_CONNECT,
               true
            );
            $Server::$Event->loop();

            $Server->stop();

            exit(0);
         }
         // # Master process
         else if ($newPID > 0) {
            $this->Children->push($newPID, (int) $deadIndex);

            $this->log("Worker #{$deadIndex} recovered (new PID: {$newPID})", self::LOG_NOTICE_LEVEL);

            // @ Update PID file
            $this->save($this->serverHost, $this->serverPort);
         }
      }
   }

   /**
    * Clean all per-project state files (PID, lock, command).
    *
    * @return void
    */
   public function clean (): void
   {
      @unlink($this->commandFile);
      @unlink($this->pidFile);

      $this->lock(LOCK_UN);
   }

   public function __destruct ()
   {
      if ($this->level === 'master') {
         @unlink($this->commandFile);

         $this->lock(LOCK_UN);
      }
   }
}
