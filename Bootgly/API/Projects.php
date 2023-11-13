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
   // @ Environment
   // Author
   public const AUTHOR_DIR   = BOOTGLY_ROOT_BASE . '/projects/';
   // Consumer
   public const CONSUMER_DIR = BOOTGLY_WORKING_BASE . '/projects/';

   // * Data
   protected static array $projects = [];

   // * Meta
   private static bool $booted = false;
   private static array $indexes = [];


   public static function add (Project $Project) : int
   {
      $index = count(self::$projects);

      self::$projects[$index] = $Project;

      return $index;
   }
   protected static function autoboot (string $environment) : bool
   {
      if (self::$booted) {
         return false;
      }

      ${'@'} = include($environment . '@.php');
      if (${'@'} === null) {
         return false;
      }

      $projects = ${'@'}['projects'];
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

      self::$booted = true;

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
