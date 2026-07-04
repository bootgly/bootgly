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
use function array_values;
use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;
use function mkdir;
use function rename;
use function rtrim;
use function scandir;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_replace;
use function strlen;
use function strtr;

use function Bootgly\ABI\copy_recursively;
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
    * Check a project path against the path-safety rules.
    *
    * Rejects path traversal, absolute paths, backslashes, null bytes, double
    * slashes and trailing slashes. Membership in the registry allow-list is
    * NOT checked here — that is `validate()`'s job.
    *
    * @param string $path
    *
    * @return bool
    */
   public static function check (string $path): bool
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

      // :
      return true;
   }

   /**
    * Validate a project path against the security boundary.
    *
    * Applies the path-safety rules (`check()`), then requires the path to be
    * an exact key of the registry allow-list. This is the only gate for
    * starting a project.
    *
    * @param string $path
    *
    * @return bool
    */
   public static function validate (string $path): bool
   {
      // ? Path-safety
      if (self::check($path) === false) {
         return false;
      }

      // ?: Allow-list membership (the real boundary)
      return array_key_exists($path, self::read());
   }

   /**
    * Register a project path in the registry allow-list (`Bootgly.projects.php`).
    *
    * The whole registry file is deterministically re-emitted: current entries
    * are loaded, the new entry is inserted, keys are sorted and the file is
    * rewritten atomically with the canonical header. The registry format is
    * machine-managed — hand-added comments inside the array are not preserved.
    *
    * @param string $path
    * @param array{interfaces?:array<string>,default?:bool} $meta
    * @param null|string $file Registry file (defaults to the consumer registry).
    *
    * @return bool
    */
   public static function register (string $path, array $meta, null|string $file = null): bool
   {
      // ? Path-safety
      if (self::check($path) === false) {
         return false;
      }
      // ? Interfaces are required
      $interfaces = $meta['interfaces'] ?? [];
      if ($interfaces === []) {
         return false;
      }

      // !
      $file ??= self::CONSUMER_DIR . 'Bootgly.projects.php';

      // @ Load the current registry
      $loaded = is_file($file) ? include $file : [];
      /** @var array<string,array{interfaces?:array<string>,default?:bool}> $registry */
      $registry = is_array($loaded) ? $loaded : [];

      // @ Insert the entry (alphabetical order for readability)
      $entry = ['interfaces' => array_values($interfaces)];
      if (($meta['default'] ?? false) === true) {
         $entry['default'] = true;
      }
      $registry[$path] = $entry;
      ksort($registry);

      // @ Re-emit the whole file
      $written = self::write($file, $registry);

      // @ Reset the registry cache
      self::$registry = null;

      // :
      return $written;
   }

   /**
    * Import a project available on disk into the consumer projects directory.
    *
    * The source directory must carry the Bootgly project signature — a
    * `*.project.php` file at its root. The project is copied, its signature
    * file is renamed to the new leaf and the path is registered in the
    * registry allow-list. The imported content is kept as-is.
    *
    * @param string $source Source project directory (platform project or fetched clone).
    * @param string $path Canonical target project path (e.g. `App/API`).
    * @param array{interfaces?:array<string>,default?:bool} $meta
    * @param null|string $base Projects base directory (defaults to the consumer directory).
    *
    * @return bool
    */
   public static function import (string $source, string $path, array $meta, null|string $base = null): bool
   {
      // !
      $source = rtrim($source, '/');
      $base ??= self::CONSUMER_DIR;

      // ? Source must be a Bootgly project (signature: `*.project.php` at its root)
      if (is_dir($source) === false) {
         return false;
      }
      $signatures = glob("{$source}/*.project.php");
      if ($signatures === false || $signatures === []) {
         return false;
      }
      $leaf = basename($signatures[0], '.project.php');

      // ? Target path-safety + collision
      if (self::check($path) === false) {
         return false;
      }
      $target = "{$base}{$path}";
      if (is_dir($target) === true) {
         return false;
      }

      // @ Copy the project
      $parent = dirname($target);
      if (is_dir($parent) === false) {
         mkdir($parent, 0755, true);
      }
      copy_recursively($source, $target);

      // @ Rename the signature file to the new leaf
      $newLeaf = basename($path);
      $file = "{$target}/{$newLeaf}.project.php";
      if ($leaf !== $newLeaf) {
         rename("{$target}/{$leaf}.project.php", $file);

         // @ Best-effort: rename old leaf references inside the project file
         $content = file_get_contents($file);
         if ($content !== false) {
            file_put_contents($file, str_replace($leaf, $newLeaf, $content));
         }
      }

      // : Register in the allow-list
      return self::register($path, $meta, "{$base}Bootgly.projects.php");
   }

   /**
    * Generate a project from a stub directory into the consumer projects directory.
    *
    * Copies the given stub directory, substitutes the metadata tokens and
    * registers the path in the registry allow-list. Which stub to use is the
    * caller's decision — this layer only copies, fills and registers.
    *
    * @param string $source Stub directory to copy.
    * @param string $path Canonical target project path (e.g. `App/API`).
    * @param array{interfaces?:array<string>,default?:bool,name?:string,description?:string,version?:string,author?:string,port?:int|string} $meta
    * @param null|string $base Projects base directory (defaults to the consumer directory).
    *
    * @return bool
    */
   public static function generate (string $source, string $path, array $meta, null|string $base = null): bool
   {
      // !
      $base ??= self::CONSUMER_DIR;

      // ? Stub source must exist
      if (is_dir($source) === false) {
         return false;
      }
      // ? Target path-safety
      if (self::check($path) === false) {
         return false;
      }
      // ? Interfaces are required
      $interfaces = $meta['interfaces'] ?? [];
      if ($interfaces === []) {
         return false;
      }
      // ? Target collision
      $target = "{$base}{$path}";
      if (is_dir($target) === true) {
         return false;
      }

      // ! Metadata tokens
      $leaf = basename($path);
      $tokens = [
         '__NAME__'        => (string) ($meta['name'] ?? $leaf),
         '__PATH__'        => $path,
         '__LEAF__'        => $leaf,
         '__DESCRIPTION__' => (string) ($meta['description'] ?? ''),
         '__VERSION__'     => (string) ($meta['version'] ?? '1.0.0'),
         '__AUTHOR__'      => (string) ($meta['author'] ?? ''),
         '__PORT__'        => (string) ($meta['port'] ?? 8080),
      ];

      // @ Copy the stub + fill the tokens
      $parent = dirname($target);
      if (is_dir($parent) === false) {
         mkdir($parent, 0755, true);
      }
      copy_recursively($source, $target);
      self::fill($target, $tokens);

      // : Register in the allow-list
      $entry = ['interfaces' => array_values($interfaces)];
      if (($meta['default'] ?? false) === true) {
         $entry['default'] = true;
      }
      return self::register($path, $entry, "{$base}Bootgly.projects.php");
   }

   /**
    * Re-emit the registry file with the canonical header, atomically.
    *
    * @param string $file
    * @param array<string,array{interfaces?:array<string>,default?:bool}> $registry
    *
    * @return bool
    */
   private static function write (string $file, array $registry): bool
   {
      // ! Key column width
      $width = 0;
      foreach ($registry as $path => $meta) {
         $length = strlen($path) + 2;
         if ($length > $width) {
            $width = $length;
         }
      }

      // @ Emit the entries
      $entries = '';
      foreach ($registry as $path => $meta) {
         $key = str_pad("'{$path}'", $width);
         $interfaces = implode("', '", $meta['interfaces'] ?? []);
         $default = ($meta['default'] ?? false) === true ? ", 'default' => true" : '';

         $entries .= "   {$key} => ['interfaces' => ['{$interfaces}']{$default}],\n";
      }

      $content = <<<REGISTRY
      <?php
      /*
       * --------------------------------------------------------------------------
       * Bootgly PHP Framework
       * Developed by Rodrigo Vieira (@rodrigoslayertech)
       * Copyright 2023-present
       * Licensed under MIT
       * --------------------------------------------------------------------------
       */

      // Unified project registry — the security allow-list.
      //
      // Only the project paths declared here may be started (`bootgly project <path> start`)
      // or autobooted by the Web platform. Each key is the project's canonical path,
      // relative to this `projects/` directory, at any depth (subprojects). Each value
      // binds the project to one or more interfaces:
      //   - CLI → Console platform
      //   - WPI → Web platform (the entry flagged `'default' => true` is the web SAPI default)

      // Kept in alphabetical order by project path. This file is machine-managed by
      // `bootgly project create/import` — hand-added comments inside the array are
      // not preserved.
      return [
      {$entries}];

      REGISTRY;

      // @ Write atomically (tmp + rename)
      $tmp = "{$file}.tmp";
      if (file_put_contents($tmp, $content) === false) {
         return false;
      }

      // :
      return rename($tmp, $file);
   }

   /**
    * Walk a generated project: rename `__LEAF__` path segments and substitute
    * the metadata tokens inside every file.
    *
    * @param string $target
    * @param array<string,string> $tokens
    *
    * @return void
    */
   private static function fill (string $target, array $tokens): void
   {
      $paths = scandir($target);
      // ?
      if ($paths === false) {
         return;
      }

      // @@
      foreach ($paths as $entry) {
         if ($entry === '.' || $entry === '..') {
            continue;
         }

         $path = "{$target}/{$entry}";

         // @ Rename entries carrying the __LEAF__ token
         if (str_contains($entry, '__LEAF__') === true) {
            $renamed = "{$target}/" . strtr($entry, ['__LEAF__' => $tokens['__LEAF__']]);
            rename($path, $renamed);
            $path = $renamed;
         }

         if (is_dir($path) === true) {
            self::fill($path, $tokens);

            continue;
         }

         // @ Substitute tokens
         $content = file_get_contents($path);
         if ($content === false) {
            continue;
         }
         file_put_contents($path, strtr($content, $tokens));
      }
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
