<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\templates\Template;
use Bootgly\streams\File;
use Bootgly\ { Project };
use Bootgly\API\Debugger;
use Bootgly\API\Logs\Logger;


class Bootgly
{
   public const BOOT_FILE = 'Bootgly.constructor.php';

   public static Project $Project;
   public static Template $Template;


   public function __construct ()
   {
      // - Features
      // core
      $Project = static::$Project = new Project;
      $Template = static::$Template = new Template;

      // - Workables
      // @ Load Bootgly constructor
      $vars = [
         'Project' => $Project,
         'Template' => $Template
      ];

      // @ Author
      // TODO
      $projects = Project::BOOTGLY_PROJECTS_DIR . self::BOOT_FILE;
      \Bootgly::boot($projects, $vars);
      // @ Consumer
      if (BOOTGLY_DIR !== BOOTGLY_WORKABLES_DIR) {
         // Multi projects || Single project
         $projects = Project::PROJECTS_DIR . self::BOOT_FILE;
         $project = Project::PROJECT_DIR . self::BOOT_FILE;

         self::boot($projects, $vars) || self::boot($project, $vars);
      }
   }

   public static function boot (string $file, array $vars) : bool
   {
      if ( is_file($file) ) {
         $extract = static function ($__file__, $__vars__)
         {
            extract($__vars__);
            @include $__file__;
         };

         $extract($file, $vars);

         return true;
      }

      return false;
   }

   // API
   public static function debug (...$vars) : Debugger
   {
      if (Debugger::$trace === null) {
         Debugger::$trace = debug_backtrace();
      }

      $Debugger = new Debugger(...$vars);

      if (Debugger::$trace !== false) {
         Debugger::$trace = null;
      }

      return $Debugger;
   }
   public function log ($data) : Logger
   {
      return new Logger($data);
   }

   public static function template (string $view, array $parameters) : Template
   {
      $Template = static::$Template;

      // TODO check Template cache

      $file = static::$Project . $view . '.template.php';
      // @ Load Raw file/string
      $File = new File;
      $File->construct = false;
      $File->convert = false;
      $File($file);

      if ($File->File) {
         $Template->raw = $File->contents;
      } else {
         $Template->raw = $view;
      }

      // @ Set Parameters
      $Template->parameters = $parameters;

      return $Template;
   }
}
