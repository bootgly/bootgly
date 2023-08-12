<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\__String;


use Bootgly\ABI\__Array;


class Path // support to FileSystem Paths only (Linux only)
{
   // separators
   const DIR_  = DIRECTORY_SEPARATOR; // DEPRECATED
   const PATH_ = PATH_SEPARATOR;      // DEPRECATED

   // * Config
   // @ convert
   public bool $convert = false;
   public bool $lowercase = false;
   // @ fix
   public bool $fix = true;
   public bool $dir_ = true;
   public bool $normalize = false;
   // @ valid
   public bool $real = false;

   // * Data
   public $Path = '';

   // * Meta
   // _ Type
   private string|false $type;
   // _ Position
   #private bool $absolute;
   #private bool $relative;
   // _ Status
   private bool $normalized;
   // _ Parts
   // ->Path
   private string $root;
   private string $parent;
   private string $current;
   #private array $parts;
   // ->parts
   #private int $indexes;
   #private object $Index;


   public function __construct (string $path = '')
   {
      if ($path) {
         $this->construct($path);
      }
   }
   public function __get (string $key)
   {
      switch ($key) {
         // * Meta
         // _ Type
         case 'type':
            return $this->type = (new \SplFileInfo($this->Path))->getType();
         // _ Parts
         // ->Path
         case 'root':
            $Path = &$this->Path;

            if ($Path === '') {
               return '';
            }
            if ($Path[0] !== '/') {
               return '';
            }

            $root = strstr($Path, DIRECTORY_SEPARATOR, true);
            $root .= DIRECTORY_SEPARATOR;

            return $this->root = $root;
         case 'parent':
            $parent = '';

            if ($this->Path) {
               $parent = dirname($this->Path);

               if ($parent[-1] !== DIRECTORY_SEPARATOR) {
                  $parent .= DIRECTORY_SEPARATOR;
               }
            }

            return $this->parent = $parent;
         case 'current':
            $current = '';

            $Path = &$this->Path;
            if ($Path) {
               $lastNode = strrchr(haystack: $Path, needle: DIRECTORY_SEPARATOR);

               $current = substr($lastNode, 1);

               if ($current === '') {
                  $current = basename($Path);
               }
            }

            return $this->current = $current;

         case 'parts':
            return self::split($this->Path);
         // ->parts
         case 'indexes':
            return count($this->parts);
         case 'Index':
            return new class ($this->parts)
            {
               // * Meta
               public object $Last;


               public function __construct ($parts)
               {
                  $__Array = new __Array($parts);

                  // * Meta
                  $this->Last = ($__Array)->Last; // ->key, ->value
               }
            };
         // ->relativize()
         // ...
      }

      return @$this->$key;
   }
   public function __call (string $name, array $arguments)
   {
      switch ($name) {
         // ->Path
         case 'normalize':
            $this->normalized = true;
            return $this->Path = self::normalize($this->Path);
         case 'split':
            return self::split($this->Path);
         case 'cut':
            return self::cut($this->Path, ...$arguments);
         case 'relativize':
            return self::relativize($this->Path, ...$arguments);
         // ->parts
         case 'join':
            return self::join($this->parts, ...$arguments);
         case 'concatenate':
            return self::concatenate($this->parts, ...$arguments);
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
      return $this->Path;
   }

   public function construct (string $path) : string
   {
      // * Data
      $Path = '';
      // * Meta
      // _ Type
      // ...dynamically
      // _ Position
      // ...
      // _ Status
      $this->normalized = false;
      // _ Parts
      // ...dynamically

      // @
      if ($path) {
         $Path = $path;

         // @ 1 - convert
         if ($this->convert && $this->lowercase) {
            $Path = strtolower($Path);
         }

         // @ 2 - fix
         if ($this->fix) {
            // Overwrites all directory separators with the standard separator
            if ($this->dir_) {
               $Path = match (DIRECTORY_SEPARATOR) {
                  '/' => str_replace('\\', '/', $Path),
                  '\\' => str_replace('/', '\\', $Path),
                  default => $Path
               };
            }

            // Remove '/./', '/../', '//' in path
            if ($this->normalize) {
               $Path = $this->normalize($Path);
            }
         }

         // @ 3 - valid
         // The resulting path will have no symbolic link, '/./' or '/../'
         if ($this->real) {
            $Path = (string) realpath($Path);
         }
      }

      return $this->Path = $Path;
   }
   public function match (string $path, string $pattern) : bool
   {
      // path: /etc/php/%
      // pattern: '8.*'

      if ($pattern === '') {
         return false;
      }

      if ($this->Path && $path[0] === '/') {
         return false;
      }

      $Path = str_replace(
         search: '%',
         replace: $pattern,
         subject: $this->Path . $path
      );

      $paths = glob($Path);

      if ( $paths !== false && isSet($paths[0]) ) {
         $this->Path = $paths[0]; // Get first path found
      } else {
         return false;
      }

      return true;

      // $this->Path = /etc/php/8.0 or /etc/php/8.1 or /etc/php/8.2...
   }

   public static function normalize ($path) : string
   {
      // $path = '../../etc/passwd';
      $parts = explode(DIRECTORY_SEPARATOR, $path);
      $Parts = [];

      // @ Parse absolute path
      if ($path[0] === DIRECTORY_SEPARATOR) {
         $Parts[] = '';
      }

      foreach ($parts as $part) {
         if ($part === '..') {
            array_pop($Parts);
         } else if ($part !== '.' && $part !== '') {
            array_push($Parts, $part);
         }
      }

      $Path = implode(DIRECTORY_SEPARATOR, $Parts);

      return $Path;
      // return 'etc/passwd';
   }
   public static function split(string $path): array
   {
      // $path = '/var/www/sys/';
      $parts = [];

      if ($path) {
         $path = trim($path, "\x2F"); // /
         $path = trim($path, "\x5C"); // \
         $path = str_replace("\\", "/", $path);

         $parts = explode("/", $path);
      }

      return $parts;
      // return [0 => 'var', 1 => 'www', 2 => 'sys'];
   }
   public static function cut (string $path, int ...$cutting) : string
   {
      // $path = var/www/html/test/;
      // $cutting = [-2, 1];
      if (count($cutting) > 2) {
         return $path;
      }

      foreach ($cutting as $cut) {
         if ($cut === 0) {
            continue;
         }

         // * Meta
         // @ Path
         $parts = explode('/', trim($path, '/'));
         $indexes = count($parts);
         $isAbsolute = $path[0] === '/';
         $isDir = $path[-1] === '/';

         if (abs($cut) === $indexes) {
            return '';
         }

         if ($cut >= 0) { // + Positive <==
            $parts = array_slice($parts, 0, $indexes - $cut);

            if ($isAbsolute) {
               array_unshift($parts, '');
            }
            array_push($parts, '');
         } else { // - Negative ==>
            $parts = array_slice($parts, abs($cut));

            if ($isDir) {
               array_push($parts, '');
            }
         }

         $path = implode('/', $parts);
      }

      return $path;
      // return 'html/';
   }
   public static function relativize (string $path, string $from) : string
   {
      // $path = '/foo/bar/tests/test2.php'
      // $from = '/foo/bar/'
      $path = explode(DIRECTORY_SEPARATOR, $path);
      $from = explode(DIRECTORY_SEPARATOR, $from);

      $length = min(count($from), count($path));
      for ($i = 0; $i < $length; $i++) {
         if ($from[$i] !== $path[$i]) {
            break;
         }
      }

      $rest = implode(DIRECTORY_SEPARATOR, array_slice($path, $i));

      return $rest;
      // return 'tests/test2.php';
   }

   private static function join (array $parts, bool $absolute = false, bool $dir = false) : string
   {
      $path = '';

      if ($absolute) {
         $path .= DIRECTORY_SEPARATOR;
      }

      $path .= implode(DIRECTORY_SEPARATOR, $parts);

      if ($dir) {
         $path .= DIRECTORY_SEPARATOR;
      }

      return $path;
   }
   private static function concatenate (array $parts, int $offset = 0) : string
   {
      $Path = '';

      // * Meta
      $indexes = 0;

      foreach ($parts as $index => $part) {
         if ($index >= $offset) {
            $separator = ($indexes > 0) ? DIRECTORY_SEPARATOR : '';

            $Path .= $separator . $part;

            $indexes++;
         }
      }

      return $Path;
   }
}
