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
      // TODO move to Scripts?
      $this->includes = [
         'directories' => [
            BOOTGLY_ROOT_DIR,
            BOOTGLY_WORKING_DIR,
         ],
         'filenames' => [
            'bootgly',
            './bootgly', // TODO normalize path
            '/usr/local/bin/bootgly',
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
      $workdir = $_SERVER['PWD'];
      $script = $_SERVER['SCRIPT_FILENAME'];

      $matches = [];
      $matches[0] = array_search($workdir, $this->includes['directories']);
      $matches[1] = array_search($script, $this->includes['filenames']);
      if ($matches[0] === false && $matches[1] === false) {
         return; // TODO output
      }

      // @ Instance
      $Commands = self::$Commands = new Commands;
      $Terminal = self::$Terminal = new Terminal;

      // ---

      // @ Boot
      // Author
      @include Project::BOOTGLY_PROJECTS_DIR . self::BOOT_FILE;
      // Consumer
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         // Multi projects
         @include Project::PROJECTS_DIR . self::BOOT_FILE;
      }
   }
}
