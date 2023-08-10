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
use Bootgly\ABI\__String;


class Path // TODO refactor
{
   const DIR_ = DIRECTORY_SEPARATOR;
   const PATH_ = PATH_SEPARATOR;


   public $Path = '';

   // * Config
   // @ convert
   public bool $convert = false;
   public bool $lowercase = false;
   // @ fix
   public bool $fix = true;
   public bool $dir_ = true;
   public bool $normalize = false;
   // @ match
   public bool $match = false;
   public string $pattern = '';
   // @ valid
   public bool $real = false;

   // * Data
   //private $root;        // /var/www/sys/index.php => /var
   //private $parent;      // /var/www/sys/index.php => /var/www/sys/
   //private $current;     // /var/www/sys/index.php => index.php
   //private $paths;       // /var/www/sys/ => [0 => 'var', 1 => 'www', 2 => 'sys']
   //private string $type; // => 'dir' or 'file'

   // * Meta
   //private $relative;


   public function __construct (string $path = '')
   {
      if ($path) {
         $this->construct($path);
      }
   }
   public function __get (string $key)
   {
      switch ($key) {
         case '_':
            return $this->Path;

         // * Data
         case 'root':
            $root = strstr($this->Path, self::DIR_, true);

            if ($root) {
               $root = $root . self::DIR_;
            }

            return $root;
         case 'parent':
            $parent = '';

            if ($this->Path) {
               $parent = dirname($this->Path);

               if ($parent[-1] !== self::DIR_) {
                  $parent .= self::DIR_;
               }
            }

            return $parent;
         case 'current':
            $current = '';

            if ($this->Path) {
               $lastNode = strrchr(haystack: $this->Path, needle: self::DIR_);

               $current = substr($lastNode, 1);

               if ($current == '') {
                  $current = basename($this->Path);
               }
            }

            return $current;
         case 'paths':
            $this->paths = self::split($this->Path);

            break; // TODO dynamically ???
         case 'type':
            $this->type = (new \SplFileInfo($this->Path))->getType();

            break;

         case 'Index':
            return new class ($this->paths)
            {
               public $last;

               public function __construct ($paths)
               {
                  $this->last = (new __Array($paths))->Last->key;
               }
            };
      }

      return @$this->$key;
   }
   public function __set (string $key, $value)
   {
      switch ($key) {
         case 'root':
         case 'parent':
         case 'current':
         case 'paths':
         case 'relative':
            $this->$key = $value;
            break;

         default:
            $this->$key = new Path($value);
      }
   }
   public function __call (string $name, array $arguments)
   {
      switch ($name) {
         case 'cut':
            return $this->relative = self::cut($this->Path, ...$arguments);
         case 'split':
            return self::split($this->Path);
         case 'normalize':
            return self::normalize($this->Path);
         case 'join':
            return self::join($this->paths, ...$arguments);
         case 'search':
            return self::search($this->paths, ...$arguments);
         case 'concatenate':
            return self::concatenate($this->paths, ...$arguments);
      }
   }
   public static function __callStatic (string $name, $arguments)
   {
      if ( method_exists(__CLASS__, $name) ) {
         return self::$name(...$arguments);
      }

      return null;
   }
   public function __invoke (string $path)
   {
      $this->Path = '';

      $this->construct($path);

      return $this;
   }
   public function __toString () : string
   {
      return $this->Path;
   }

   public function construct (string $path) : string
   {
      $Path = '';

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
               $Path = match (self::DIR_) {
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
      $paths = explode(self::DIR_, $path);
      $Paths = [];

      // @ Parse absolute path
      if ($path[0] === self::DIR_) {
         $Paths[] = '';
      }

      foreach ($paths as $node) {
         if ($node === '..') {
            array_pop($Paths);
         } else if ($node !== '.' && $node !== '') {
            array_push($Paths, $node);
         }
      }

      $path = implode(self::DIR_, $Paths);

      return $path;
      // return 'etc/passwd';
   }
   public static function cut (string $path, int ...$cutting) : string
   {
      if (count($cutting) > 2) {
         return $path;
      }

      foreach ($cutting as $cut) {
         if ($cut === 0) {
            continue;
         }

         // * Meta
         $paths = explode('/', trim($path, '/'));
         $nodes = count($paths);
         $absolute = $path[0] === '/';
         $dir = $path[-1] === '/';

         if (abs($cut) === $nodes) {
            return '';
         }

         if ($cut >= 0) { // + Positive <==
            $paths = array_slice($paths, 0, $nodes - $cut);

            if ($absolute) {
               array_unshift($paths, '');
            }
            array_push($paths, '');
         } else { // - Negative ==>
            $paths = array_slice($paths, abs($cut));

            if ($dir) {
               array_push($paths, '');
            }
         }

         $path = implode('/', $paths);
      }

      return $path;
   }
   public static function split (string $path) : array
   {
      // $path = '/var/www/sys/';
      $paths = [];

      if ($path) {
         $path = trim($path, "\x2F"); // /
         $path = trim($path, "\x5C"); // \
         $path = str_replace("\\", "/", $path);

         $paths = explode("/", $path);
      }

      return $paths;
      // return [0 => 'var', 1 => 'www', 2 => 'sys'];
   }
   public static function relativize (string $from, string $to) : string
   {
      // $from = '/foo/bar/'
      // $to   = '/foo/bar/tests/test2.php'
      $from = explode(DIRECTORY_SEPARATOR, $from);
      $to = explode(DIRECTORY_SEPARATOR, $to);

      $length = min(count($from), count($to));
      for ($i = 0; $i < $length; $i++) {
         if ($from[$i] !== $to[$i]) {
            break;
         }
      }

      $rest = implode(DIRECTORY_SEPARATOR, array_slice($to, $i));

      return $rest;
   }
   private static function join (array $paths) : string
   {
      $path = '';

      $path = implode(self::DIR_, $paths);

      return $path;
   }
   private static function concatenate (array $paths, int $from = 0) : string
   {
      $Path = '';

      foreach ($paths as $index => $path) {
         if ($index >= $from) {
            $Path .= $path;

            // ? Concat with "/" if the node is not file
            $Result = __String::search($path, '.');
            if ($Result->position === false) {
               $Path .= self::DIR_;
            }
         }
      }

      return $Path;
   }
   private static function search (array $paths, $needle)
   {
      return __Array::search($paths, $needle);
   }
}
