<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\data;


use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Bootgly\ABI\Data\__String\Path;


class Dir extends Path
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
   protected string $Dir;

   // * Meta
   protected bool $constructed = false;
   // @ Access
   protected bool $writable;


   public function __get (string $name)
   {
      switch ($name) {
         // * Meta
         // @ Access
         case 'writable':
            $Dir = $this->Dir ?? '';
            return $this->writable = is_writable($Dir);
      }
   }
   public function __call (string $name, array $arguments)
   {
      switch ($name) {
         case 'scan':
            return self::scan($this->Dir, ...$arguments);
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
      return $this->Dir ?? '';
   }

   public function construct (string $path) : string
   {
      if ($this->constructed) {
         return '';
      }
      if ($path === '') {
         return '';
      }

      // @
      $Path = parent::construct($path);

      if ($Path === '') {
         return '';
      }

      if ($this->convert && $this->real) {
         if (is_file($Path) === true) {
            $Path = dirname($Path, 1) . DIRECTORY_SEPARATOR;
         } else if ($Path[-1] !== DIRECTORY_SEPARATOR) {
            $Path .= DIRECTORY_SEPARATOR;
         }
      }

      if ($this->validate) {
         if ($this->real && is_dir($Path) === false) {
            $Path = '';
         }

         if ($Path[-1] !== DIRECTORY_SEPARATOR) {
            $Path = '';
         }
      }

      $this->constructed = true;

      return $this->Dir = $Path;
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
