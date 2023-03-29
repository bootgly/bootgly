<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal;


use Bootgly\Pipe;


class Input
{
   // * Config
   // ...

   // * Data
   public $stream;

   // * Meta
   // ...


   public function __construct ($stream = STDIN)
   {
      // * Config
      // ...

      // * Data
      $this->stream = $stream;

      // * Meta
      // ...
   }

   public function read (int $length) : string|false
   {
      pcntl_signal_dispatch();

      try {
         $read = @fread($this->stream, $length);
      } catch (\Throwable) {
         $read = false;
      }

      if ($read === false) {
         // TODO check errors
      }

      return $read;
   }

   public function reading (\Closure $CAPI, \Closure $SAPI)
   {
      $Pipe = new Pipe;

      $stream = $this->stream;

      // @ Register shutdown function
      register_shutdown_function(function () use ($stream) {
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

         // Configure terminal
         system('stty -icanon -echo');
         // Set non-blocking for data stream
         stream_set_blocking($this->stream, false);

         try {
            // @ Call Terminal Client API passing the Pipe write method
            $CAPI([$this, 'read'], [$Pipe, 'write']);
         } catch (\Throwable) {
            // ...
         }

         // Close Client API
         exit(0);
      } else if ($pid > 0) { // @ Parent (Server)
         cli_set_process_title("BootglyCLI: Server");

         try {
            // @ Call Terminal Server API passing the Pipe reading method
            $SAPI([$Pipe, 'reading']);
         } catch (\Throwable) {
            // ...
         }

         // Send signal to terminate child process
         posix_kill($pid, SIGTERM);

         // Wait for child process to exit
         pcntl_waitpid($pid, $status);
      } else if ($pid === -1) {
         die('Could not fork process!');
      }
   }
}
