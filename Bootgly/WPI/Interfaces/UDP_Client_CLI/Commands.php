<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use const SIGINT;
use function count;

use Bootgly\ACI\Logs\Logger;
use Bootgly\CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI as Client;


class Commands extends CLI\Terminal
{
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


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

      $children = (string) count($this->Client->Process->Children->PIDs);
      return match ($command) {
         // ! Client
         'quit' =>
            $this->Logger->log(
               warning: "@\\;Stopping $children worker(s)... "
            )
            && $this->Client->Process->Signals->send(SIGINT)
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
      $this->Logger->log(debug: <<<'OUTPUT'
      @\;======================================================================
      @:i: `quit` @;        = Close the Client and Stop all workers;

      @:i: `clear` @;       = Clear this console screen;
      ========================================================================

      OUTPUT);

      return true;
   }
}
