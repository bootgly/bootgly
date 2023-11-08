<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API;


use Bootgly\ABI\Resources;


abstract class Projects implements Resources
{
   // Author
   public const AUTHOR_DIR   = BOOTGLY_ROOT_BASE . '/projects/';
   // Consumer
   public const CONSUMER_DIR = BOOTGLY_WORKING_BASE . '/projects/';

   // * Data
   protected static array $projects = [];

   // * Meta
   private static array $indexes = [];


   public static function add (Project $Project) : int
   {
      $index = count(self::$projects);

      self::$projects[$index] = $Project;

      return $index;
   }
   public static function boot () : bool
   {
      ${'@'} = include(self::CONSUMER_DIR . '@.php');

      if (${'@'} === null) {
         return false;
      }

      $projects = ${'@'}['projects'];
      foreach ($projects as $project) {
         foreach ($project['paths'] as $path) {
            $Project = new Project;
            $Project->construct($path);
            self::add($Project);
         }
      }

      return true;
   }

   public static function count () : int
   {
      return count(self::$projects);
   }

   public static function index (string $project) : bool
   {
      if ($project === '') {
         return false;
      }
      if (isSet(self::$indexes[$project]) === true) {
         return false;
      }

      self::$indexes[$project] = count(self::$projects) - 1;

      return true;
   }
   public static function select (string|int $project) : false|Project
   {
      if (is_string($project)) {
         $project = self::$indexes[$project] ?? null;
      }

      $Project = self::$projects[$project] ?? false;

      return $Project;
   }
}
