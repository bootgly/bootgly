<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal;


use const SIGTERM;
use const STDIN;
use function cli_set_process_title;
use function defined;
use function fopen;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_kill;
use function register_shutdown_function;
use function stream_isatty;
use function stream_set_blocking;
use function system;
use function time;
use Closure;
use Generator;
use Throwable;

use Bootgly\ABI\IO\IPC\Pipe;
use Bootgly\ACI\Process\State;
use Bootgly\API\Projects;
use Bootgly\CLI\Terminal\Input\Roles;


class Input
{
   // * Config
   // ...

   // * Data
   /** @var resource */
   public $stream;
   // # Terminal Client/Server API
   // Role and duplex channel for embedded runtimes (one role per process);
   // when both stay null and process control exists, reading() forks natively.
   public null|Roles $role = null;
   /** @var array{0: resource, 1: resource}|null Duplex channel override (read, write) */
   public null|array $channel = null;

   // * Metadata
   // ...


   /**
    * @param resource $stream
    */
   public function __construct ($stream = STDIN)
   {
      // * Config
      // ...

      // * Data
      $this->stream = $stream;

      // * Metadata
      // ...
   }

   /**
    * Configures the input stream settings.
    *
    * @param bool $blocking Whether to set the stream to blocking or non-blocking mode. Default is true (blocking).
    * @param bool $canonical Whether to enable or disable canonical input processing mode. Default is true (enabled).
    * @param bool $echo Whether to enable or disable echoing of input characters. Default is true (enabled).
    *
    * @return self Returns the current instance for method chaining.
    */
   public function configure (bool $blocking = true, bool $canonical = true, bool $echo = true): self
   {
      stream_set_blocking($this->stream, $blocking);

      // ? Terminal modes require an interactive TTY (pipes and embedded runtimes cannot fork stty)
      if (stream_isatty($this->stream) === false) {
         // :
         return $this;
      }

      $canonical
         ? system('stty icanon')
         : system('stty -icanon');

      $echo
         ? system('stty echo')
         : system('stty -echo');

      return $this;
   }

   /**
    * Reads a specified number of bytes from the input stream.
    *
    * @param int $length The number of bytes to read.
    *
    * @return string|false Returns the read data as a string, or false if an error occurred.
    */
   public function read (int $length): string|false
   {
      // ?
      if ($length < 1) {
         return false;
      }

      // @
      if (function_exists('pcntl_signal_dispatch') === true) {
         pcntl_signal_dispatch();
      }

      try {
         $read = @fread($this->stream, $length);
      }
      catch (Throwable) {
         $read = false;
      }

      if ($read === false) {
         // TODO check errors
      }

      return $read;
   }

   /**
    * Initiates a bidirectional communication between a Terminal Client API and a Terminal Server API.
    * The function forks a child process to handle the Terminal Client API and communicates with the parent process using a Pipe.
    *
    * @param Closure $CAPI The Terminal Client API function. It should accept two parameters: a callable to read from the input stream and a callable to write to the Pipe.
    * @param Closure $SAPI The Terminal Server API function. It should accept one parameter: a callable to read from the Pipe.
    *
    * @return void
    *
    * @throws Throwable If an error occurs during the communication or process management.
    */
   public function reading (Closure $CAPI, Closure $SAPI): void
   {
      // ? Embedded runtimes run a single role wired to an injected duplex channel
      $Role = $this->role ?? Roles::tryFrom((string) getenv('BOOTGLY_TERMINAL_ROLE'));
      if ($Role !== null) {
         $this->relay($Role, $CAPI, $SAPI);

         return;
      }

      // ? Forking the Client/Server pair requires process control
      if (function_exists('pcntl_fork') === false) {
         return;
      }

      $Pipe = new Pipe;
      $Pipe->blocking = false;
      $Pipe->open();

      $stream = $this->stream;

      // @ Register shutdown function
      register_shutdown_function(function ()
      use ($stream) {
         // Set blocking for data stream
         stream_set_blocking($stream, true);
         // Restore terminal settings
         system('stty icanon echo');
      });

      // @ Fork process
      $pid = pcntl_fork();

      // @ Save PID state for show/stop visibility
      $stateId = defined('BOOTGLY_PROJECT') ? Projects::encode(BOOTGLY_PROJECT->folder) : self::class;
      $State = new State(id: $stateId);

      if ($pid === 0) { // @ Child (Client)
         cli_set_process_title("BootglyCLI: Client");

         // Watch for a signal from the parent process to terminate
         pcntl_signal(SIGTERM, function () {
            exit(0);
         });

         // Disable canonical input processing mode and echo return
         system('stty -icanon -echo');
         // Set non-blocking for data stream
         stream_set_blocking($this->stream, false);

         try {
            // @ Call Terminal Client API passing the Pipe write method
            $CAPI([$this, 'read'], [$Pipe, 'write']);
         }
         catch (Throwable) {
            // ...
         }

         // Close Client API
         exit(0);
      }
      else if ($pid > 0) { // @ Parent (Server)
         cli_set_process_title("BootglyCLI: Server");

         // @ Handle SIGTERM: kill child before exiting
         pcntl_signal(SIGTERM, function () use ($pid, $State) {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
            $State->clean();
            exit(0);
         });

         // @ Save PID state
         $State->save([
            'master'  => posix_getpid(),
            'workers' => [$pid],
            'type'    => 'CLI-IPC',
            'started' => time()
         ]);

         try {
            // @ Call Terminal Server API passing the Pipe reading method
            $SAPI([$Pipe, 'reading']);
         }
         catch (Throwable) {
            // ...
         }

         // Send signal to terminate child process
         posix_kill($pid, SIGTERM);

         // Wait for child process to exit
         pcntl_waitpid($pid, $status);

         // @ Clean PID state
         $State->clean();
      }
      else if ($pid === -1) {
         die('Could not fork process!');
      }
   }

   /**
    * Runs a single Terminal Client/Server API role wired to a duplex channel.
    * Embedded runtimes (e.g. WASM workers) run one role per process and provide
    * the channel as a pair of streams — natively reading() forks both roles.
    *
    * @param Roles $Role The role this process assumes (Client or Server).
    * @param Closure $CAPI The Terminal Client API function.
    * @param Closure $SAPI The Terminal Server API function.
    *
    * @return void
    */
   private function relay (Roles $Role, Closure $CAPI, Closure $SAPI): void
   {
      // ! Duplex channel (read, write) — injected or resolved from the environment
      $channel = $this->channel;
      if ($channel === null) {
         // ?
         $uri = getenv('BOOTGLY_TERMINAL_CHANNEL');
         if ($uri === false || $uri === '') {
            return;
         }

         $read = fopen($uri, 'r');
         $write = fopen($uri, 'w');
         // ?
         if ($read === false || $write === false) {
            return;
         }

         $channel = [$read, $write];
      }

      switch ($Role) {
         case Roles::Client:
            // @ Terminal Client API: reads this Input, writes to the channel
            $CAPI(
               [$this, 'read'],
               static function (string $data) use ($channel): int|false {
                  return fwrite($channel[1], $data);
               }
            );

            break;
         case Roles::Server:
            // @ Terminal Server API: consumes data read from the channel
            $SAPI(
               static function (int $length = 1024, null|int $timeout = null) use ($channel): Generator {
                  // ?
                  if ($length < 1) {
                     $length = 1024;
                  }

                  // @@ Read the channel until it closes or fails
                  while (true) {
                     $data = fread($channel[0], $length);

                     // ?: Channel closed or failed
                     if ($data === false || $data === '') {
                        yield false;

                        break;
                     }

                     yield $data;
                  }
               }
            );

            break;
      }
   }
}
