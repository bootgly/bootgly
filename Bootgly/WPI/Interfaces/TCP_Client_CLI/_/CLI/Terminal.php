<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI\_\CLI;


use Bootgly\CLI;
use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\WPI\Interfaces\TCP_Client_CLI as Client;


class Terminal extends CLI\Terminal // TODO rename to Commands
{
   use LoggableEscaped;


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

         default => true
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
