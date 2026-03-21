<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use const PHP_SAPI;
use function is_array;
use Exception;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\API\Projects;
use Bootgly\CLI\Command;
use Bootgly\CLI\Commands;
use Bootgly\CLI\Scripts;
use Bootgly\CLI\Terminal;
use Bootgly\commands\HelpCommand;


class CLI extends Projects // Command Line Interface
{
   // * Config
   public static bool $interactive = false;

   // * Data
   // ...

   // * Metadata
   // ...

   public readonly Commands $Commands;
   public readonly Scripts $Scripts;
   public readonly Terminal $Terminal;


   public function autoboot (): void
   {
      // ?
      if (PHP_SAPI !== 'cli')
         return;

      // @
      // Debugging Vars
      Vars::$debug = true;
      Vars::$exit = false;

      // @ Instance variables
      $Commands = $this->Commands = new Commands;
      $Scripts = $this->Scripts  = new Scripts;
      $this->Terminal = new Terminal;

      // @ Validate scripts
      $status = $Scripts->validate();

      // ---

      // @ Boot CLI
      switch ($status) {
         case -2:
            break;
         case -1:
            // TODO custom bootgly exception
            throw new Exception(<<<MESSAGE
               Invalid script: script `{$Scripts->filename}` not registered in bootstrap file!
               Please, register it in `scripts/@.php`.
               MESSAGE
            );
         case 0: // @ Running external script
            break;
         default: // @ Boot CLI
            // @ Register Help command
            $Commands->register(Command: new HelpCommand, Script: $Commands);

            // @ Register framework commands
            /** @var array<Command> $commands */
            $commands = require(__DIR__ . '/commands/@.php');
            foreach ($commands as $Command) {
               $Commands->register($Command, Script: $this);
            }

            // @ Register consumer commands
            if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
               $consumer_commands = @include(Projects::CONSUMER_DIR . 'Bootgly/commands/@.php');
               if (is_array($consumer_commands)) {
                  /** @var array<Command> $consumer_commands */
                  foreach ($consumer_commands as $Command) {
                     $Commands->register($Command, Script: $this);
                  }
               }
            }

            // @ Route commands
            $Commands->route(From: $this);
      }
   }
}
