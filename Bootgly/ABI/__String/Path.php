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


#[\AllowDynamicProperties]
class Path
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
   // ->Path
   //private string $root;    // /var/www/sys/index.php => /var
   //private string $parent;  // /var/www/sys/index.php => /var/www/sys/
   //private string $current; // /var/www/sys/index.php => index.php
   //private array $paths;    // /var/www/sys/ => [0 => 'var', 1 => 'www', 2 => 'sys']
   //private string $type;    // => 'dir' or 'file'
   // ->paths
   //private object $Index;   // ->Last->key, Last->value
   // ->relativize() ?
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
         // * Meta
         // ->Path
         case '_': // DEPRECATED
            return $this->Path;
         case 'root':
            $root = strstr($this->Path, DIRECTORY_SEPARATOR, true);

            if ($root) {
               $root = $root . DIRECTORY_SEPARATOR;
            }

            return $root;
         case 'parent':
            $parent = '';

            if ($this->Path) {
               $parent = dirname($this->Path);

               if ($parent[-1] !== DIRECTORY_SEPARATOR) {
                  $parent .= DIRECTORY_SEPARATOR;
               }
            }

            return $parent;
         case 'current':
            $current = '';

            if ($this->Path) {
               $lastNode = strrchr(haystack: $this->Path, needle: DIRECTORY_SEPARATOR);

               $current = substr($lastNode, 1);

               if ($current == '') {
                  $current = basename($this->Path);
               }
            }

            return $current;
         case 'paths':
            return self::split($this->Path);
         case 'type':
            $this->type = (new \SplFileInfo($this->Path))->getType();

            break;
         // ->paths
         case 'Index':
            return new class ($this->paths)
            {
               // * Meta
               public object $Last;


               public function __construct ($paths)
               {
                  $__Array = new __Array($paths);

                  // * Meta
                  $this->Last = ($__Array)->Last; // ->key, ->value
               }
            };
         // ->relativize()
         // ...
      }

      return @$this->$key;
   }
   public function __set (string $key, $value)
   {
      switch ($key) {
         // * Meta
         // ->Path
         case 'root':
         case 'parent':
         case 'current':
         case 'paths':
         case 'relative':
            $this->$key = $value;
            break;
         // ->paths
         // ...
         // ->relativize()
         // ...
      }
   }
   public function __call (string $name, array $arguments)
   {
      switch ($name) {
         // ->Path
         case 'normalize':
            return self::normalize($this->Path);
         case 'split':
            return self::split($this->Path);
         case 'cut':
            return self::cut($this->Path, ...$arguments);
         case 'relativize':
            return self::relativize($this->Path, ...$arguments);
         // ->paths
         case 'join':
            return self::join($this->paths, ...$arguments);
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

      $this->__construct($path);

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
      $paths = explode(DIRECTORY_SEPARATOR, $path);
      $Paths = [];

      // @ Parse absolute path
      if ($path[0] === DIRECTORY_SEPARATOR) {
         $Paths[] = '';
      }

      foreach ($paths as $node) {
         if ($node === '..') {
            array_pop($Paths);
         } else if ($node !== '.' && $node !== '') {
            array_push($Paths, $node);
         }
      }

      $path = implode(DIRECTORY_SEPARATOR, $Paths);

      return $path;
      // return 'etc/passwd';
   }
   public static function split(string $path): array
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
   public static function cut (string $path, int ...$cutting) : string
   {
      // $path = var/www/html/test/;
      // $nodes = [-2, 1];
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

   private static function join (array $paths, bool $absolute = false, bool $dir = false) : string
   {
      $path = '';

      if ($absolute) {
         $path .= DIRECTORY_SEPARATOR;
      }

      $path .= implode(DIRECTORY_SEPARATOR, $paths);

      if ($dir) {
         $path .= DIRECTORY_SEPARATOR;
      }

      return $path;
   }
   private static function concatenate (array $paths, int $offset = 0) : string
   {
      $Path = '';

      // * Meta
      $nodes = 0;

      foreach ($paths as $index => $path) {
         if ($index >= $offset) {
            $separator = ($nodes > 0) ? DIRECTORY_SEPARATOR : '';

            $Path .= $separator . $path;

            $nodes++;
         }
      }

      return $Path;
   }
}
