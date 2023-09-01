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


use Throwable;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

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

   // * Data
   public Path $Path;
   protected readonly string|false $dir;

   // * Meta
   // _ Access
   protected int|false $permissions; // 0644
   protected bool $writable;


   public function __construct (string $path)
   {
      // * Data
      $this->Path = new Path($path);
   }
   public function __get (string $name)
   {
      if ($name === 'dir') {
         return $this->dir ?? false;
      }
      if ( isSet($this->$name) ) {
         return $this->$name;
      }

      // @ Construct $this->dir
      if (isSet($this->dir) === false) {
         $this->pathify();
      }

      // Only if $this->dir was successfully constructed
      $dir = $this->dir ?? false;
      if ($dir === '' || $dir === false) {
         return false;
      }

      switch ($name) {
         // * Data
         // ...
         // * Meta
         // _ Access
         case 'permissions':
            $permissions = fileperms($dir);

            $permissions = substr(sprintf('%o', $permissions), -4);

            // @ Convert to octal and return
            return $this->permissions = intval($permissions, 8);
         case 'writable':
            return $this->writable = is_writable($dir);
      }
   }
   public function __set (string $name, $value)
   {
      // @ Construct $this->dir
      if (isSet($this->dir) === false) {
         $this->pathify();
      }

      // Only if $this->dir was successfully constructed
      $dir = $this->dir ?? false;
      if ($dir === '' || $dir === false) {
         return false;
      }

      switch ($name) {
         // * Data
         // ...
         // * Meta
         // _ Access
         case 'permissions':
            $changed = chmod($dir, $value);

            if ($changed) {
               $this->permissions = $value;
            }

            break;
      }
   }
   public function __call (string $name, array $arguments)
   {
      // Path
      if (isSet($this->dir) === false) {
         $this->pathify();
      }

      // Only if $this->dir was successfully constructed
      $dir = $this->dir ?? false;
      if ($dir === '' || $dir === false) {
         return false;
      }

      switch ($name) {
         case 'scan':
            return self::scan($dir, ...$arguments);
         default:
            return null;
      }
   }
   public static function __callStatic (string $name, $arguments)
   {
      if ( method_exists(__CLASS__, $name) ) {
         return self::$name(...$arguments);
      }

      return null;
   }

   public function __toString () : string
   {
      // Path
      if (isSet($this->dir) === false) {
         $this->pathify();
      }

      return $this->dir ?? '';
   }

   private function pathify () : string
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

   public function create (int $permissions = 0775, bool $recursively = true) : bool
   {
      // * Data
      $basedir = $this->Path->path;

      // @
      try {
         $created = mkdir($basedir, $permissions, $recursively);
      } catch (Throwable) {
         $created = false;
      }

      return $created;
   }
   private static function scan (string $dir, bool $recursive = false) : array
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
            $path = $SplFileInfo->getPathname();

            if ( $SplFileInfo->isDir() ) {
               $path .= DIRECTORY_SEPARATOR;
            }

            $paths[] = $path;
         }
      } else {
         $results = scandir($dir);

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
