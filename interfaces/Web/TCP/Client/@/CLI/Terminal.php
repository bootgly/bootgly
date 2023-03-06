<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Client\_\CLI;


use const Bootgly\HOME_DIR;

use Bootgly\Bootgly;
use Bootgly\CLI;
use Bootgly\CLI\Terminal\_\ {
   Logger\Logging
};
use Bootgly\Web\TCP\ {
   Client
};


class Terminal extends CLI\Terminal
{
   use Logging;


   public Client $Client;

   // * Data
   // ! Command
   public static array $commands = [
      'stop', 'exit', 'quit',

      'clear',
      'help'
   ];
   public static array $subcommands = [];


   public function __construct (Client &$Client)
   {
      $this->Client = $Client;
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
      return $this->command($command);
   }
   public function command (string $command) : bool
   {
      // TODO split command in subcommands by space

      return match ($command) {
         // ! Client
         'stop', 'exit', 'quit' =>
            $this->log(
               '@\;Stopping ' . $this->Client->Process->children . ' worker(s)... ',
               self::LOG_WARNING_LEVEL
            )
            && $this->Client->Process->sendSignal(SIGINT)
            && false,

         'clear' =>
            $this->clear() && true,
         'help' =>
            $this->help() && true,

         default => passthru($command) !== false && true
      };
   }

   public function help ()
   {
      $this->log(<<<'OUTPUT'
      @\;======================================================================
      @:i: `quit` @;        = Close the Client and Stop all workers;

      @:i: `clear` @;       = Clear this console screen;
      ========================================================================

      OUTPUT);

      return true;
   }
}
