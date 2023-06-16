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


use Bootgly\ACI\Debugger;
use Bootgly\API\Project;

use Bootgly\CLI\Commands;
use Bootgly\CLI\Terminal;


class CLI
{
   public const BOOT_FILE = 'CLI.constructor.php';

   // * Config
   public array $includes;

   // * Data
   // ...

   // * Meta
   public static bool $interactive = false;

   public static Commands $Commands;
   public static Terminal $Terminal;


   public function __construct ()
   {
      if (PHP_SAPI !== 'cli') {
         return;
      }

      // * Config
      // TODO move to Bootgly config path
      $this->includes = [
         'scripts' => [
            BOOTGLY_DIR . 'bootgly',
            BOOTGLY_DIR . './bootgly', // TODO normalize path

            BOOTGLY_WORKABLES_DIR . 'bootgly',
            BOOTGLY_WORKABLES_DIR . './bootgly', // TODO normalize path
         ]
      ];
      // Debugger
      Debugger::$debug = true;
      Debugger::$cli = true;
      Debugger::$exit = false;
      // * Data
      // ...

      // * Meta
      // ...

      // @ Validate
      $script = $_SERVER['PWD'] . DIRECTORY_SEPARATOR . $_SERVER['SCRIPT_FILENAME'];
      if (array_search($script, $this->includes['scripts']) === false) {
         return;
      }

      // @ Instance
      $Commands = self::$Commands = new Commands;
      $Terminal = self::$Terminal = new Terminal;

      // ---

      // @ Boot
      // Author
      @include Project::BOOTGLY_PROJECTS_DIR . self::BOOT_FILE;
      // Consumer
      if (BOOTGLY_DIR !== BOOTGLY_WORKABLES_DIR) {
         // Multi projects
         @include Project::PROJECTS_DIR . self::BOOT_FILE;
      }
   }
}
