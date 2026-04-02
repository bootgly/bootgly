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


use function count;
use function is_string;

use Bootgly\ABI\Resources;
use Bootgly\API\Projects\Project;


abstract class Projects
{
   use Resources;


   // _Dir
   // Author
   public const string AUTHOR_DIR = BOOTGLY_ROOT_BASE . '/projects/';
   // Consumer
   public const string CONSUMER_DIR = BOOTGLY_WORKING_BASE . '/projects/';

   // * Config
   // ...

   // * Data
   /** @var Project[] */
   protected static array $projects = [];

   // * Metadata
   // @ index
   private static int $index = 0;
   /** @var int[] */
   private static array $indexes = [];


   public static function add (Project $Project): int
   {
      $index = count(self::$projects);

      self::$projects[$index] = $Project;

      return $index;
   }

   /**
    * Index a project by name. If the project is already indexed, it will return false.
    *
    * @param string $project
    *
    * @return bool 
    */
   public static function index (string $project): bool
   {
      // ?
      if ($project === '') {
         return false;
      }
      if (isSet(self::$indexes[$project]) === true) {
         return false;
      }

      // @
      $index = count(self::$projects);
      self::$index = $index;
      self::$indexes[$project] = $index;

      return true;
   }

   /**
    * Count the number of projects.
    *
    * @return int 
    */
   public static function count (): int
   {
      return count(self::$projects);
   }
   /**
    * Select a project by index or name. If no project is selected, the default project is selected.
    *
    * @param null|string|int $project 
    *
    * @return Project|false
    */
   public static function select (null|string|int $project = null): Project|false
   {
      // ?!
      if (is_string($project) === true) {
         $project = self::$indexes[$project] ?? null;
      }
      else if ($project === null) {
         $project = self::$index;
      }

      // @ Select by project index
      $Project = self::$projects[$project] ?? false;

      return $Project;
   }
}
