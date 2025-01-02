<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\FS;


use function chmod;
use function fileperms;
use function intval;
use function sprintf;
use function substr;
use Throwable;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use SplFileInfo;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS;


class Dir implements FS
{
   // * Config
   /**
    * Convert to directory if possible.
    */
   public bool $convert = true;
   /**
    * Validate if is directory.
    */
   public bool $validate = true;
   // # Access
   /**
    * The permissions of the directory in octal (0644) format or false if not available.
    * Note that permissions is not automatically assumed to be an octal value, so to ensure the expected operation, you need to prefix permissions with a zero (0).
    */
   public null|int|false $permissions {
      get {
         // ?:
         if (isSet($this->permissions) === true) {
            return $this->permissions;
         }

         if (isSet($this->dir) === false) {
            $this->pathify();
         }

         $permissions = fileperms($this->dir);

         $permissions = substr(sprintf('%o', $permissions), -4);

         // @ Convert to octal and return
         return $this->permissions = intval($permissions, 8);
      }
      set {
         if (isSet($this->dir) === false) {
            $this->pathify();
         }

         $changed = chmod($this->dir, (int) $value);

         $value = intval($value, 8);

         $this->permissions = $changed ? $value : false;
      }
   }

   // * Data
   public Path $Path;
   public protected(set) string $dir {
      get {
         if (isSet($this->dir) === false) {
            $this->pathify();
         }

         return $this->dir;
      }
   }

   // * Metadata
   // # Access
   public bool $writable {
      get {
         return is_writable($this->dir);
      }
   }


   public function __construct (string $path)
   {
      // * Data
      $this->Path = new Path($path);
   }
   /**
    * @param string $name
    * @param array<mixed> $arguments
    *
    * @return mixed
    */
   public function __call (string $name, array $arguments): mixed
   {
      switch ($name) {
         case 'scan':
            /**
             * @var bool $recursive
             */
            $recursive = $arguments[0] ?? $arguments['recursive'];

            return self::scan($this->dir, $recursive);
         default:
            return null;
      }
   }
   /**
    * @param string $name
    * @param array<mixed> $arguments
    *
    * @return mixed
    */
   public static function __callStatic (string $name, array $arguments): mixed
   {
      if ( method_exists(__CLASS__, $name) ) {
         return self::$name(...$arguments);
      }

      return null;
   }

   public function __toString (): string
   {
      return $this->dir;
   }

   private function pathify (): string
   {
      // ?
      $Path = $this->Path;

      $path = $Path->path;

      if ($path === '') {
         return $this->dir = '';
      }

      // * Config
      $Path->real = true;

      // @
      if ($this->convert) {
         if (is_file($path) === true) {
            $path = dirname($path, 1) . DIRECTORY_SEPARATOR;
         } else if ($path[-1] !== DIRECTORY_SEPARATOR) {
            $path .= DIRECTORY_SEPARATOR;
         }
      }

      if ($this->validate) {
         if (is_dir($path) === false) {
            $path = '';
         }

         if ($path[-1] !== DIRECTORY_SEPARATOR) {
            $path = '';
         }
      }

      return $this->dir = $path;
   }

   public function create (int $permissions = 0775, bool $recursively = true): bool
   {
      // * Data
      $basedir = $this->Path->path;

      // @
      try {
         $created = @mkdir($basedir, $permissions, $recursively);
      } catch (Throwable) {
         $created = false;
      }

      return $created;
   }
   /**
    * @param string $dir
    * @param bool $recursive
    *
    * @return array<string>
    */
   private static function scan (string $dir, bool $recursive = false): array
   {
      if ($dir === '') {
         return [];
      }

      $paths = [];

      if ($recursive) {
         $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD
         );

         $paths[] = $dir;

         foreach ($iterator as $SplFileInfo) {
            if ($SplFileInfo instanceof SplFileInfo === false) {
               continue;
            }

            $path = $SplFileInfo->getPathname();

            if ( $SplFileInfo->isDir() ) {
               $path .= DIRECTORY_SEPARATOR;
            }

            $paths[] = $path;
         }
      }
      else {
         $results = scandir($dir);

         if ($results === false) {
            return [];
         }

         foreach ($results as $relative) {
            if ($relative === '.' || $relative === '..') {
               continue;
            }

            $path = $dir . $relative;

            if ( is_dir($path) ) {
               $path .= DIRECTORY_SEPARATOR;
            }

            $paths[] = $path;
         }
      }

      return $paths;
   }
}
