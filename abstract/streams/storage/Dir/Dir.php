<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Bootgly\Path;


class Dir
{
   const DIR_ = DIRECTORY_SEPARATOR;


   public ?Path $Path = null;

   public string $path = '';
   public string $dir = '';

   public string $Dir = '';

   //? @Config
   public bool $check = true;
   public bool $convert = false;
   public bool $clean = false;
   //? @Access
   protected $writable;    // bool || Return if the file is writable


   public function __construct (string $path = null)
   {
      if (! $path) {
         return;
      }

      if ($this->Path === null) {
         $this->Path = new Path($path);
      }

      $Path = &$this->Path;
      if ($Path->Path) {
         $this->path = $path;
         $this->dir = $this->path;

         $this->Dir = $this->Dir($Path->Path);

         if ($this->Dir && $this->Dir !== $this->dir) {
            $this->Path = $Path($this->Dir);

            $this->dir = $Path->parent;
         }
      } else {
         $this->Dir = '';
      }
   }
   public function __get (string $name)
   {
      if (!$this->Dir) {
         return @$this->name;
      }

      switch ($name) {
         case 'writable':
            return $this->writable = is_writable($this->Dir);
      }
   }
   public function __invoke (string $path)
   {
      $this->Dir = '';

      $this->__construct($path);

      return $this->Dir;
   }

   public function __toString () : string
   {
      return $this->Dir;
   }

   public function Dir (string $path) : string
   {
      $this->Dir = '';

      if ($path) {
         if ($this->check && is_dir($path)) {
            // Only check if is Dir
            return $this->Dir = $path;
         }

         if ($this->convert) {
            // Convert base to dir and check if is Dir
            $path_ = $path . self::DIR_;
            if (is_dir($path_)) {
               return $this->Dir = $path_;
            }

            // Convert file to dir and check if is Dir
            if (is_file($path)) {
               return $this->Dir = dirname($path, 1) . self::DIR_;
            }
         }

         if ($this->clean && $this->Dir) {
            // Clean Dir separators to native SO Dir separators
            $this->Dir = rtrim($this->Dir, "\x2F"); // /
            $this->Dir = rtrim($this->Dir, "\x5C"); // \
            return $this->Dir .= self::DIR_;
         }
      }

      return $this->Dir;
   }

   // TODO Refactor this function to reduce its Cognitive Complexity from 19 to the 15 allowed.
   public static function scan (Dir $Dir, bool $recursive = false) : array
   {
      $paths = [];

      if ($Dir->Path) {
         if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
               new \RecursiveDirectoryIterator($Dir->Path, \RecursiveDirectoryIterator::SKIP_DOTS),
               \RecursiveIteratorIterator::SELF_FIRST,
               \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            $paths[] = $Dir->Path;

            foreach ($iterator as $object => $path) {
               if ($path->isDir()) {
                  $paths[] = $path->getPathname() . self::DIR_;
               }
            }
         } else {
            $results = \scandir($Dir->Path);

            foreach ($results as $path) {
               if ($path === '.' || $path === '..') {
                  continue;
               }

               $paths[] = $Dir->Path . $path . self::DIR_;
            }
         }
      }

      return $paths;
   }
   public static function build (string $path, string $prefix)
   {
      // Constants Path
      // TODO
   }

   /*
   public function tree(
      string $path,
      int $floorDirection = -1,
      int $untilLevel = 0,
      bool $reverseTree = false
   ): array // TODO rename
   {
      $this->Tree = [];

      if ($path) {
         $Path = new Path($path);
         $Dir = $Path->Path($Path->dir);

         if (!$Dir or $Dir === $Path->Path(HOME_DIR)) {
            return [];
         }

         if ($untilLevel === 1) {
            $this->Tree = [$Dir];
         } else {
            if ($floorDirection === 1) {
               $this->Tree = self::Scan($Dir, $untilLevel);
            } elseif ($floorDirection === -1) {
               // tempDirTree
               $tempDirTree = [];
               $i = 0;
               foreach (self::split($Dir) as $key => $value) {
                  $tempDirTree[$i] = @end($tempDirTree) . $value . DIR_;
                  $i++;
               }

               // Tree
               if ($untilLevel === 0 or $untilLevel < HOME_LEVELS_DIR) {
                  $untilLevel = HOME_LEVELS_DIR;
               }
               $fromKey = $untilLevel;
               for ($i = 0; $i < count($tempDirTree) - $untilLevel; $i++) {
                  $this->Tree[$i] = $tempDirTree[$fromKey++];
               }
            }

            if ($reverseTree) {
               $this->Tree = array_reverse($this->Tree);
            }
         }
      }

      return $this->Tree;
   }
   */
}
