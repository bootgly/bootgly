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
   public const BOOT_FILE = 'CLI.boot.php';

   // * Config
   public static bool $interactive = false;

   // * Data
   // ...

   // * Metadata
   private static bool $booted = false;

   public static Commands $Commands;
   public static Scripts $Scripts;
   public static Terminal $Terminal;


   public function __construct ()
   {
      if (PHP_SAPI !== 'cli')
         throw new \Exception("CLI class can only be instantiated using SAPI CLI.");

      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      // ...


      // @
      // Debugging Vars
      Vars::$debug = true;
      Vars::$exit = false;

      // @ Instance variables
      $Commands = self::$Commands = new Commands;
      $Scripts  = self::$Scripts  = new Scripts;
      $Terminal = self::$Terminal = new Terminal;

      // @ Validate scripts
      $status = $Scripts->validate();
      switch ($status) {
         case -2:
            break;
         case -1:
            throw new \Exception(
               "Invalid script: script not registered in bootstrap file!"
            );
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
