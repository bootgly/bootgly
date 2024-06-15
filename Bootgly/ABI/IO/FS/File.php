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
use Throwable;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS;
use Bootgly\ABI\IO\FS\File\MIME;


#[AllowDynamicProperties]
class File implements FS
{
   // @ open modes
   // (r)ead
   /**
    * Open for reading only.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist, return false.
    */
   public const READONLY_MODE = 'r';
   /**
    * Open for reading and writing.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist, return false.
    */
   public const READ_WRITE_MODE = 'r+';

   // @ (c)reate
   /**
    * Open for writing only.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    * If it exists, it is neither truncated (as opposed to 'w'), nor the call to this function fails (as is the case with 'x').
    */
   public const CREATE_WRITEONLY_MODE = 'c';
   /**
    * Open for reading and writing.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    * If it exists, it is neither truncated (as opposed to 'w'), nor the call to this function fails (as is the case with 'x').
    */
   public const CREATE_READ_WRITE_MODE = 'c+';

   // @ (w)rite
   /**
    * Open for writing only.
    * ⚠️ Truncate the file to zero length (wipe all file data)!
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const CREATE_TRUNCATE_WRITEONLY_MODE = 'w';
   /**
    * Open for writing and reading.
    * ⚠️ Truncate the file to zero length (wipe all file data)!
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const CREATE_TRUNCATE_READ_WRITE_MODE = 'w+';

   // @ (a)ppend
   /**
    * Open for writing only.
    * 
    * Place the file pointer at the end of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const CREATE_APPEND_WRITEONLY_MODE = 'a';
   /**
    * Open for reading and writing.
    * 
    * Place the file pointer at the end of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const CREATE_APPEND_READ_WRITE_MODE = 'a+';

   // @ e(x)clusive
   /**
    * Open for writing only.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file exists, the opening will fail. 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const CREATE_EXCLUSIVE_WRITEONLY_MODE = 'x';
   /**
    * Open for reading and writing.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file exists, the opening will fail. 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const CREATE_EXCLUSIVE_READ_WRITE_MODE = 'x+';

   // @ read methods
   public const DEFAULT_READ_METHOD = 'fread';
   public const INCLUDE_READ_METHOD = 'include';
   public const CONTENTS_READ_METHOD = 'file_get_contents';
   public const READFILE_READ_METHOD = 'readfile';


   // * Config
   /**
    * Simple check file.
    */
   public bool $check = true;
   /**
    * Convert file (+.php or +/index.php).
    */
   public bool $convert = true;

   // * Data
   public readonly Path $Path;
   public readonly Dir $Basedir;
   protected readonly string|false $file;

   // * Metadata
   private $handler;

   protected bool $exists;           // bool true|false

   protected bool $EOF;              // bool true|false
   protected int|false $size;        // int 51162 (bytes)
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
   protected string|false $contents;
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
   // @ write
   protected ? bool $written = null;


   public function __construct (string $path)
   {
      // * Data
      $this->Path = new Path($path);
      $this->Basedir = new Dir($this->Path->parent);
   }
   public function __get (string $name)
   {
      if ($name === 'file') {
         return $this->file ?? $this->pathify();
      }
      if ( isSet($this->$name) ) {
         return $this->$name;
      }
      if ($this->Path->constructed) {
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
      }

      // @ Construct $this->file
      if (isSet($this->file) === false) {
         $this->pathify();
      }

      // Only if $this->file was successfully constructed
      $file = $this->file ?? false;
      if ($file === '' || $file === false) {
         return false;
      }

      switch ($name) {
         // * Data
         case 'handler':
            return $this->handler;
         case 'contents':
            return $this->contents = file_get_contents($file, false);

         // * Metadata
         case 'exists':
            return is_file($file);

         case 'EOF':
            return \feof($this->handler);
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
      if (isSet($this->file) === false) {
         $this->pathify();
      }

      // Only if $this->file was successfully constructed
      $file = $this->file ?? false;
      if ($file === '' || $file === false) {
         return;
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

            $this->contents = $contents;
      }
   }
   public function __toString () : string
   {
      // Path
      if (isSet($this->file) === false) {
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

   /**
    * Creates a file (touch).
    *
    * @param bool $recursively Whether to create base directories if they don't exist.
    * @return bool Returns true if the file was successfully created, false otherwise.
    */
   public function create (bool $recursively = true) : bool
   {
      $Path = $this->Path;

      // * Data
      // Path
      $filename = $this->file ?? $Path->path;
      // * Metadata
      $dir  = null;
      $file = null;

      // @
      if ($recursively === true) {
         $dir = $this->Basedir->create();
      }

      try {
         is_file($filename) ? ($this->file = $filename) : ($file = touch($filename));
      } catch (Throwable) {
         $file = false;
      }

      return $dir || $file;
   }
   /**
    * Opens a file in the specified mode or read-only mode by default.
    *
    * @param string $mode The mode in which to open the file (optional, default: read-only mode).
    * @return false|resource Returns the file handler on success, or false on failure.
    */
   public function open (string $mode = self::READONLY_MODE)
   {
      $filename = $this->file ?? $this->Path->path;

      // @
      $handler = false;
      if ($this->handler === null && $filename) {
         try {
            $handler = @fopen($filename, $mode);
         } catch (Throwable) {
            $handler = false;
         }
      }

      // Only set this->handler / pathify() if no error
      if ($handler !== false) {
         $this->handler = $handler;

         if (isSet($this->file) === false) {
            $this->pathify();
         }
      }

      return $handler;
   }


   /**
    * Read data from the file using various methods.
    *
    * @param string $method The method to use for reading (default: DEFAULT_READ_METHOD).
    * @param int $offset The offset to start reading from (default: 0).
    * @param int|null $length The number of bytes to read (default: null, reads until the end).
    *
    * @return string|int|false The read data as a string, number of bytes read as an integer, or false on failure.
    */
   public function read (string $method = self::DEFAULT_READ_METHOD, int $offset = 0, ? int $length = null) : string|int|false
   {
      if ( ! $this->file ) {
         return false;
      }

      // * Data
      $data = false;
      // * Metadata
      // @
      $size = ($offset === 0 && $length) ? $length : null;
      $filterable = ($method !== self::CONTENTS_READ_METHOD) && ($offset > 0 || $length > 0);

      // @
      // Methods with valid handler
      if ($this->handler) {
         switch ($method) {
            case self::INCLUDE_READ_METHOD:
               try {
                  ob_start();

                  // @ Include file with isolated scope / context
                  (static function ($file) {
                     include $file;
                  })($this->file);

                  $data = ob_get_clean();
               } catch (Throwable) {
                  $data = false;
               }

               break;
            case self::CONTENTS_READ_METHOD:
               $data = file_get_contents($this->file, false, null, $offset, $length);
               break;
            case self::READFILE_READ_METHOD:
               $data = readfile($this->file);
               break;
            default:
               if ( ! $this->handler) {
                  $data = false;
                  break;
               }

               try {
                  $data = @fread(
                     $this->handler,
                     $size ?? $this->size ?? $this->__get('size')
                  );
               } catch (Throwable) {
                  $data = false;
               }
         }
      }

      // :
      if ($filterable) {
         return substr($data, $offset, $length);
      }

      return $this->contents = $data;
   }
   /**
    * Write data to the file handler.
    *
    * @param string $data The data to be written to the file.
    * @param int|null $length [optional] If the length argument is given,
    *                 writing will stop after length bytes have been written or the end of string is reached,
    *                 whichever comes first.
    * @return int|false The number of bytes written, or false on failure.
    */
   public function write (string $data, ? int $length = null) : int|false
   {
      try {
         $bytes = @fwrite($this->handler, $data, $length);
      } catch (Throwable) {
         $bytes = false;
      }

      if ($bytes) {
         $this->written = true;
      }

      return $bytes;
   }

   /**
    * Closes the file handler.
    *
    * This method attempts to close the file handler associated with the object.
    *
    * @return bool Returns `true` if the file handler was successfully closed or if it was already closed,
    *              returns `false` if an error occurred while trying to close the file handler.
    */
   public function close () : bool
   {
      // @
      try {
         @fclose($this->handler);
      } catch (Throwable) {
         return false;
      }

      $this->handler = null;

      return true;
   }
   /**
    * Delete the file associated with this instance.
    *
    * If a file handler is open, it will be closed before deletion.
    *
    * @return bool Returns true on successful deletion, false otherwise.
    */
   public function delete () : bool
   {
      if ($this->handler !== null) {
         $this->close();
      }

      // * Data
      $filename = $this->file ?? $this->Path->path;

      if ( ! $filename ) {
         return false;
      }

      // @
      try {
         @unlink($filename);
      } catch (Throwable) {
         return false;
      }

      return true;
   }

   public function __destruct ()
   {
      $this->handler !== null && $this->close();
   }
}
