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
use Bootgly\API\Project;

use Bootgly\CLI\Commands;
use Bootgly\CLI\Scripts;
use Bootgly\CLI\Terminal;


class CLI // Command Line Interface
{
   public const BOOT_FILE = 'CLI.php';

   // * Config
   // ...

   // * Data
   // ...

   // * Meta
   public static bool $interactive = false;

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
      // Debugging Vars
      Vars::$debug = true;
      Vars::$exit = false;
      // * Data
      // ...

      // * Meta
      // ...

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
      // Author
      @include(Project::AUTHOR_DIR . 'Bootgly/' . self::BOOT_FILE);
      // Consumer
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         // Multi projects
         @include(Project::CONSUMER_DIR . 'Bootgly/' . self::BOOT_FILE);
      }
   }
}
