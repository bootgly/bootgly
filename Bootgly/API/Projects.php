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


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_WORKING_BASE;
use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function str_contains;
use function str_ends_with;
use function str_replace;

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
   // @ registry
   /** @var null|array<string,array{interfaces?:array<string>,default?:bool}> */
   private static null|array $registry = null;


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

   /**
    * Read and cache the unified project registry (`Bootgly.projects.php`).
    *
    * The consumer registry takes precedence over the framework one. The file
    * returns a project-keyed map where each key is the project's canonical path
    * (relative to the projects root) and each value carries its `interfaces`.
    *
    * @return array<string,array{interfaces?:array<string>,default?:bool}>
    */
   public static function read (): array
   {
      // ?
      if (self::$registry !== null) {
         return self::$registry;
      }

      // @ Consumer dir wins over framework dir
      $file = self::CONSUMER_DIR . 'Bootgly.projects.php';
      if (is_file($file) === false) {
         $file = self::AUTHOR_DIR . 'Bootgly.projects.php';
      }

      $loaded = is_file($file) ? include $file : [];
      /** @var array<string,array{interfaces?:array<string>}> $registry */
      $registry = is_array($loaded) ? $loaded : [];

      // :
      return self::$registry = $registry;
   }

   /**
    * List the registered project paths bound to one interface (`CLI`|`WPI`),
    * preserving the registry declaration order.
    *
    * @param string $interface
    *
    * @return array<string>
    */
   public static function filter (string $interface): array
   {
      // !
      $paths = [];

      // @
      foreach (self::read() as $path => $meta) {
         if (in_array($interface, $meta['interfaces'] ?? [], true)) {
            $paths[] = $path;
         }
      }

      // :
      return $paths;
   }

   /**
    * Pick the default project path for one interface (`CLI`|`WPI`).
    *
    * Returns the entry flagged `'default' => true`; when none is flagged it
    * falls back to the first registered project for that interface. The
    * registry's alphabetical ordering is for readability only — the default
    * is chosen by the flag, not by position. Returns null if the interface
    * has no registered projects.
    *
    * @param string $interface
    *
    * @return null|string
    */
   public static function pick (string $interface): null|string
   {
      // !
      $fallback = null;

      // @
      foreach (self::read() as $path => $meta) {
         if (in_array($interface, $meta['interfaces'] ?? [], true) === false) {
            continue;
         }

         $fallback ??= $path;

         // ?: Explicit default wins over position
         if (($meta['default'] ?? false) === true) {
            return $path;
         }
      }

      // :
      return $fallback;
   }

   /**
    * Validate a project path against the security boundary.
    *
    * Rejects path traversal, absolute paths, backslashes, null bytes, double
    * slashes and trailing slashes, then requires the path to be an exact key of
    * the registry allow-list. This is the only gate for starting a project.
    *
    * @param string $path
    *
    * @return bool
    */
   public static function validate (string $path): bool
   {
      // ? Path-safety
      if ($path === '' || $path[0] === '/' || str_ends_with($path, '/')) {
         return false;
      }
      if (str_contains($path, '..') || str_contains($path, '\\')) {
         return false;
      }
      if (str_contains($path, "\0") || str_contains($path, '//')) {
         return false;
      }

      // ?: Allow-list membership (the real boundary)
      return array_key_exists($path, self::read());
   }

   /**
    * Encode a canonical project path into a filesystem-safe identifier.
    *
    * Used for pid/lock filenames so nested leaves never collide:
    * `Demo/HTTP_Server_CLI` becomes `Demo~HTTP_Server_CLI`.
    *
    * @param string $path
    *
    * @return string
    */
   public static function encode (string $path): string
   {
      return str_replace('/', '~', $path);
   }
}
