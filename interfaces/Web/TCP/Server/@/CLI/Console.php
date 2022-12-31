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


use Bootgly\CLI\_\ {
   Logger\Logging
};
use Bootgly\Web\TCP\ {
   Server
};


class Console
{
   use Logging;


   public Server $Server;

   // ***


   public function __construct (Server &$Server)
   {
      $this->Server = $Server;
   }

   public function interact () : bool // If return true -> interact imediatily in the next loop otherwise wait for output...
   {
      #$this->log('>_: ');

      #$input = fgets(STDIN);
      $input = readline('>_: ');

      $command = trim($input);

      return match ($command) {
         // TODO 'status'
         'stop', 'exit', 'quit' => $this->Server->Process->kill(SIGINT) && false,
         'pause' => $this->Server->Process->kill(SIGTSTP) && false,
         'resume' => $this->Server->Process->kill(SIGUSR1) && false,

         'stats' => $this->Server->Process->kill(SIGIO, false) && false, // $this->Server->Process->Signals->send(SIGIO, ['master', 'children'])
         'peers', 'connections' => $this->Server->Process->kill(SIGIOT, false) && false,

         'clear' => $this->clear() && true, // TODO move to CLI\Console context ($this->clear())

         // @ Help command
         'help' => $this->log(<<<'OUTPUT'
         ========================================================
         `stop`   = Stop the Server and all workers;
         `pause`  = Pause the Server and all workers;
         `resume` = Resume the Server and all workers;

         `stats`  = Show stats about connections and data;
         `peers`  = Show info about active connections remote peers;

         `clear`  = Clear the console screen;
         ========================================================

         OUTPUT) && true,

         default => $this->log(PHP_EOL . "Error: Command $command not accepted!" . PHP_EOL) && true // TODO help command output
      };
   }

   // TODO move to CLI\Console global
   public function clear ()
   {
      $this->log(chr(27).chr(91).'H'.chr(27).chr(91).'J');
      return true;
   }
}
