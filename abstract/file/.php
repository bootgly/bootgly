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


use Bootgly\__String;
use Bootgly\Path;
use Bootgly\Dir;


#[\AllowDynamicProperties]
class File
{
   // TODO load MIMES externaly (with use, require?)
   const MIMES = [
      'php' => 'text/html',
      'pdf' => 'application/pdf',

      'eot' => 'application/vnd.ms-fontobject',
      'ttf' => 'application/x-font-ttf',
      'woff' => 'application/x-font-woff',
      'woff2' => 'application/x-font-woff',
      'js' => 'application/javascript',

      'png' => 'image/png',
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'gif' => 'image/gif',
      'ico' => 'image/x-icon',
      'svg' => 'image/svg+xml',
      'webp' => 'image/webp',

      'css' => 'text/css',
      'map' => 'application/x-navimap',
      'less' => 'text/css',

      'html' => 'text/html',
      'txt' => 'text/plain'
   ];
   // ! Open mode
   // ? Read
   const READ_MODE = 'r';
   const READ_NEW_MODE = 'r+';
   // ? Write
   const WRITE_MODE = 'w';
   const WRITE_NEW_MODE = 'w+';
   const WRITE_APPEND_MODE = 'a';
   // ? Read / Write
   const READ_WRITE_MODE = 'rw';
   const READ_WRITE_NEW_MODE = 'rw+';
   const READ_APPEND_MODE = 'ra';
   // ! Read method
   const DEFAULT_READ_METHOD = 'fread';
   const REQUIRE_READ_METHOD = 'require';
   const CONTENTS_READ_METHOD = 'file_get_contents';
   const READFILE_READ_METHOD = 'readfile';


   public ?Path $Path = null;

   // * Config
   public bool $check = true;
   public bool $construct = true;
   public bool $convert = true;
   protected $pointer;           // Line pointer positions array
   protected $mode;              // Open mode: r, r+, w, w+, a...
   protected $method;            // Read method: fread, require, file_get_contents

   // * Data (set public or injetable)
   public string $path = '';
   public string $file = '';
   public string $dir = '';
   protected $contents;

   // * Meta (set private|protected and not injetable)
   public string $File = '';
   public ?Dir $Dir  = null;
   private $handle;
   protected $exists;             // @ bool
   protected $size;               // @ int 51162
   protected $status;             // @ accessed, created, modified
   protected $lines;              // @ int 15
   #protected string $name;       // @ foo.html -> 'foo'
   #protected string $extension;  // @ foo.html -> 'html'
   #protected string $type;       // @ 'text/html'
   // ! Access
   protected int $permissions;    // @ 0644
   protected bool $readable;      // @ true | false
   protected bool $executable;    // @ true | false
   protected bool $writable;      // @ true | false
   protected string $owner;       // @ 'bootgly'
   protected string $group;       // @ 'bootgly'
   // ! Stat
   protected int $accessed;       // @ accessed file (timestamp)
   protected int $created;        // @ only Windows / in Unix is changed inode
   protected int $modified;       // @ modified content (timestamp)
   // ! System
   protected $inode;              // @ int inode number
   protected $link;               // @ string link of file if exists
   // ! Event
   protected ? bool $written = null;


   public function __construct (? string $path = null)
   {
      if ($path === null || $path === '') {
         return;
      }

      if ($this->Path === null) {
         $this->Path = new Path($path);
      }

      // TODO Refactor or Remove to reduce memory and CPU usage
      $Path = &$this->Path;
      if ($Path->Path) {
         $this->path = $path;
         $this->file = $this->path;
         $this->dir = $Path->parent;

         $this->File = $this->construct($Path->Path);
         $this->Dir = new Dir($this->dir);

         if ($this->File && $this->File !== $this->file) {
            $this->Path = $Path($this->File);

            // $this->path = $path;
            $this->file = $this->File;
            $this->dir = $Path->parent;
         }
      } else {
         // TODO
         $this->Dir = null;
      }
   }
   public function __invoke (string $path)
   {
      // TODO Reset all properties needed
      unset($this->size);

      $this->__construct($path);

      return $this->File;
   }
   public function __get (string $name)
   {
      // use Path
      if ($this->Path) {
         // @ /path/to/foo.php
         switch ($name) { // TODO Refactor, Create alias and Test all
            case 'basename':
            case 'current':   // @ 'foo.php'
               return $this->Path->current;
            case 'name':      // @ foo
               return $this->name = strstr($this->current, '.', true);
            case 'extension': // @ php
               return $this->extension = substr(strrchr($this->current, '.'), 1);
            case 'type':      // @ text/html
               return $this->type = @self::MIMES[$this->extension];
            case 'parent':    // @ /path/to/
               return $this->Path->parent;
         }
      }

      // use Dir
      if ($this->Dir) {
         switch ($name) {
            case 'writable':  // @ true
               return $this->Dir->writable;
         }
      }

      // use File
      if ($this->File) {
         switch ($name) {
            // * Data
            case 'contents':
               return file_get_contents($this->File, false);
            // * Meta
            case 'exists':
               return is_file($this->File);
            case 'size':
               return $this->size = (new \SplFileInfo($this->File))->getSize();
            // ! Access
            case 'permissions':
               return $this->permissions = (new \SplFileInfo($this->File))->getPerms();
            case 'readable':
               return $this->readable = (new \SplFileInfo($this->File))->isReadable();
            case 'executable':
               return $this->executable = (new \SplFileInfo($this->File))->isExecutable();
            case 'writable':
               return $this->writable = (new \SplFileInfo($this->File))->isWritable();
            case 'owner':
               return $this->owner = (new \SplFileInfo($this->File))->getOwner();
            case 'group':
               return $this->group = (new \SplFileInfo($this->File))->getGroup();
            // ! Stat
            case 'accessed':
               return $this->accessed = (new \SplFileInfo($this->File))->getATime();
            case 'created':
               return $this->created = (new \SplFileInfo($this->File))->getCTime();
            case 'modified':
               return $this->modified = (new \SplFileInfo($this->File))->getMTime();
            // ! System
            case 'inode':
               return $this->inode = (new \SplFileInfo($this->File))->getInode();
            case 'link':
               return $this->link = (new \SplFileInfo($this->File))->getLinkTarget();
         }
      }

      if ($this->file) {
         switch ($name) {
            // * Meta
            case 'exists':
               return is_file($this->file);
            case 'readable':
               return $this->readable = (new \SplFileInfo($this->file))->isReadable();
            case 'executable':
               return $this->executable = (new \SplFileInfo($this->file))->isExecutable();
            case 'writable':
               return $this->writable = (new \SplFileInfo($this->file))->isWritable();
         }
      }

      return @$this->$name;
   }
   public function __set (string $name, $value)
   {
      // Generic
      switch ($name) {
         // * Data
         case 'contents':
            if ($this->File) {
               $file = $this->File;
            } else {
               $file = $this->file;
            }

            $this->contents = file_put_contents($file, $value); // TODO rest of arguments

            if ($this->contents !== false) {
               $this->written = true;

               $this->construct($file);
            } else {
               $this->written = false;
            }

            return $this->contents;
      }

      return $this->$name = $value;
   }
   public function __toString () : string
   {
      return $this->File;
   }

   public function construct (string $path) : string
   {
      if (! $path) {
         return '';
      }

      $this->File = '';

      if ($this->check && is_file($path)) { // Only check if the path exists as file
         return $this->File = $path;
      }

      if ($this->construct) { // Construct the path with $Path->Path($path) and check if file exists
         $path = $this->Path->Path($path);
         if (is_file($path)) {
            return $this->File = $path;
         }
      }

      if ($this->convert) { // Convert the path to base or index and check if file exists
         $base = $path . '.php';
         if (is_file($base)) {
            return $this->File = $base;
         }

         $index = __String::trim($path, Path::DIR_, 1) . $this->Path::DIR_ . 'index.php';
         if (is_file($index)) {
            return $this->File = $index;
         }
      }

      return $this->File;
   }

   // TODO Refactor this function to reduce its Cognitive Complexity from 29 to the 15 allowed.
   public function open (string $mode)
   {
      if ($this->handle === null && $this->Path !== null) {
         $this->mode = $mode;

         // if ($this->File === '') {
            // $this->__construct((string) $this->Path);
         // }

         switch ($mode) {
            // Read
            case 'r':
               // Open to Read (r)
               // Place the file pointer at the beginning of the file. (?)
               // If the file does not exist, return false.

               $this->handle = @fopen($this->Path, 'r');

               break;
            case 'r+':
               // Open to Read (r)
               // Place the file pointer at the beginning of the file. (?)
               // If the file does not exist, attempt to create it. (+)

               if ($this->File === '') {
                  // Create directory base if not exists
                  if (is_dir($this->parent) === false) {
                     mkdir($this->parent, 0775);
                  }
                  // Create empty file if file not exists
                  file_put_contents($this->Path, '');
               }

               $this->handle = fopen($this->Path, 'r+');

               break;
            // Write
            case 'w':
               // Open to Write
               // Place the file pointer at the end of the file. (?)
               // If the file does not exist, return false.

               if ($this->File === '') {
                  $this->handle = false;
               } else {
                  $this->handle = fopen($this->Path, 'w');
               }

               break;
            case 'w+':
               // Open to Write (w)
               // Place the file pointer at the end of the file. (?)
               // If the file does not exist, attempt to create it. (+)

               if ($this->File === '' && is_dir($this->parent) === false) {
                  // Create dir if not exists
                  mkdir($this->parent, 0775);
               }

               $this->handle = fopen($this->Path, 'w+');

               break;
            case 'rw+':
               // Open to Read (r) and Write (w)
               // Place the file pointer at the beginning of the file in read and at the end in write. (?)
               // If the file does not exist, attempt to create it. (+)

               if ($this->File === '') {
                  // Create directory base if not exists
                  if (is_dir($this->parent) === false) {
                     mkdir($this->parent, 0775);
                  }
                  // Create empty file if file not exists
                  file_put_contents($this->Path, '');
               }

               $this->handle = true;

               break;
            default:
               $this->handle = true;
         }

         if ($this->File === '' && $this->handle !== false) {
            $this->__construct($this->Path->_);
         }
      }

      return $this->handle;
   }

   public function read ($method = self::READFILE_READ_METHOD, int $offset = 0, ? int $length = null)
   {
      if ($this->File === '') {
         return false;
      }

      if ($method) {
         $this->method = $method;
      }

      if ($this->handle) {
         switch ($this->method) {
            case self::REQUIRE_READ_METHOD: // TODO refactor
               ob_start();

               require $this->File;

               $contents = ob_get_contents();

               ob_end_clean();

               return $this->contents = $contents;
            default:
               if ($this->mode == 'rw+') {
                  $this->handle = fopen($this->Path, 'r');
               }

               return $this->contents = fread($this->handle, $this->size);
         }
      }

      switch ($this->method) {
         case self::CONTENTS_READ_METHOD:
            return $this->contents = file_get_contents($this->File, false, null, $offset, $length);
         case self::READFILE_READ_METHOD:
            return readfile($this->File);
      }

      return false;
   }
   public function write ($data)
   {
      if ($this->mode == 'rw+') {
         $this->close();
         $this->handle = fopen($this->Path, 'w');
      }

      if ($this->handle) {
         $bytes = fwrite($this->handle, $data);

         if ($bytes) {
            $this->written = true;
            return $bytes;
         }
      }

      return false;
   }
   public function close ()
   {
      if (is_resource($this->handle)) {
         fclose($this->handle);
      }

      $this->handle = null;
   }

   public function __destruct ()
   {
      $this->close();
   }
}
