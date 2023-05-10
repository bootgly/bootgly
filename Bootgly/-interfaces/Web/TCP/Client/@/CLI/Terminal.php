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
      'quit',

      'clear',
      'help'
   ];
   public static array $subcommands = [];


   public function __construct (Client &$Client)
   {
      parent::__construct();
      $this->Client = $Client;
   }

   // ! Command<T>
   // @ Interact
   public function command (string $command) : bool
   {
      // TODO split command in subcommands by space

      return match ($command) {
         // ! Client
         'quit' =>
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
