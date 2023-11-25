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

   // * Meta
   private bool $booted;

   public static Commands $Commands;
   public static Scripts $Scripts;
   public static Terminal $Terminal;


   public function __construct ()
   {
      if (PHP_SAPI !== 'cli') {
         return;
      }

      // * Config
      // ...

      // * Data
      // ...

      // * Meta
      $this->booted = false;


      // @
      // Debugging Vars
      Vars::$debug = true;
      Vars::$exit = false;

      // @ Instance variables
      $Commands = self::$Commands = new Commands;
      $Scripts  = self::$Scripts  = new Scripts;
      $Terminal = self::$Terminal = new Terminal;

      // @ Validate scripts
      if ($Scripts->validate() === false) {
         return;
      }

      // ---

      // @ Boot CLI
      self::autoboot(self::CONSUMER_DIR);

      // Consumer
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         $this->booted = (@include Projects::CONSUMER_DIR . 'Bootgly/' . self::BOOT_FILE);
      }

      // Author
      if ($this->booted === false) {
         @include(Projects::AUTHOR_DIR . 'Bootgly/' . self::BOOT_FILE);
      }
   }
}
