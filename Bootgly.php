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
