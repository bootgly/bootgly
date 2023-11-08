<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Debugging;
use Bootgly\ABI\Templates\Template;

use Bootgly\ACI\Logs\Logger;

use Bootgly\API\Project;
use Bootgly\API\Projects;


class Bootgly
{
   public const BOOT_FILE = 'Bootgly.php';

   public static Project $Project;
   public static Template $Template;


   public function __construct ()
   {
      // @ Instance
      $Project = static::$Project = new Project;

      // ---

      // @ Boot
      // Author
      if (BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR) {
         @include Projects::AUTHOR_DIR . self::BOOT_FILE;
      }
      // Consumer
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         // Multi projects
         @include Projects::CONSUMER_DIR . self::BOOT_FILE;
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

   // ABI
   public static function template (string $view) : Template
   {
      $Template = static::$Template = new Template(
         static::$Project . Path::normalize($view) . '.template.php'
      );

      return $Template;
   }

   // ACI
   public static function debug (...$data)
   {
      // TODO
   }
   public static function log ($data) : Logger
   {
      return new Logger($data);
   }
}
