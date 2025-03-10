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


use Bootgly\ABI\Debugging\Data\Vars;

use Bootgly\API\Projects;

use Bootgly\CLI\Commands;
use Bootgly\CLI\Scripts;
use Bootgly\CLI\Terminal;


class CLI extends Projects // Command Line Interface
{
   public const string BOOT_FILE = 'CLI.boot.php';

   // * Config
   public static bool $interactive = false;

   // * Data
   // ...

   // * Metadata
   private static bool $booted = false;

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
      $this->Commands = new Commands;
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
            throw new \Exception(<<<MESSAGE
               Invalid script: script `{$Scripts->filename}` not registered in bootstrap file!
               Please, register it in `scripts/@.php`.
               MESSAGE
            );
         case 0: // @ Running external script
            break;
         default: // @ Boot CLI
            // Consumer
            if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
               self::$booted = (@include Projects::CONSUMER_DIR . 'Bootgly/' . self::BOOT_FILE);
            }
            // Author
            if (self::$booted === false) {
               require(Projects::AUTHOR_DIR . 'Bootgly/' . self::BOOT_FILE);
            }
      }
   }
}
