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


use function cli_set_process_title;
use function fread;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_waitpid;
use function posix_kill;
use function register_shutdown_function;
use function stream_set_blocking;
use function system;
use Closure;
use Throwable;

use Bootgly\ABI\IO\IPC\Pipe;


class Input
{
   // * Config
   // ...

   // * Data
   /** @var resource */
   public $stream;

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
      pcntl_signal_dispatch();

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
      }
      else if ($pid === -1) {
         die('Could not fork process!');
      }
   }
}
