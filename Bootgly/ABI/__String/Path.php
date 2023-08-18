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
   // * Config
   // @ convert
   public bool $convert = false;
   public bool $lowercase = false;
   // @ fix
   public bool $fix = true;
   public bool $dir_ = true;
   public bool $normalize = false;
   // @ validate
   public bool $real = false;

   // * Data
   protected string $Path;

   // * Meta
   protected bool $constructed = false;
   // _ Type
   protected string|false $type;
   // _ Position
   protected bool $absolute;
   protected bool $relative;
   // _ Status
   protected bool $normalized;
   // _ Parts
   // ->Path
   protected string $root;
   protected string $parent;
   protected string $current;
   #protected array $parts;
   // ->parts
   #protected int $indexes;
   #protected object $Index;


   public function __construct (string $path = '')
   {
      if ($path) {
         $this->construct($path);
      }
   }
   public function __get (string $key)
   {
      switch ($key) {
         case 'Path':
            return $this->Path ?? '';
         // * Meta
         // _ Type
         case 'type':
            if ($this->real === false) {
               return false;
            }
            $Path = $this->Path ?? '';
            return $this->type = (new \SplFileInfo($Path))->getType();
         // _ Position
         case 'absolute':
            $Path = $this->Path ?? '';
            return $this->absolute = $Path[0] === '/';
         case 'relative':
            $Path = $this->Path ?? '';
            return $this->relative = $Path[0] !== '/';
         // _ Status
         case 'normalized':
            return $this->normalized;
         // _ Parts
         // ->Path
         case 'root':
            $Path = $this->Path ?? '';

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

            $Path = $this->Path ?? '';
            if ($Path) {
               $parent = dirname($Path);

               if ($parent[-1] !== DIRECTORY_SEPARATOR) {
                  $parent .= DIRECTORY_SEPARATOR;
               }
            }

            return $this->parent = $parent;
         case 'current':
            $current = '';

            $Path = $this->Path ?? '';
            if ($Path) {
               $lastNode = strrchr(haystack: $Path, needle: DIRECTORY_SEPARATOR);

               $current = substr($lastNode, 1);

               if ($current === '') {
                  $current = basename($Path);
               }
            }

            return $this->current = $current;

         case 'parts':
            $Path = $this->Path ?? '';
            return self::split($Path);
         // ->parts
         case 'indexes':
            return count($this->parts);
         case 'Index':
            $__Array = new __Array($this->parts);

            return (object) [
               'Last' => $__Array->Last
            ];
         default:
            return null;
      }
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
      return (string) $this->Path;
   }

   public function construct (string $path) : string
   {
      if ($this->constructed) {
         return '';
      }
      if ($path === '') {
         return '';
      }

      // * Data
      // @
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

      $this->constructed = true;

      return $this->Path = $Path;
   }
   public function match (string $path, string $pattern) : bool
   {
      // path: /etc/php/%
      // pattern: '8.*'

      if ($pattern === '') {
         return false;
      }

      $Path = $this->Path ?? '';
      if ($Path && $path[0] === '/') {
         return false;
      }

      $pattern = str_replace(
         search: '%',
         replace: $pattern,
         subject: $Path . $path
      );

      $paths = glob($pattern);

      if ( $paths !== false && isSet($paths[0]) ) {
         $this->Path = $paths[0]; // Get first path found
      } else {
         return false;
      }

      return true;

      // /etc/php/8.0 or /etc/php/8.1 or /etc/php/8.2...
   }

   public static function normalize ($path) : string
   {
      // $path = '../../etc/passwd';
      $parts = explode(DIRECTORY_SEPARATOR, $path); // TODO use self::split?
      $normalizeds = [];

      // @ Parse absolute path
      if ($path[0] === DIRECTORY_SEPARATOR) {
         $normalizeds[] = '';
      }

      foreach ($parts as $part) {
         if ($part === '..') {
            array_pop($normalizeds);
         } else if ($part !== '.' && $part !== '') {
            array_push($normalizeds, $part);
         }
      }

      $normalized = implode(DIRECTORY_SEPARATOR, $normalizeds);

      return $normalized;
      // return 'etc/passwd';
   }
   public static function split (string $path) : array
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
      // $path = [0 => 'var', 1 => 'www', 2 => 'sys'];
      $path = '';

      if ($absolute) {
         $path .= DIRECTORY_SEPARATOR;
      }

      $path .= implode(DIRECTORY_SEPARATOR, $parts);

      if ($dir) {
         $path .= DIRECTORY_SEPARATOR;
      }

      return $path;
      // return '/var/www/sys/';
   }
   private static function concatenate (array $parts, int $offset = 0) : string
   {
      // $parts = ['home', 'bootgly', 'bootgly', 'index.php'];
      // $offset = 2;
      $path = '';

      // * Meta
      $indexes = 0;

      foreach ($parts as $index => $part) {
         if ($index >= $offset) {
            $separator = ($indexes > 0) ? DIRECTORY_SEPARATOR : '';

            $path .= $separator . $part;

            $indexes++;
         }
      }

      return $path;
      // return 'bootgly/index.php';      
   }
}
