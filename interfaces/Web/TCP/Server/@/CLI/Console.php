<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\_\CLI;


use Bootgly\CLI;
use Bootgly\Web\TCP\ {
   Server
};


class Console extends CLI\Console
{
   public Server $Server;

   // ! Command
   // * Data
   public static array $commands = [
      'stop', 'exit', 'quit',
      'pause',
      'resume',

      'status',
      'stats',
      'peers', 'connections',

      'clear',
      'help'
   ];

   // ***


   public function __construct (Server &$Server)
   {
      $this->Server = $Server;
   }

   // If return true -> interact imediatily in the next loop otherwise wait for output...
   public function interact () : bool
   {
      // @ Register CLI autocomplete function
      // Use TAB key as trigger
      readline_completion_function([$this, 'autocomplete']);

      // @ Get user input (read line)
      $input = readline('>_: ');

      // @ Sanitize user input
      $command = trim($input);

      if ($command === '') return true;

      // @ Clear last used command (returned by autocomplete function)
      self::$command = [];

      // @ Enable command history and add the last command to history
      // Use UP/DOWN key to access the history
      readline_add_history($command);

      // @ Execute command
      return match ($command) {
         // ! Server
         'stop', 'exit', 'quit' => 
            $this->log("@\;Stopping {$this->Server->Process->children} worker(s)... ", self::LOG_WARNING_LEVEL)
            && $this->Server->Process->sendSignal(SIGINT)
            && false,
         'pause' =>
            $this->Server->Process->sendSignal(SIGTSTP) && false,
         'resume' =>
            $this->Server->Process->sendSignal(SIGUSR1) && false,

         'status' =>
            $this->Server->Process->sendSignal(SIGUSR2, children: false) && true,
         // ! \Connection
         'stats' =>
            // $this->Server->Process->Signal->send(SIGIO, ...)
            $this->Server->Process->sendSignal(SIGIO, master: false) && false,
         'peers', 'connections' =>
            $this->Server->Process->sendSignal(SIGIOT, master: false) && false,

         'clear' =>
            $this->clear() && true,
         'help' =>
            $this->help() && true,
         default =>
            $this->log(PHP_EOL . "Error: Command `$command` not found! Available commands:" . PHP_EOL)
            && $this->help()
            && true
      };
   }

   public function help ()
   {
      $this->log(<<<'OUTPUT'
      @\;============================================================
      @:i: `stop` @;   = Stop the Server and all workers;
      @:i: `pause` @;  = Pause the Server and all workers;
      @:i: `resume` @; = Resume the Server and all workers;

      @:i: `status` @; = Show info about status of server;
      @:i: `stats` @;  = Show stats about connections / data per worker;
      @:i: `peers` @;  = Show info about active connections remote peers;

      @:i: `clear` @;  = Clear this console screen;
      ============================================================

      OUTPUT);

      return true;
   }
}
