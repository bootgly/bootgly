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
   // * Config
   public array $includes;

   // * Data
   // ...

   // * Meta
   // ! Escapeable
   public const _START_ESCAPE = "\033[";

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

      // @ Instance
      self::$Commands = new Commands;
      self::$Terminal = new Terminal;
   }

   public function construct () : bool
   {
      // @ Validate include
      $script = $_SERVER['PWD'] . DIRECTORY_SEPARATOR . $_SERVER['SCRIPT_FILENAME'];
      $included = array_search($script, $this->includes['scripts']);
      if ($included === false) {
         return false;
      }

      // @ Extract variables
      // TODO extract dinamically
      $Commands = self::$Commands;
      $Terminal = self::$Terminal;

      // @ Load CLI constructor
      $file = 'CLI.constructor.php';
      $vars = [
         'Commands' => $Commands,
         'Terminal' => $Terminal
      ];

      // Multi projects || Single project
      $projects = Project::PROJECTS_DIR . $file;
      $project = Project::PROJECT_DIR . $file;

      return Bootgly::extract($projects, $vars) || Bootgly::extract($project, $vars);
   }
}
