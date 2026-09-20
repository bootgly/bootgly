<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_WORKING_BASE;
use const BOOTGLY_WORKING_DIR;
use const DIRECTORY_SEPARATOR;
use function array_pop;
use function explode;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function str_replace;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use Bootgly\ABI\IO\FS\File;


class Scripts
{
   public const ROOT_BASE = BOOTGLY_ROOT_BASE . '/scripts';
   public const WORKING_BASE = BOOTGLY_WORKING_BASE . '/scripts';

   public const ROOT_DIR = BOOTGLY_ROOT_BASE . '/scripts/';
   public const WORKING_DIR = BOOTGLY_WORKING_BASE . '/scripts/';

   /**
    * Every script group a bootstrap file may declare.
    *
    * @var array<int,string>
    */
   public const GROUPS = ['bootstrap', 'built-in', 'imported', 'user'];

   // * Config
   // ...

   // * Data
   /** @var array<string,array<string|array<string>>> */
   protected array $includes;
   /** @var array<string> */
   protected array $scripts;

   // * Metadata
   // @ Validating
   private null|string $path;
   private null|string $filename;
   private int $validation;


   public function __construct ()
   {
      // * Config
      // ...
      // * Data
      $this->includes = [
         'paths' => [
            BOOTGLY_ROOT_BASE,
            BOOTGLY_WORKING_BASE,

            BOOTGLY_ROOT_DIR,
            BOOTGLY_WORKING_DIR,

            self::WORKING_BASE,
            self::WORKING_DIR,
         ],
         'filenames' => [
            'bootstrap' => [
               BOOTGLY_ROOT_DIR . 'bootgly',    // absolute (framework)
               BOOTGLY_WORKING_DIR . 'bootgly', // absolute (consumer wrapper — re-exec / reload)
               '/usr/local/bin/bootgly',        // global
               'bootgly'                        // relative
            ]
         ]
      ];
      $this->scripts = [];
      // * Metadata
      $this->path = null;
      $this->filename = null;


      // @ Bootstrap scripts
      $resource_dirs = [
         self::ROOT_DIR
      ];
      if (self::ROOT_DIR !== self::WORKING_DIR) {
         $resource_dirs[] = self::WORKING_DIR;
      }
      // @@ Merge every bootstrap first, group by group. `+=` on the map would
      //    keep the group the framework bootstrap already declared and drop
      //    the consumer's whole group — a project could never add a script.
      //    The `bootstrap` group is intrinsic: it names the entry point itself,
      //    so it is seeded here and stays registered even when no resource dir
      //    carries a bootstrap file — the binary still resolves itself
      /** @var array<string,array<int,string>> $groups */
      $groups = [];
      foreach ($this->includes['filenames'] as $group => $filenames) {
         $groups[$group] = $filenames;
      }
      foreach ($resource_dirs as $dir) {
         // ? Consumer dirs may not have booted their resources yet (fresh kit)
         if (is_file("{$dir}" . BOOTSTRAP_FILENAME) === false) {
            continue;
         }

         $bootstrap = (include $dir . BOOTSTRAP_FILENAME);
         $scripts = is_array($bootstrap)
            ? ($bootstrap['scripts'] ?? null)
            : null;
         if (is_array($scripts) === false) {
            continue;
         }

         foreach ($scripts as $group => $filenames) {
            // ? Only a named group of filenames is a declaration
            if (is_string($group) === false || is_array($filenames) === false) {
               continue;
            }

            foreach ($filenames as $filename) {
               // ? The kit copies the framework `scripts/` template, so a
               //   consumer bootstrap repeats the framework entries verbatim
               if (is_string($filename) && in_array($filename, $groups[$group] ?? [], true) === false) {
                  $groups[$group][] = $filename;
               }
            }
         }
      }
      $this->includes['filenames'] = $groups;

      // ---

      // @@ Register once, from the merged map — registering inside the loop
      //    above would re-register every earlier entry per resource directory
      foreach ($groups as $group => $filenames) {
         // ? Unknown group — nothing to resolve it against
         if (in_array($group, self::GROUPS, true) === false) {
            continue;
         }

         foreach ($filenames as $filename) {
            // ? Consumers (platform repos, packages) run imported scripts from
            //   their own working directory — the Bootgly working directory
            //   falls back to the root when booted via Composer
            if ($group === 'imported') {
               $cwd = getcwd();
               if ($cwd !== false && "$cwd/" !== BOOTGLY_WORKING_DIR) {
                  $this->scripts[] = "$cwd/$filename";
               }
            }

            $this->scripts[] = match ($group) {
               'bootstrap' => $filename,
               'built-in' => self::ROOT_DIR . $filename,
               'imported' => BOOTGLY_WORKING_DIR . $filename,
               'user' => self::WORKING_DIR . $filename
            };
         }
      }
   }
   public function __get (string $name): mixed
   {
      return match ($name) {
         'path' => $this->path,
         'filename' => $this->filename,
         'validation' => $this->validation,
         default => null
      };
   }

   public function validate (): int
   {
      // !
      /** @var string $PWD **/
      $PWD = $_SERVER['PWD'] ?? getcwd();
      /** @var string $SCRIPT_FILENAME **/
      $SCRIPT_FILENAME = $_SERVER['SCRIPT_FILENAME'] ?? '';
      // ---
      $this->path ??= $PWD ?: null;
      $this->filename ??= $SCRIPT_FILENAME ?: null;
      // ?:
      if ($this->path === null || $this->filename === null) {
         return $this->validation = -2;
      }

      // !
      $this->filename = self::normalize($this->filename);

      // @
      // Global scripts (absolute paths)
      if (in_array($this->filename, $this->scripts) !== false) {
         return $this->validation = 1;
      }
      // Local scripts (relative to scripts/ working directory)
      if (in_array($this->path . '/' . $this->filename, $this->scripts) !== false) {
         return $this->validation = 0;
      }

      return $this->validation = -1;
   }

   public static function execute (string $script): void
   {
      $basedirs = [
         self::WORKING_DIR,
         self::ROOT_DIR
      ];

      $found = false;
      foreach ($basedirs as $basedir) {
         $path = $basedir . self::normalize($script);
         $Script = new File($path);

         if ($Script->exists) {
            require $Script->file;
            // TODO register commands, etc.
            $found = true;
            break;
         }
      }

      if ($found === false) {
         throw new \Exception("Script not found: `$script`");
      }
   }

   /**
    * Normalize bootstrap script paths without loading ABI Path.
    */
   private static function normalize (string $path): string
   {
      if ($path === '') {
         return '';
      }

      $directory = $path[-1] === '\\' || $path[-1] === '/';
      $path = match (DIRECTORY_SEPARATOR) {
         '/' => str_replace('\\', '/', $path),
         '\\' => str_replace('/', '\\', $path),
      };

      $parts = explode(DIRECTORY_SEPARATOR, $path);
      $normalized = [];

      if ($path[0] === DIRECTORY_SEPARATOR) {
         $normalized[] = '';
      }

      foreach ($parts as $part) {
         if ($part === '..') {
            array_pop($normalized);
         }
         else if ($part !== '.' && $part !== '') {
            $normalized[] = $part;
         }
      }

      $path = implode(DIRECTORY_SEPARATOR, $normalized);
      if ($directory) {
         $path .= DIRECTORY_SEPARATOR;
      }

      return $path;
   }
}
