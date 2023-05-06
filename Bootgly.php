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


abstract class Bootgly // TODO move
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
      // @ Load Bootgly constructor
      // Multi projects
      $projects = BOOTGLY_WORKABLES_BASE . '/projects/bootgly.constructor.php';
      if ( is_file($projects) ) {
         return require $projects;
      }

      // Single project
      $project = BOOTGLY_WORKABLES_BASE . '/project/bootgly.constructor.php';
      if ( is_file($project) ) {
         return require $project;
      }

      // TODO warning or error?

      return false;
   }
   public static function debug ()
   {
      // TODO
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
