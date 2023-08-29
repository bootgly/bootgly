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
   protected string $path;
   public Path $Path;
   protected readonly string|false $dir;

   // * Meta
   protected bool $constructed = false;
   // _ Access
   protected bool $writable;


   public function __construct (string $path)
   {
      // * Data
      $this->path = $path;
      $this->Path = new Path;
   }
   public function __get (string $name)
   {
      if ( isSet($this->$name) ) {
         return $this->$name;
      }

      // Path
      if ($this->constructed === false) {
         $this->pathify($this->path);
      }

      // Only constructed successfully
      $dir = $this->dir ?? false;

      if ($dir === false) {
         return false;
      }
      switch ($name) {
         // * Data
         case 'dir':
            return $dir;
         // * Meta
         // _ Access
         case 'writable':
            return $this->writable = is_writable($dir);
      }
   }
   public function __call (string $name, array $arguments)
   {
      // Path
      if ($this->constructed === false) {
         $this->pathify($this->path);
      }

      // Only constructed successfully
      $dir = $this->dir ?? false;

      if ($dir === false) {
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
      if ($this->constructed === false) {
         $this->pathify($this->path);
      }

      return $this->dir ?? '';
   }

   private function pathify (string $path) : string
   {
      $this->constructed = true;

      if ($path === '') {
         return $this->dir = '';
      }

      // @
      // | Path
      $Path = $this->Path;
      $Path->real = true;

      $path = $Path->construct($path);

      if ($path === '') {
         return $this->dir = '';
      }

      if ($this->convert) {
         if (is_file($path) === true) {
            $path = dirname($path, 1) . DIRECTORY_SEPARATOR;
         } else if ($path[-1] !== DIRECTORY_SEPARATOR) {
            $path .= DIRECTORY_SEPARATOR;
         }
      }

      if ($this->validate) {
         if ($this->real && is_dir($path) === false) {
            $path = '';
         }

         if ($path[-1] !== DIRECTORY_SEPARATOR) {
            $path = '';
         }
      }

      return $this->dir = $path;
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
