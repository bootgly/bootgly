<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\FS;


use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;
use function bin2hex;
use function chmod;
use function clearstatcache;
use function count;
use function dirname;
use function fclose;
use function feof;
use function fflush;
use function fgets;
use function file;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function fread;
use function fstat;
use function fsync;
use function fwrite;
use function is_dir;
use function is_file;
use function is_link;
use function lstat;
use function ob_get_clean;
use function ob_start;
use function random_bytes;
use function readfile;
use function readlink;
use function realpath;
use function rename;
use function rtrim;
use function scandir;
use function stat;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strrchr;
use function strrpos;
use function substr;
use function touch;
use function umask;
use function unlink;
use AllowDynamicProperties;
use ReflectionProperty;
use SplFileInfo;
use Throwable;

use Bootgly\ABI\Code\__String\Path;
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
   /**
    * The path exactly as given to the constructor.
    *
    * `replace()` and `lock()` act on this entry — never on the normalized `Path`
    * nor on a `.php` / `/index.php` the read side converts a missing name to.
    */
   protected string $path;
   public readonly Path $Path;
   public readonly Dir $Basedir;
   public readonly string $base;
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
         if (isSet($this->file) === false) {
            $this->pathify();
         }

         // !
         $file = $this->file;
         $size = $this->size;

         if ($file === '' || $size === null) {
            return null;
         }

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
   protected null|string|false $extension;      // foo.html -> 'html'
   protected string $parent;         // /path/to/foo.html -> /path/to/
   // # Access
   public protected(set) null|int $permissions {
      get {
         if (isSet($this->permissions) === false) {
            $this->permissions = new SplFileInfo($this->file)->getPerms() ?: null;
         }

         return $this->permissions;
      }
   }
   public protected(set) null|bool $readable {
      get {
         if (isSet($this->readable) === false) {
            $this->readable = new SplFileInfo($this->file)->isReadable();
         }

         return $this->readable;
      }
   }
   public protected(set) null|bool $executable {
      get {
         if (isSet($this->executable) === false) {
            $this->executable = new SplFileInfo($this->file)->isExecutable();
         }

         return $this->executable;
      }
   }
   public protected(set) null|bool $writable {
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
            $this->owner = new SplFileInfo($this->file)->getOwner() ?: null;
         }

         return $this->owner;
      }
   }
   public protected(set) null|int $group {
      get {
         if (isSet($this->group) === false) {
            $this->group = new SplFileInfo($this->file)->getGroup() ?: null;
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
            $MIME = $this->file
               ? new MIME($this->file)
               : null;

            $this->MIME = $MIME;
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
            $this->accessed = new SplFileInfo($this->file)->getATime() ?: null;
         }

         return $this->accessed;
      }
   }
   public protected(set) null|int $created {
      get {
         if (isSet($this->created) === false) {
            $this->created = new SplFileInfo($this->file)->getCTime() ?: null;
         }

         return $this->created;
      }
   }
   public protected(set) null|int $modified {
      get {
         if (isSet($this->modified) === false) {
            $this->modified = new SplFileInfo($this->file)->getMTime() ?: null;
         }

         return $this->modified;
      }
   }
   // # System
   public protected(set) null|int $inode {
      get {
         if (isSet($this->inode) === false) {
            $this->inode = new SplFileInfo($this->file)->getInode() ?: null;
         }

         return $this->inode;
      }
   }
   public protected(set) null|string $link {
      get {
         if (isSet($this->link) === false) {
            // ! `null` for a non-link (or missing) file is this property's contract,
            //   and `SplFileInfo::getLinkTarget()` throws — not warns — on one of
            //   those very cases, so ask whether the file is a link first.
            $this->link = is_link($this->file)
               ? (readlink($this->file) ?: null)
               : null;
         }

         return $this->link;
      }
   }
   // # Event
   // @ write
   protected null|bool $written = null;
   // # Lock
   /**
    * Whether the open handle holds an advisory lock taken by `lock()`.
    *
    * Set by a successful `lock()`, cleared by `unlock()` and `close()`.
    */
   public protected(set) bool $locked = false;


   public function __construct (string $path, string $base = '')
   {
      // * Data
      $this->path = $path;
      $this->Path = new Path($path);
      $this->Basedir = new Dir($this->Path->parent);

      $this->base = $base;
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
      $file = $this->file ?: false;
      if (!$file) {
         return false;
      }

      return null;
   }
   public function __toString (): string
   {
      return $this->file;
   }

   /**
    * Path-traversal guard: returns true when access should be blocked, false when safe.
    *
    * Both parameters fall back to instance values when omitted:
    * - `$path` → `$this->file` (the pathified path resolved by pathify())
    * - `$base` → `$this->base` (the jail directory set at construction time)
    *
    * The `$base` argument is only used as a fallback when the instance base is empty
    * (i.e. no `base` was supplied to the constructor). If `$this->base` is already set,
    * it always takes precedence — the instance base cannot be overridden after construction.
    *
    * Returns `true`  (blocked) when:
    * - the resolved path falls outside the effective jail directory, or
    * - `realpath()` fails on either argument (fail-closed).
    *
    * Returns `false` (allowed) when:
    * - no effective base is set (unconstrained), or
    * - the resolved path is confirmed inside the jail.
    *
    * @param string $path The file path to validate. Defaults to `$this->file` when empty.
    * @param string $base The jail directory to validate against. Defaults to `$this->base` when empty.
    *
    * @return bool True if path traversal is detected (block the request), false if the path is safe.
    */
   public function guard (string $path = '', string $base = ''): bool
   {
      $effectivePath = ($path !== '') ? $path : $this->file;
      $effectiveBase = ($this->base !== '') ? $this->base : $base;

      if ($effectiveBase === '') {
         return false;
      }

      $resolved = realpath($effectivePath);
      $baseReal  = realpath($effectiveBase);

      return $resolved === false
         || $baseReal === false
         || !str_starts_with($resolved, $baseReal . DIRECTORY_SEPARATOR);
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
         if ($this->guard($path)) return $this->file = '';
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
            if ($this->guard($base)) return $this->file = '';
            return $this->file = $base;
         }

         // @ Convert base to file with index.php
         $index = $path . DIRECTORY_SEPARATOR . 'index.php';
         if (is_file($index) === true) {
            if ($this->guard($index)) return $this->file = '';
            return $this->file = $index;
         }
      }

      return $this->file = '';
   }
   /**
    * Resolves the filename a filesystem operation must act on, subject to the jail.
    *
    * `pathify()` only resolves paths that already exist as files, so an operation that
    * creates its own target (`create()`, `open()` in a write mode) has nothing pathified
    * to work with. Such a target is judged by the deepest existing directory it would
    * land in — the entry cannot escape a jail its parent does not escape.
    *
    * @param string $filename The entry to judge — the pathified file (or the normalized
    *                         path) when empty.
    *
    * @return string The filename to operate on, or `''` when the jail blocks it.
    */
   private function resolve (string $filename = ''): string
   {
      // ?
      if ($filename === '') {
         $filename = $this->file ?: $this->Path->path;
      }

      if ($filename === '' || $this->base === '') {
         return $filename;
      }

      // ?: An existing entry is judged by itself (guard() resolves symlinks)
      if (file_exists($filename) === true || is_link($filename) === true) {
         return $this->guard($filename) ? '' : $filename;
      }

      // ! A target that does not exist yet is judged by the directory it would land in
      $parent = dirname($filename);
      while (is_dir($parent) === false && $parent !== dirname($parent)) {
         $parent = dirname($parent);
      }

      // ?: The jail directory itself is trivially contained — guard() only matches below it
      if (realpath($parent) === realpath($this->base)) {
         return $filename;
      }

      // :
      return $this->guard($parent) ? '' : $filename;
   }

   /**
    * Pins an open file for a call that only takes a filename (`chmod()`).
    *
    * On Linux, `/proc/self/fd/<n>` names the file a descriptor holds, whatever becomes
    * of the name it was opened by: the descriptor whose link reads `$name` and whose
    * file is `$opened` (its `fstat()`) is returned. When no descriptor matches, the name
    * no longer leads to the opened file and nothing is returned. Without `/proc` (other
    * systems), the name itself is returned, for the caller to verify around the call.
    *
    * @param string $name The absolute name the file was opened by.
    * @param array<int|string,int> $opened The `fstat()` of the open handle.
    *
    * @return null|string The descriptor entry that pins the file, `$name` without `/proc`,
    *                     or null when the name was swapped away from the opened file.
    */
   private function pin (string $name, array $opened): null|string
   {
      // ! The descriptors of this process — none outside Linux
      $descriptors = @scandir('/proc/self/fd');
      if ($descriptors === false) {
         return $name;
      }

      // @@ The descriptor that holds the opened file under that name
      foreach ($descriptors as $descriptor) {
         $entry = "/proc/self/fd/{$descriptor}";
         if (@readlink($entry) !== $name) {
            continue;
         }

         clearstatcache(true, $entry);
         $state = @stat($entry);
         if ($state !== false && $state['dev'] === $opened['dev'] && $state['ino'] === $opened['ino']) {
            return $entry;
         }
      }

      // : The name was swapped away from the opened file
      return null;
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
      $filename = $this->resolve();
      // * Metadata
      $dir  = null;
      $file = null;

      // ? The jail blocks the target — create nothing, not even its parent directories
      if ($filename === '') {
         return false;
      }

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
      $filename = $this->resolve();

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
    * @return string|false The read data as a string or false on failure.
    */
   public function read (
      string $method = self::DEFAULT_READ_METHOD,
      int $offset = 0,
      null|int $length = null
   ): string|false
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
      return $data;
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
    * Replace the file's contents durably and atomically.
    *
    * The entry replaced is exactly the path given to the constructor, its directory
    * resolved as the kernel resolves it — never the normalized `Path` (which turns a
    * backslash into a separator and folds `..` lexically), and never the `.php` or
    * `/index.php` sibling the read side converts a missing name to. A path that ends in
    * `/`, whose last segment is `.` or `..` or carries a backslash, that holds a NUL
    * byte or that names a stream wrapper (`scheme://`) is refused.
    *
    * The data is written to a randomly named temporary sibling (`.<random>.tmp`, created
    * exclusively in the target's directory) under a umask that adds to the process umask
    * every bit `$permissions` leaves out: the temporary never holds a bit beyond
    * `$permissions`, and since the umask is process-wide, a file created anywhere else in
    * that instant never gets a bit the process umask removes. The mode created differs
    * from `$permissions` when the process umask removed a requested bit, when execute bits
    * are requested (`fopen()` creates from `0666`) or when the directory carries a default
    * ACL: the temporary is then `chmod()`ed to `$permissions` before its first byte — only
    * after `lstat()` proves the name is still the file the handle opened, and the replace
    * is refused when it is not that file before or after the call. PHP has no `fchmod()`:
    * on Linux the `chmod()` goes through the handle's own `/proc/self/fd` entry, which
    * names the opened file whatever becomes of its name (no such entry left under that
    * name refuses the replace). Without `/proc` (other systems), it goes through the
    * name, and a writer able to swap the name in the instant between `lstat()` and
    * `chmod()` can still redirect that one call — the replace is then refused, never
    * reported as done. When the mode created is already `$permissions`, no `chmod()`
    * runs. On a filesystem that ignores `chmod()` (modes fixed by mount options), a mode
    * that still differs is accepted only when `$permissions` is what the process umask
    * gives a new file (`0666 & ~umask()`) — the caller asked for nothing a plain `fopen()`
    * would not have given; any other request is refused.
    *
    * Once every byte is written and synced to the disk, the temporary is renamed over
    * the target, so a reader sees either the whole old file or the whole new one, never
    * a truncated or partial file. The directory is then synced on a best-effort basis:
    * the swap has already happened and cannot be undone, so a directory that cannot be
    * opened or synced does not turn the replace into a failure — the new contents are in
    * place, only the new entry's survival of a power loss in the next instants is not
    * guaranteed.
    *
    * The target entry itself is never opened: a symlink at the target is replaced by the
    * new file, not followed. The `base` jail is judged by the directory the entry lands in,
    * resolved when `replace()` runs. PHP has no `openat()`: the temporary and the rename
    * name that directory by its path, so a writer inside the jail able to swap one of its
    * components for a link between the check and the write can still redirect them — the
    * jail confines the paths a caller hands over, not a tree shared with such a writer.
    * On any failure before the rename, the temporary is removed and the target is left
    * untouched. A process killed mid-write cannot remove its temporary: the target stays
    * intact and a hidden `.<random>.tmp` sibling is left behind. After a replace, the
    * File describes the new entry: `file` names it, and every cached property (contents,
    * size, permissions, access, owner, group, MIME, times, inode, link) is refreshed.
    *
    * An open handle (from `open()` or `lock()`) keeps pointing to the replaced file: to
    * serialize writers, lock a separate lock file, not the replaced one.
    *
    * Without `$durable`, both syncs are skipped and everything else stays the same: the swap
    * is still atomic for every reader, but after a power loss or a kernel crash the target
    * may hold the old contents or, on some filesystems, an empty file. Use it only for
    * records that can be regenerated (caches, transient queues), never for user data.
    *
    * @param string $data The complete new contents.
    * @param int $permissions The permission bits of the new file (`0` to `0777`).
    * @param bool $durable Sync the new file and its directory to the disk (`true`), or only
    *                      swap it atomically (`false`).
    *
    * @return bool Returns true when the target holds the new contents, false otherwise.
    */
   public function replace (string $data, int $permissions = 0600, bool $durable = true): bool
   {
      // ? Permission bits only — never setuid, setgid or sticky
      if ($permissions < 0 || $permissions > 0777) {
         return false;
      }

      // ! The exact entry the caller named — never normalized, never converted
      $path = $this->path;

      // ? A NUL byte never reaches the filesystem; a stream wrapper cannot rename()
      if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
         return false;
      }

      // ! The last segment and its directory, split on `/` only — as the kernel splits them
      $slash = strrpos($path, '/');
      $name = ($slash === false) ? $path : substr($path, $slash + 1);
      $parent = match ($slash) {
         false   => '.',
         0       => '/',
         default => substr($path, 0, $slash)
      };

      // ? An entry name — never a directory, never a separator of another system
      if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '\\')) {
         return false;
      }

      // ! The directory the entry lands in, resolved as the kernel's rename() will see it
      clearstatcache(true);
      $directory = realpath($parent);
      if ($directory === false || is_dir($directory) === false) {
         return false;
      }
      $directory = rtrim($directory, DIRECTORY_SEPARATOR);

      // ? The jail judges the directory: the entry itself is replaced, never followed
      if ($this->base !== '') {
         $jail = realpath($this->base);
         if ($jail === false) {
            return false;
         }
         $jail = rtrim($jail, DIRECTORY_SEPARATOR);

         if ($directory !== $jail && str_starts_with("{$directory}/", "{$jail}/") === false) {
            return false;
         }
      }

      // ! An absolute sibling nobody can predict — never read as a stream wrapper
      $random = bin2hex(random_bytes(8));
      $target = "{$directory}/{$name}";
      $temporary = "{$directory}/.{$random}.tmp";

      // @ Created exclusively, under a umask that adds to the process one every bit
      //   `$permissions` leaves out: the umask is process-wide, and a file created
      //   anywhere else in this instant never gets a bit the process umask removes
      $mask = umask();
      umask((~$permissions & 0777) | $mask);
      try {
         $Handle = @fopen($temporary, 'xb');
      }
      catch (Throwable) {
         $Handle = false;
      }
      finally {
         umask($mask);
      }
      // ?
      if ($Handle === false) {
         return false;
      }
      // ? The permissions before the first byte
      $state = @fstat($Handle);
      if ($state !== false && ($state['mode'] & 0777) !== $permissions) {
         // ? A bit the process umask removed, an execute bit (`fopen()` creates from 0666) or a
         //   default ACL: chmod() the opened file, never what a name became
         $opened = $state;
         $entry = @lstat($temporary);
         $state = (
            $entry !== false
            && $entry['dev'] === $opened['dev'] && $entry['ino'] === $opened['ino']
            && ($pinned = $this->pin($temporary, $opened)) !== null
            && @chmod($pinned, $permissions) === true
         ) ? @fstat($Handle) : false;

         // ? A name swapped meanwhile sent the mode elsewhere — never adopt what follows
         clearstatcache(true, $temporary);
         $entry = ($state !== false) ? @lstat($temporary) : false;
         if ($entry === false || $entry['dev'] !== $opened['dev'] || $entry['ino'] !== $opened['ino']) {
            $state = false;
         }

         // ? A filesystem that ignores chmod(): only the mode a plain fopen() gives is accepted
         if ($state !== false && ($state['mode'] & 0777) !== $permissions && $permissions !== (0666 & ~$mask)) {
            $state = false;
         }
      }
      if ($state === false) {
         fclose($Handle);
         @unlink($temporary);

         return false;
      }

      // @@ Every byte, then to the disk when durable
      $length = strlen($data);
      $written = false;
      try {
         $offset = 0;
         while ($offset < $length) {
            $bytes = @fwrite($Handle, substr($data, $offset));
            if ($bytes === false || $bytes === 0) {
               break;
            }
            $offset += $bytes;
         }
         $written = $offset === $length && @fflush($Handle) && ($durable === false || @fsync($Handle));
      }
      catch (Throwable) {
         $written = false;
      }
      finally {
         fclose($Handle);
      }

      // @ Swap the entry: `rename()` never opens what a link points to
      try {
         $renamed = $written && @rename($temporary, $target);
      }
      catch (Throwable) {
         $renamed = false;
      }
      if ($renamed === false) {
         @unlink($temporary);

         return false;
      }

      // @ The new entry to the disk — best effort: the swap has already landed and stays
      if ($durable) {
         $Directory = @fopen($directory !== '' ? $directory : DIRECTORY_SEPARATOR, 'r');
         if ($Directory !== false) {
            @fsync($Directory);
            fclose($Directory);
         }
      }

      // ! The File describes the new entry — nothing cached from the replaced one survives
      $this->file = $target;
      $this->exists = true;
      $this->written = true;
      $this->size = $length;
      $this->permissions = null;
      $this->readable = null;
      $this->executable = null;
      $this->writable = null;
      $this->owner = null;
      $this->group = null;
      $this->MIME = null;
      $this->accessed = null;
      $this->created = null;
      $this->modified = null;
      $this->inode = null;
      $this->link = null;
      // ! Contents read before are what was just written — set raw: the hook would write them
      $Contents = new ReflectionProperty($this, 'contents');
      if ($Contents->isInitialized($this) === true) {
         $Contents->setRawValue($this, $data);
      }

      // :
      return true;
   }

   /**
    * Take an advisory lock (`flock()`) on the file's open handle.
    *
    * Without an open handle, one is opened on exactly the path given to the constructor —
    * never the normalized `Path`, never a `.php` or `/index.php` the read side converts a
    * missing name to — in a mode that never truncates: read/write, creating a missing
    * lock file (`c+`), or read-only when the file is not writable. A path holding a NUL
    * byte or naming a stream wrapper (`scheme://`) is refused, and the `base` jail judges
    * the entry (or, for a missing one, the directory it would land in). Once opened here,
    * the File describes that entry: `file` names it — `read()` and `delete()` act on it —
    * and every cached property (contents, size, permissions, access, owner, group, MIME,
    * times, inode, link) is refreshed. A handle opened
    * here is closed again when the lock is not taken. Two handles of the same process
    * conflict like two processes do; the lock is released by `unlock()`, `close()` or the
    * end of the process.
    *
    * A lock of the other type on a handle that already holds one is a conversion, and a
    * conversion is not atomic: Linux drops the held lock before it tries the new one. A
    * conversion that fails (a busy lock without `$wait`) therefore leaves the handle with
    * no lock at all — `locked` turns false on every failed `lock()`, never claiming a
    * lock the kernel may have dropped.
    *
    * @param bool $exclusive An exclusive lock (`true`, writers) or a shared one (`false`, readers).
    * @param bool $wait Block until the lock is free (`true`), or fail at once when it is busy (`false`).
    *
    * @return bool Returns true when the lock is held, false when it could not be taken —
    *              a busy lock without `$wait` included.
    */
   public function lock (bool $exclusive = true, bool $wait = true): bool
   {
      // ! A handle on the exact entry the caller named, that never truncates
      $opened = false;
      if ($this->handler === null) {
         $path = $this->path;

         // ? A NUL byte never reaches the filesystem; a stream wrapper holds no advisory lock
         if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            return false;
         }

         // ? The jail judges the entry — or the directory a missing one lands in
         $filename = $this->resolve($path);
         if ($filename === '') {
            return false;
         }

         try {
            $Handler = @fopen($filename, self::CREATE_READ_WRITE_MODE);
            if ($Handler === false) {
               $Handler = @fopen($filename, self::READONLY_MODE);
            }
         }
         catch (Throwable) {
            $Handler = false;
         }

         // ?
         if ($Handler === false) {
            return false;
         }

         $this->handler = $Handler;
         $opened = true;

         // ! The File describes the entry it opened — never a `.php` / `/index.php` the read
         //   side converted the name to: read(), delete() and every cached property follow it
         $state = @fstat($Handler);
         $this->file = $filename;
         $this->exists = $state !== false && ($state['mode'] & 0170000) === 0100000;
         $this->size = ($state !== false && $state['size'] >= 0) ? $state['size'] : null;
         $this->permissions = null;
         $this->readable = null;
         $this->executable = null;
         $this->writable = null;
         $this->owner = null;
         $this->group = null;
         $this->MIME = null;
         $this->accessed = null;
         $this->created = null;
         $this->modified = null;
         $this->inode = null;
         $this->link = null;
         // ! Contents read before came from the other entry — set raw: the hook would write them
         $Contents = new ReflectionProperty($this, 'contents');
         if ($Contents->isInitialized($this) === true) {
            $Contents->setRawValue($this, @file_get_contents($filename));
         }
      }

      // @
      $operation = ($exclusive ? LOCK_EX : LOCK_SH) | ($wait ? 0 : LOCK_NB);
      try {
         $locked = $this->handler !== null && @flock($this->handler, $operation);
      }
      catch (Throwable) {
         $locked = false;
      }

      // ?
      if ($locked === false) {
         // ! A failed conversion dropped the lock the handle held (Linux)
         $this->locked = false;

         if ($opened === true) {
            $this->close();
         }

         return false;
      }

      $this->locked = true;

      // :
      return true;
   }
   /**
    * Release the advisory lock taken by `lock()`, keeping the handle open.
    *
    * @return bool Returns true when the handle holds no lock anymore, false when there is
    *              no open handle or the release failed.
    */
   public function unlock (): bool
   {
      // ?
      if ($this->handler === null) {
         return false;
      }

      // @
      try {
         $unlocked = @flock($this->handler, LOCK_UN);
      }
      catch (Throwable) {
         $unlocked = false;
      }

      if ($unlocked === true) {
         $this->locked = false;
      }

      // :
      return $unlocked;
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
      $this->locked = false;

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
      $filename = $this->resolve();

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
