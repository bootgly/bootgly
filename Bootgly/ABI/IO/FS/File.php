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


use function feof;
use function file_get_contents;
use function is_file;
use AllowDynamicProperties;
use SplFileInfo;
use Throwable;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS\File\MIME;
use Bootgly\ABI\IO\FS;


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
   public const string READONLY_MODE = 'r';
   /**
    * Open for reading and writing.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist, return false.
    */
   public const string READ_WRITE_MODE = 'r+';

   // @ (c)reate
   /**
    * Open for writing only.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    * If it exists, it is neither truncated (as opposed to 'w'), nor the call to this function fails (as is the case with 'x').
    */
   public const string CREATE_WRITEONLY_MODE = 'c';
   /**
    * Open for reading and writing.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    * If it exists, it is neither truncated (as opposed to 'w'), nor the call to this function fails (as is the case with 'x').
    */
   public const string CREATE_READ_WRITE_MODE = 'c+';

   // @ (w)rite
   /**
    * Open for writing only.
    * ⚠️ Truncate the file to zero length (wipe all file data)!
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const string CREATE_TRUNCATE_WRITEONLY_MODE = 'w';
   /**
    * Open for writing and reading.
    * ⚠️ Truncate the file to zero length (wipe all file data)!
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const string CREATE_TRUNCATE_READ_WRITE_MODE = 'w+';

   // @ (a)ppend
   /**
    * Open for writing only.
    * 
    * Place the file pointer at the end of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const string CREATE_APPEND_WRITEONLY_MODE = 'a';
   /**
    * Open for reading and writing.
    * 
    * Place the file pointer at the end of the file.
    * 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const string CREATE_APPEND_READ_WRITE_MODE = 'a+';

   // @ e(x)clusive
   /**
    * Open for writing only.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file exists, the opening will fail. 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const string CREATE_EXCLUSIVE_WRITEONLY_MODE = 'x';
   /**
    * Open for reading and writing.
    * 
    * Place the file pointer at the beginning of the file.
    * 
    * If the file exists, the opening will fail. 
    * If the file does not exist and basedir exists, attempt to create the file.
    */
   public const string CREATE_EXCLUSIVE_READ_WRITE_MODE = 'x+';

   // @ read methods
   public const string DEFAULT_READ_METHOD = 'fread';
   public const string INCLUDE_READ_METHOD = 'include';
   public const string CONTENTS_READ_METHOD = 'file_get_contents';
   public const string READFILE_READ_METHOD = 'readfile';


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
   public protected(set) string $file {
      get {
         if (isSet($this->file) === false) {
            $this->pathify();
         }

         return $this->file;
      }
   }

   // * Metadata
   /** @var resource|null */
   private $handler = null;

   public protected(set) bool $exists {
      get {
         if (isSet($this->exists) === false) {
            $this->exists = is_file($this->file);
         }

         return $this->exists;
      }
   }

   public protected(set) null|bool $EOF {
      get {
         if ($this->handler === null) {
            return null;
         }

         if (isSet($this->EOF) === false) {
            $this->EOF = feof($this->handler);
         }

         return $this->EOF;
      }
   }
   /**
    * The size of the file in bytes or null if not available.
    * @var null|int<0,max>
    */
   public protected(set) null|int $size {
      get {
         if (isSet($this->size) === false) {
            /** @var false|int<0,max> $size */
            $size = new SplFileInfo($this->file)->getSize();

            $this->size = ($size !== false) ? $size : null;
         }

         return $this->size;
      }
      set {
         $this->size = $value;
      }
   }
   public null|int $lines {
      get {
         // !
         $file = $this->file;
         $size = $this->size;

         // @
         if ($size < 100000) { // if file < 100kb use + perf method
            $lines_array = @file($file);
            $lines_count = ($lines_array !== false)
               ? count($lines_array)
               : null;
         }
         else { // else use more memory-efficient method
            $handler = $this->handler;

            if ($handler) {
               $lines_count = 0;

               while (@fgets($handler) !== false) {
                  $lines_count++;
               }
            }
            else {
               $lines_count = null;
            }
         }

         return $lines_count;
      }
   }
   // @ Path
   protected string $basename;       // /path/to/foo.html -> foo.html
   protected string $name;           // foo.html -> 'foo'
   protected string $extension;      // foo.html -> 'html'
   protected string $parent;         // /path/to/foo.html -> /path/to/
   // # Access
   public protected(set) null|int $permissions {
      get {
         if (isSet($this->permissions) === false) {
            $this->permissions = new SplFileInfo($this->file)->getPerms() | null;
         }

         return $this->permissions;
      }
   }
   public protected(set) bool $readable {
      get {
         if (isSet($this->readable) === false) {
            $this->readable = new SplFileInfo($this->file)->isReadable();
         }

         return $this->readable;
      }
   }
   public protected(set) bool $executable {
      get {
         if (isSet($this->executable) === false) {
            $this->executable = new SplFileInfo($this->file)->isExecutable();
         }

         return $this->executable;
      }
   }
   public protected(set) bool $writable {
      get {
         if (isSet($this->writable) === false) {
            $this->writable = new SplFileInfo($this->file)->isWritable();
         }

         return $this->writable;
      }
   }
   public protected(set) null|int $owner {
      get {
         if (isSet($this->owner) === false) {
            $this->owner = new SplFileInfo($this->file)->getOwner() | null;
         }

         return $this->owner;
      }
   }
   public protected(set) null|int $group {
      get {
         if (isSet($this->group) === false) {
            $this->group = new SplFileInfo($this->file)->getGroup() | null;
         }

         return $this->group;
      }
   }
   // # Content
   public string|false $contents {
      get {
         if (isSet($this->contents) === false) {
            $this->contents = $this->file
               ? file_get_contents($this->file, false)
               : false;
         }

         return $this->contents;
      }
      set {
         $contents = $this->file
            ? file_put_contents($this->file, $value)
            : false;

         if ($contents !== false) {
            $this->written = true;

            $this->size = null;

            $this->accessed = null;
            $this->created = null;
            $this->modified = null;
         }
         else {
            $this->written = false;
         }

         $this->contents = $value;
      }
   }
   public protected(set) null|MIME $MIME {
      get {
         if (isSet($this->MIME) === false) {
            $this->MIME = $this->file
               ? new MIME($this->file)
               : null;
         }

         return $this->MIME;
      }
   }
   public null|string $format {
      get {
         return $this->MIME?->format;
      }
   }
   public null|string $subtype {
      get {
         return $this->MIME?->subtype;
      }
   }
   // # Stat
   public protected(set) null|int $accessed {
      get {
         if (isSet($this->accessed) === false) {
            $this->accessed = new SplFileInfo($this->file)->getATime() | null;
         }

         return $this->accessed;
      }
   }
   public protected(set) null|int $created {
      get {
         if (isSet($this->created) === false) {
            $this->created = new SplFileInfo($this->file)->getCTime() | null;
         }

         return $this->created;
      }
   }
   public protected(set) null|int $modified {
      get {
         if (isSet($this->modified) === false) {
            $this->modified = new SplFileInfo($this->file)->getMTime() | null;
         }

         return $this->modified;
      }
   }
   // # System
   public protected(set) null|int $inode {
      get {
         if (isSet($this->inode) === false) {
            $this->inode = new SplFileInfo($this->file)->getInode() | null;
         }

         return $this->inode;
      }
   }
   public protected(set) null|string $link {
      get {
         if (isSet($this->link) === false) {
            // @phpstan-ignore-next-line
            $this->link = new SplFileInfo($this->file)->getLinkTarget() | null;
         }

         return $this->link;
      }
   }
   // # Event
   // @ write
   protected null|bool $written = null;


   public function __construct (string $path)
   {
      // * Data
      $this->Path = new Path($path);
      $this->Basedir = new Dir($this->Path->parent);
   }
   public function __get (string $name): mixed
   {
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
               /** @var string $basename */
               $basename = $this->basename ?? $this->__get('basename');
               $length = strrpos($basename, '.');

               $name = substr(
                  string: $basename,
                  offset: 0,
                  length: ($length !== false) ? $length : null
               );

               return $this->name = $name;
            case 'extension': # > php
               /** @var string $basename */
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

      // Only if $this->file was successfully constructed
      $file = $this->file ?? false;
      if ($file === '' || $file === false) {
         return false;
      }

      return null;
   }
   public function __toString (): string
   {
      return $this->file;
   }

   private function pathify (): string
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

      return $this->file = '';
   }

   /**
    * Creates a file (touch).
    *
    * @param bool $recursively Whether to create base directories if they don't exist.
    *
    * @return bool Returns true if the file was successfully created, false otherwise.
    */
   public function create (bool $recursively = true): bool
   {
      // !
      // * Data
      // Path
      $filename = ($this->file !== '')
         ? $this->file
         : $this->Path->path;
      // * Metadata
      $dir  = null;
      $file = null;

      // @
      if ($recursively === true) {
         $dir = $this->Basedir->create();
      }

      try {
         if (is_file($filename) === false) {
            $file = touch($filename); // Create file

            $this->file = $filename;
         }
      }
      catch (Throwable) {
         $file = false;
      }

      return $dir || $file;
   }
   /**
    * Opens a file in the specified mode or read-only mode by default.
    *
    * @param string $mode The mode in which to open the file (optional, default: read-only mode).
    *
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
         }
         catch (Throwable) {
            $handler = false;
         }
      }

      // Only set this->handler / pathify() if no error
      if ($handler !== false) {
         $this->handler = $handler;

         $this->file; // @phpstan-ignore-line
      }

      return $handler;
   }


   /**
    * Read data from the file using various methods.
    *
    * @param string $method The method to use for reading (default: DEFAULT_READ_METHOD).
    * @param int $offset The offset to start reading from (default: 0).
    * @param int<0,max>|null $length The number of bytes to read (default: null, reads until the end).
    *
    * @return string|int|false The read data as a string, number of bytes read as an integer, or false on failure.
    */
   public function read (
      string $method = self::DEFAULT_READ_METHOD,
      int $offset = 0,
      null|int $length = null
   ): string|int|false
   {
      // ?
      if ( ! $this->file ) {
         return false;
      }
      // ? Check offset and length
      if ($offset < 0) {
         return false;
      }
      if ($length !== null && !$length) {
         return false;
      }

      // * Data
      /** @var string|false $data */
      $data = false;
      // * Metadata
      $filterable = ($method !== self::CONTENTS_READ_METHOD) && ($offset > 0 || $length);

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
               }
               catch (Throwable) {
                  $data = false;
               }

               break;
            case self::CONTENTS_READ_METHOD:
               $data = file_get_contents(
                  $this->file,
                  false,
                  null,
                  $offset,
                  $length
               );

               break;
            case self::READFILE_READ_METHOD:
               readfile($this->file);

               $filterable = false;

               break;
            default:
               try {
                  $size = null;
                  if ($offset === 0 && $length) {
                     $size = $length;
                  }
                  if ($size === null) {
                     $size = $this->size;
                  }

                  if ($size === null || $size < 1) {
                     return false;
                  }

                  $data = @fread(
                     $this->handler,
                     $size
                  );

                  $length ??= $this->size;
               }
               catch (Throwable) {
                  $data = false;
               }
         }
      }

      if ($data === false) {
         return false;
      }

      // ?:
      if ($filterable) {
         return substr($data, $offset, $length);
      }
      // :
      return $this->contents = $data;
   }
   /**
    * Write data to the file handler.
    *
    * @param string $data The data to be written to the file.
    * @param null|int<0,max> $length [optional] If the length argument is given,
    *                 writing will stop after length bytes have been written or the end of string is reached,
    *                 whichever comes first.
    *
    * @return int|false The number of bytes written, or false on failure.
    */
   public function write (string $data, null|int $length = null): int|false
   {
      try {
         if ($this->handler === null) {
            return false;
         }

         $bytes = @fwrite($this->handler, $data, $length);
      }
      catch (Throwable) {
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
   public function close (): bool
   {
      // @
      try {
         if ($this->handler === null) {
            return true;
         }

         @fclose($this->handler);
      }
      catch (Throwable) {
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
   public function delete (): bool
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
      }
      catch (Throwable) {
         return false;
      }

      return true;
   }

   public function __destruct ()
   {
      $this->handler !== null && $this->close();
   }
}
