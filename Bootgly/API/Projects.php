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
   // _Dir
   // Author
   public const AUTHOR_DIR   = BOOTGLY_ROOT_BASE . '/projects/';
   // Consumer
   public const CONSUMER_DIR = BOOTGLY_WORKING_BASE . '/projects/';

   // * Data
   protected static array $projects = [];

   // * Meta
   private static array $indexes = [];
   // @autoboot
   private static array $booted = [];


   public static function add (Project $Project) : int
   {
      $index = count(self::$projects);

      self::$projects[$index] = $Project;

      return $index;
   }
   protected static function autoboot (string $_dir) : bool
   {
      if ( isSet(self::$booted[$_dir]) ) {
         return false;
      }

      $bootstrap = include($_dir . '@.php');
      if ($bootstrap === null) {
         return false;
      }

      $interface = substr(strrchr(static::class, '\\'), 1);
      $projects = $bootstrap['projects'][$interface];

      foreach ($projects as $project) {
         $Project = new Project;

         foreach ($project['paths'] as $path) {
            $Project->construct($path);
         }

         self::add($Project);

         if ($name = $project['name'] ?? false) {
            $Project->name($name);
            self::index($name);
         }
      }

      self::$booted[$_dir] = true;

      return true;
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

   public static function count () : int
   {
      return count(self::$projects);
   }
   public static function select (null|string|int $project) : false|Project
   {
      if (is_string($project)) {
         $project = self::$indexes[$project] ?? null;
      }

      $Project = self::$projects[$project] ?? false;

      return $Project;
   }
}
