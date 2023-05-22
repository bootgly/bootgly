<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


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
   // ...

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

      // @ Instance
      $Commands = self::$Commands = new Commands;
      $Terminal = self::$Terminal = new Terminal;

      // @ Validate include
      $script = $_SERVER['PWD'] . DIRECTORY_SEPARATOR . $_SERVER['SCRIPT_FILENAME'];
      $included = array_search($script, $this->includes['scripts']);
      if ($included === false) {
         return;
      }

      // @ Load constructor file + extract vars
      $vars = [
         'Commands' => $Commands,
         'Terminal' => $Terminal
      ];

      // @ Author
      // TODO
      $projects = Project::BOOTGLY_PROJECTS_DIR . self::BOOT_FILE;
      Bootgly::extract($projects, $vars);
      // TODO
      // @ Consumer
      if (BOOTGLY_DIR !== BOOTGLY_WORKABLES_DIR) {
         // Multi projects || Single project
         $projects = Project::PROJECTS_DIR . self::BOOT_FILE;
         $project = Project::PROJECT_DIR . self::BOOT_FILE;

         Bootgly::extract($projects, $vars) || Bootgly::extract($project, $vars);
      }
   }
}
