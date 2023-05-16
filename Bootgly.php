<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly; // TODO remove namespace


use Bootgly\templates\Template;
use Bootgly\streams\File;


abstract class Bootgly
{
   public static Project $Project;
   public static Template $Template;


   public static function boot ()
   {
      // - Features
      // core
      $Project = static::$Project = new Project;
      $Template = static::$Template = new Template;

      // - Workables
      // @ Load Bootgly boot
      $file = 'Bootgly.boot.php';
      $vars = [
         'Project' => $Project,
         'Template' => $Template
      ];

      // Multi projects || Single project
      $projects = Project::PROJECTS_DIR . $file;
      $project = Project::PROJECT_DIR . $file;

      return self::extract($projects, $vars) || self::extract($project, $vars);
   }
   public static function debug ()
   {
      // TODO
   }

   public static function extract (string $file, array $vars) : bool
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
