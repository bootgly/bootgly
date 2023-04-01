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


abstract class Bootgly
{
   // * Meta
   public readonly bool $booted;

   public static Project $Project;
   public static Template $Template;


   public static function boot ()
   {
      $Project = self::$Project = new Project;
      $Template = self::$Template = new Template;


      // @ Load Bootgly constructor
      $projects = HOME_BASE . '/projects/bootgly.constructor.php';
      // Multi projects
      if ( is_file($projects) ) {
         return require $projects;
      }

      // Single project
      $project = HOME_BASE . '/project/bootgly.constructor.php';
      if ( is_file($project) ) {
         return require $project;
      }

      // TODO warning or error?

      return false;
   }

   public static function debug (bool $status)
   {
      // @ PHP
      match ($status) {
         true => error_reporting(E_ALL) && ini_set('display_errors', 'On'),
         false => error_reporting(0) && ini_set('display_errors', 'Off')
      };

      return true;
   }
}
