<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\templates\Template;
use Bootgly\ABI\streams\File;

use Bootgly\ACI\Debugger;
use Bootgly\ACI\Logs\Logger;

use Bootgly\API\Project;


class Bootgly
{
   public const BOOT_FILE = 'Bootgly.constructor.php';

   public static Project $Project;
   public static Template $Template;


   public function __construct ()
   {
      // @ Instance
      $Project = static::$Project = new Project;
      $Template = static::$Template = new Template;

      // ---

      // @ Boot
      // Author
      if (BOOTGLY_DIR === BOOTGLY_WORKING_DIR) {
         @include Project::BOOTGLY_PROJECTS_DIR . self::BOOT_FILE;
      }
      // Consumer
      if (BOOTGLY_DIR !== BOOTGLY_WORKING_DIR) {
         // Multi projects
         @include Project::PROJECTS_DIR . self::BOOT_FILE;
      }
   }

   public static function boot (string $file, array $vars) : bool
   {
      if ( is_file($file) ) {
         $boot = static function ($__file__, $__vars__)
         {
            extract($__vars__);
            @include $__file__;
         };

         $boot($file, $vars);

         return true;
      }

      return false;
   }
   // TODO TEMP
   public static function export ($expression, bool $return = false)
   {
      $export = var_export($expression, TRUE);

      #$export = preg_replace("/^([\s]*)(.*)/m", '$1$1$2', $export);

      $array = preg_split("/\r\n|\n|\r/", $export);
      $array = preg_replace(
         pattern: ["/\s*array\s\($/", "/\)(,)?$/", "/\s=>\s$/"],
         replacement: [null, ']$1', ' => ['],
         subject: $array
      );

      $export = join(PHP_EOL, array_filter(["["] + $array));

      if ($return) {
         return $export;
      } else {
         echo $export;
      }
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
   public static function log ($data) : Logger
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
