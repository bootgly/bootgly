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


use Bootgly\ACI\Logs\LoggableEscaped;

use Bootgly\CLI;

use Bootgly\WPI\Interfaces\TCP_Client_CLI as Client;


class Commands extends CLI\Terminal
{
   use LoggableEscaped;


   public Client $Client;

   // * Data
   // ! Command
   /** @var array<string> */
   public static array $commands = [
      'quit',

      'clear',
      'help'
   ];
   /** @var array<string,array<string>> */
   public static array $subcommands = [];


   public function __construct (Client &$Client)
   {
      parent::__construct();
      $this->Client = $Client;
   }

   // ! Command<T>
   // @ Interact
   public function command (string $command): bool
   {
      // TODO split command in subcommands by space

      $children = (string) count($this->Client->Process::$children);
      return match ($command) {
         // ! Client
         'quit' =>
            $this->log(
               '@\;Stopping ' . $children . ' worker(s)... ',
               self::LOG_WARNING_LEVEL
            )
            && $this->Client->Process->sendSignal(SIGINT) // @phpstan-ignore-line
            && false,

         'clear' =>
            $this->clear() && true, // @phpstan-ignore-line
         'help' =>
            $this->help() && true, // @phpstan-ignore-line

         default => true
      };
   }
   public function help (): true
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
