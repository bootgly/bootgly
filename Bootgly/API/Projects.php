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
use function is_dir;
use function is_string;
use Exception;

use Bootgly\ABI\Resources;


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
   private static Project $Default;
   // @ index
   private static int $index = 0;
   /** @var int[] */
   private static array $indexes = [];
   // @autoboot
   private static bool $booted;


   public static function add (Project $Project): int
   {
      $index = count(self::$projects);

      self::$projects[$index] = $Project;

      return $index;
   }
   /**
    * Autoboot projects from the consumer directory. If no projects are found, it will return null.
    *
    * @return null|Project 
    */
   public static function autobooting (): null|Project
   {
      if ( isSet(self::$booted) )
         throw new Exception("Project autoboot can only be called once.");

      // @ Load from consumer first, then author
      $bootstrap = @include(Projects::CONSUMER_DIR . '@.php');
      if ($bootstrap === false || $bootstrap === null) {
         $bootstrap = @include(Projects::AUTHOR_DIR . '@.php');
      }
      if ($bootstrap === false || $bootstrap === null) {
         self::$booted = true;
         return null;
      }

      // @ Resolve default project
      $default = $bootstrap['default'] ?? null;
      if ($default !== null) {
         $Project = new Project;

         // @ Try consumer dir first
         $path = $default . '/';
         $consumerPath = Projects::CONSUMER_DIR . $path;
         $authorPath = Projects::AUTHOR_DIR . $path;

         if (is_dir($consumerPath)) {
            $Project->construct($path);
         }
         else if (is_dir($authorPath)) {
            $Project->construct($path);
         }

         self::add($Project);
         $Project->name($default);
         self::index($default);
         self::$Default = $Project;
      }

      self::$booted = true;

      return self::$Default ?? null;
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
