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


use AllowDynamicProperties;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS;
use Bootgly\ABI\IO\FS\File\MIME;


#[AllowDynamicProperties]
class File implements FS
{
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
   // @ read
   const DEFAULT_READ_METHOD = 'fread';
   const REQUIRE_READ_METHOD = 'require';
   const CONTENTS_READ_METHOD = 'file_get_contents';
   const READFILE_READ_METHOD = 'readfile';


   // * Config
   /**
    * Simple check file.
    */
   public bool $check = true;
   /**
    * Convert file (+.php or +/index.php).
    */
   public bool $convert = true;

   protected $mode;                  // Open mode: r, r+, w, w+, a...
   protected $method;                // Read method: fread, require, file_get_contents

   // * Data
   public Path $Path;
   protected readonly string|false $file;

   protected string|false $contents;

   // * Meta
   private $handler;

   protected bool $exists;           // bool true|false

   protected int|false $size;        // int 51162
   protected int|false $lines;       // int 15
   // @ Path
   protected string $basename;       // /path/to/foo.html -> foo.html
   protected string $name;           // foo.html -> 'foo'
   protected string $extension;      // foo.html -> 'html'
   protected string $parent;         // /path/to/foo.html -> /path/to/
   // _ Access
   protected int|false $permissions; // 0644
   protected bool $readable;         // true | false
   protected bool $executable;       // true | false
   protected bool $writable;         // true | false
   protected int|false $owner;       // 0
   protected int|false $group;       // 0
   // _ Content
   # < foo.jpg
   protected object|false $MIME;     // > object (real MIME based content)
   protected string|false $format;   // > 'image'
   protected string|false $subtype;  // > 'jpeg'
   // _ Stat
   protected int|false $accessed;    // accessed file (timestamp)
   protected int|false $created;     // only Windows / in Unix is changed inode
   protected int|false $modified;    // modified content (timestamp)
   // _ System
   protected int|false $inode;       // 
   protected string|false $link;     // 
   // _ Event
   protected string|false $status;   // accessed, created, modified
   // @ write
   protected ? bool $written = null;


   public function __construct (string $path)
   {
      // * Data
      $this->Path = new Path($path);
   }
   public function __get (string $name)
   {
      if ( isSet($this->$name) ) {
         return $this->$name;
      }

      // Path
      if ( ! isSet($this->file) ) {
         $this->pathify();
      }

      // ...
      # < /path/to/foo.php
      switch ($name) {
         case 'basename':  # > foo.php
            $current = $this->Path->current;

            return $this->basename = $current;
         case 'name':      # > foo
            $basename = $this->basename ?? $this->__get('basename');
            $name = substr(
               string: $basename,
               offset: 0,
               length: strrpos($basename, '.')
            );

            return $this->name = $name;
         case 'extension': # > php
            $basename = $this->basename ?? $this->__get('basename');
            $extension = '';

            $dot = strrchr($basename, '.');
            if ($dot !== false) {
               $extension = substr($dot, 1);
            }

            return $this->extension = $extension;
         case 'parent':    # > /path/to/
            $parent = $this->Path->parent;

            return $this->parent = $parent;
      }

      // Only constructed successfully
      $file = $this->file ?? false;

      if ( ! $file ) {
         return false;
      }

      switch ($name) {
         // * Data
         case 'file':
            return $file;
         case 'contents':
            return file_get_contents($file, false);

         // * Meta
         case 'exists':
            return is_file($file);

         case 'size':
            return $this->size = (new \SplFileInfo($file))->getSize();
         case 'lines':
            $size = $this->size ?? $this->__get('size');

            if ($size < 100000) { // if file < 100kb use + perf method
               $linesArray = @file($file);
               $linesCount = ($linesArray !== false) ? count($linesArray) : false;
            } else { // else use more memory-efficient method
               $handler = @fopen($file, 'r');

               if ($handler) {
                  $linesCount = 0;

                  while (@fgets($handler) !== false) {
                     $linesCount++;
                  }

                  @fclose($handler);
               } else {
                  $linesCount = false;
               }
            }

            return $this->lines = $linesCount;
         // _ Access
         case 'permissions':
            return $this->permissions = (new \SplFileInfo($file))->getPerms();
         case 'readable':
            return $this->readable = (new \SplFileInfo($file))->isReadable();
         case 'executable':
            return $this->executable = (new \SplFileInfo($file))->isExecutable();
         case 'writable':
            return $this->writable = (new \SplFileInfo($file))->isWritable();
         case 'owner':
            return $this->owner = (new \SplFileInfo($file))->getOwner();
         case 'group':
            return $this->group = (new \SplFileInfo($file))->getGroup();
         // _ Content
         case 'MIME':
            return $this->MIME = new MIME($file);
         case 'format':
            $MIME = $this->MIME ?? $this->__get('MIME');
            return $MIME->format;
         case 'subtype':
            $MIME = $this->MIME ?? $this->__get('MIME');
            return $MIME->subtype;
         // _ Stat
         case 'accessed':
            return $this->accessed = (new \SplFileInfo($file))->getATime();
         case 'created':
            return $this->created = (new \SplFileInfo($file))->getCTime();
         case 'modified':
            return $this->modified = (new \SplFileInfo($file))->getMTime();
         // _ System
         case 'inode':
            return $this->inode = (new \SplFileInfo($file))->getInode();
         case 'link':
            return $this->link = (new \SplFileInfo($file))->getLinkTarget();
      }

      return null;
   }
   public function __set (string $name, $value)
   {
      // Path
      if ( ! isSet($this->file) ) {
         $this->pathify();
      }

      // Only constructed successfully
      $file = $this->file ?? false;

      if ( ! $file ) {
         return false;
      }

      switch ($name) {
         // * Data
         case 'contents':
            $contents = file_put_contents($file, $value);

            if ($contents !== false) {
               $this->written = true;

               unset($this->size);
               unset($this->lines);

               unset($this->accessed);
               unset($this->created);
               unset($this->modified);
            } else {
               $this->written = false;
            }

            return $this->contents = $contents;
      }
   }
   public function __toString () : string
   {
      // Path
      if ( ! isSet($this->file) ) {
         $this->pathify();
      }

      return $this->file ?? '';
   }

   private function pathify () : string|false
   {
      // ?
      $path = $this->Path->path;

      if ($path === '') {
         return $this->file = '';
      }

      // @
      if ($this->check && is_file($path) === true) { // Only check if the path exists as file
         return $this->file = $path;
      }

      if ($this->convert) {
         // @ Convert path dir to base if needed
         if ($path[-1] === DIRECTORY_SEPARATOR) {
            $path = rtrim($path, DIRECTORY_SEPARATOR);
         }

         // @ Convert base to file with .php
         $base = $path . '.php';
         if (is_file($base) === true) {
            return $this->file = $base;
         }

         // @ Convert base to file with index.php
         $index = $path . DIRECTORY_SEPARATOR . 'index.php';
         if (is_file($index) === true) {
            return $this->file = $index;
         }
      }

      return $this->file = false;
   }

   public function open (string $mode)
   {
      $Path = $this->Path;

      if ($this->handler === null && $Path->path !== null) {
         $this->status = 'accessed';

         $this->mode = $mode;

         switch ($mode) {
            // Read
            case 'r':
               // Open to Read (r)
               // Place the file pointer at the beginning of the file. (?)
               // If the file does not exist, return false.

               $this->handler = @fopen($Path, 'r');

               break;
            case 'r+':
               // Open to Read (r)
               // Place the file pointer at the beginning of the file. (?)
               // If the file does not exist, attempt to create it. (+)

               if ($this->file === '') {
                  // Create directory base if not exists
                  if (is_dir($Path->parent) === false) {
                     mkdir($Path->parent, 0775);
                  }
                  // Create empty file if file not exists
                  file_put_contents($Path, '');
               }

               $this->handler = fopen($Path, 'r+');

               break;
            // Write
            case 'w':
               // Open to Write
               // Place the file pointer at the end of the file. (?)
               // If the file does not exist, return false.

               if ($this->file === '') {
                  $this->handler = false;
               } else {
                  $this->handler = fopen($Path, 'w');
               }

               break;
            case 'w+':
               // Open to Write (w)
               // Place the file pointer at the end of the file. (?)
               // If the file does not exist, attempt to create it. (+)

               if ($this->file === '' && is_dir($Path->parent) === false) {
                  // Create dir if not exists
                  mkdir($Path->parent, 0775);
               }

               $this->handler = fopen($Path, 'w+');

               break;
            case 'rw+':
               // Open to Read (r) and Write (w)
               // Place the file pointer at the beginning of the file in read and at the end in write. (?)
               // If the file does not exist, attempt to create it. (+)

               if ($this->file === '') {
                  // Create directory base if not exists
                  if (is_dir($Path->parent) === false) {
                     mkdir($Path->parent, 0775);
                  }
                  // Create empty file if file not exists
                  file_put_contents($Path, '');
               }

               $this->handler = true;

               break;
            default:
               $this->handler = true;
         }
      }

      return $this->handler;
   }

   public function read ($method = self::READFILE_READ_METHOD, int $offset = 0, ? int $length = null): string|int|false
   {
      if ($this->file === '') {
         return false;
      }

      if ($method) {
         $this->method = $method;
      }

      if ($this->handler) {
         switch ($this->method) {
            case self::REQUIRE_READ_METHOD:
               ob_start();

               // @ Require file with isolated scope / context
               (static function ($file) {
                  require $file;
               })($this->file);

               $contents = ob_get_contents();

               ob_end_clean();

               if ($offset > 0 || $length > 0) {
                  $contents = substr($contents, $offset, $length);
               }

               return $this->contents = $contents;
            default:
               if ($this->mode == 'rw+') {
                  $this->handler = fopen($this->Path, 'r');
               }

               return $this->contents = fread($this->handler, $this->size);
         }
      }

      switch ($this->method) {
         case self::CONTENTS_READ_METHOD:
            return $this->contents = file_get_contents($this->file, false, null, $offset, $length);
         case self::READFILE_READ_METHOD:
            return readfile($this->file);
      }

      return false;
   }
   public function write ($data)
   {
      if ($this->mode == 'rw+') {
         $this->close();
         $this->handler = fopen($this->Path, 'w');
      }

      if ($this->handler) {
         $bytes = fwrite($this->handler, $data);

         if ($bytes) {
            $this->written = true;
            return $bytes;
         }
      }

      return false;
   }

   public function close ()
   {
      if (is_resource($this->handler)) {
         fclose($this->handler);
      }

      $this->handler = null;
   }

   public function __destruct ()
   {
      $this->close();
   }
}
