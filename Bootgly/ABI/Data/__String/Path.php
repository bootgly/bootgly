<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String;


use SplFileInfo;

use Bootgly\ABI\Data\__Array;


class Path // support to FileSystem Paths only (Linux only)
{
   // * Config
   // @ convert
   public bool $convert = false;
   public bool $lowercase = false;
   // @ fix
   public bool $fix = true;
   public bool $dir_ = true; // DEPRECATED
   public bool $normalize = true;
   // @ validate
   public bool $real = false;

   // * Data
   public protected(set) string $path = '';

   // * Metadata
   protected bool $constructed = false;
   // # Type
   protected string|false $type;
   // # Position
   protected bool $absolute;
   protected bool $relative;
   // # Status
   protected bool $normalized;
   // # Parts
   // ->path
   public string $root {
      get {
         if (isSet($this->root) === true) {
            return $this->root;
         }

         $path = $this->path;

         if ($path === '') {
            return '';
         }
         if ($path[0] !== '/') {
            return '';
         }

         $root = strstr($path, DIRECTORY_SEPARATOR, true);
         $root .= DIRECTORY_SEPARATOR;

         return $this->root = $root;
      }
   }
   public string $parent {
      get {
         if (isSet($this->parent) === true) {
            return $this->parent;
         }

         $parent = '';

         $path = $this->path;
         if ($path) {
            $parent = dirname($path);

            if ($parent[-1] !== DIRECTORY_SEPARATOR) {
               $parent .= DIRECTORY_SEPARATOR;
            }
         }

         return $this->parent = $parent;
      }
   }
   public string $current {
      get {
         if (isSet($this->current) === true) {
            return $this->current;
         }

         $current = '';

         $path = $this->path;
         if ($path) {
            $lastNode = strrchr(haystack: $path, needle: DIRECTORY_SEPARATOR);
            if ($lastNode === false) {
               return $this->current = $path;
            }

            $current = substr($lastNode, 1);

            if ($current === '') {
               $current = basename($path);
            }
         }

         return $this->current = $current;
      }
   }
   /** @var array<string> */
   public array $parts {
      get {
         $path = $this->path;

         return self::split($path);
      }
   }
   // ->parts
   public int $indexes {
      get => count($this->parts);
   }
   public object $Index {
      get {
         $__Array = new __Array($this->parts);

         return (object) [
            'Last' => $__Array->Last
         ];
      }
   }


   public function __construct (string $path = '')
   {
      if ($path) {
         $this->construct($path);
      }
   }
   public function __get (string $key): mixed
   {
      switch ($key) {
         // * Metadata
         case 'constructed':
            return $this->constructed;
         // # Type
         case 'type':
            if ($this->real === false) {
               return false;
            }
            $path = $this->path;
            return $this->type = (new SplFileInfo($path))->getType();
         // # Position
         case 'absolute':
            $path = $this->path;
            return $this->absolute = $path[0] === '/';
         case 'relative':
            $path = $this->path;
            return $this->relative = $path[0] !== '/';
         // # Status
         case 'normalized':
            return $this->normalized;
         default:
            return null;
      }
   }
   /**
    * @param string $name
    * @param array<mixed> $arguments
    */
   public function __call (string $name, array $arguments): mixed
   {
      switch ($name) {
         // ->path
         case 'normalize':
            $this->normalized = true;
            return $this->path = self::normalize($this->path);
         case 'split':
            return self::split($this->path);
         case 'cut':
            // @phpstan-ignore-next-line
            return self::cut($this->path, ...$arguments);
         case 'relativize':
            // @phpstan-ignore-next-line
            return self::relativize($this->path, ...$arguments);
         // ->parts
         case 'join':
            // @phpstan-ignore-next-line
            return self::join($this->parts, ...$arguments);
         case 'concatenate':
            // @phpstan-ignore-next-line
            return self::concatenate($this->parts, ...$arguments);
         default:
            return null;
      }
   }
   /**
    * @param string $name
    * @param array<mixed> $arguments
    */
   public static function __callStatic (string $name, $arguments): mixed
   {
      if ( method_exists(__CLASS__, $name) ) {
         return self::$name(...$arguments);
      }

      return null;
   }
   public function __toString (): string
   {
      return $this->path;
   }
   /**
    * Construct a path
    * 
    * @param string $path
    *
    * @return string
    */
   public function construct (string $path): string
   {
      if ($this->constructed) {
         return '';
      }
      if ($path === '') {
         return '';
      }

      // * Data
      // @
      // * Metadata
      // _ Type
      // ...dynamically
      // _ Position
      // ...
      // _ Status
      $this->normalized = false;
      // _ Parts
      // ...dynamically

      // @
      // @ 1 - convert
      if ($this->convert && $this->lowercase) {
         $path = strtolower($path);
      }

      // @ 2 - fix
      if ($this->fix) {
         // Remove '/./', '/../', '//' in path
         if ($this->normalize) {
            $path = self::normalize($path);
         }
      }

      // @ 3 - valid
      // The resulting path will have no symbolic link, '/./' or '/../'
      if ($this->real) {
         $path = (string) realpath($path);
      }

      $this->constructed = true;

      return $this->path = $path;
   }
   /**
    * Match a path with a pattern
    * 
    * @param string $path
    * @param string $pattern
    *
    * @return bool
    */
   public function match (string $path, string $pattern): bool
   {
      // path: /etc/php/%
      // pattern: '8.*'

      if ($pattern === '') {
         return false;
      }

      if (isSet($this->path) && $path[0] === '/') {
         return false;
      }

      $pattern = str_replace(
         search: '%',
         replace: $pattern,
         subject: "{$this->path}{$path}"
      );

      $paths = glob($pattern);

      if ( $paths !== false && isSet($paths[0]) ) {
         $this->path = $paths[0]; // Get first path found
      } else {
         return false;
      }

      return true;

      // /etc/php/8.0 or /etc/php/8.1 or /etc/php/8.2...
   }
   /**
    * Normalize a path
    * 
    * @param string $path
    *
    * @return string
    */
   public static function normalize (string $path): string
   {
      // $path = '../..\etc/passwd';

      // * Metadata
      $is_dir = $path[-1] === '\\' || $path[-1] === '/';

      // @
      // Overwrites all directory separators with the standard separator
      $path = match (DIRECTORY_SEPARATOR) {
         '/' => str_replace('\\', '/', $path),
         '\\' => str_replace('/', '\\', $path)
      };
      // Split the path into parts
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
      if ($is_dir) {
         $normalized .= DIRECTORY_SEPARATOR;
      }

      return $normalized;
      // return 'etc/passwd';
   }
   /**
    * Split a path into parts
    * 
    * @param string $path
    *
    * @return array<string>
    */
   private static function split (string $path): array
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
   /**
    * Cut parts from a path
    * 
    * @param string $path
    * @param int ...$cutting
    *
    * @return string
    */
   private static function cut (string $path, int ...$cutting): string
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

         // * Metadata
         // @ path
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
   /**
    * Get the relative path from a path to another
    * 
    * @param string $path
    * @param string $from
    *
    * @return string
    */
   public static function relativize (string $path, string $from): string
   {
      // $path = '/foo/bar/tests/test2.php'
      // $from = '/foo/bar/'
      $path = explode(DIRECTORY_SEPARATOR, $path);
      $from = explode(DIRECTORY_SEPARATOR, $from);

      $length = min(count($from), count($path));
      $target = 0;
      for ($i = 0; $i < $length; $i++) {
         if ($from[$i] !== $path[$i]) {
            $target = $i;
            break;
         }
      }

      $relative_parts = array_slice($path, $target);
      $relative_path = implode(DIRECTORY_SEPARATOR, $relative_parts);

      return $relative_path;
      // return 'tests/test2.php';
   }
   /**
    * Join parts to form a path
    * 
    * @param array<string> $parts
    * @param bool $absolute
    * @param bool $dir
    *
    * @return string
    */
   private static function join (array $parts, bool $absolute = false, bool $dir = false): string
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
   /**
    * Concatenate parts to form a path
    * 
    * @param array<string> $parts
    * @param int $offset
    *
    * @return string
    */
   private static function concatenate (array $parts, int $offset = 0): string
   {
      // $parts = ['home', 'bootgly', 'bootgly', 'index.php'];
      // $offset = 2;
      $path = '';

      // * Metadata
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
