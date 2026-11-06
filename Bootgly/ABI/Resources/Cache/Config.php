<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache;


use const BOOTGLY_STORAGE_DIR;
use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function defined;
use function dirname;
use function function_exists;
use function getcwd;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_finite;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function lstat;
use function ltrim;
use function mkdir;
use function posix_geteuid;
use function preg_match;
use function readlink;
use function realpath;
use function reset;
use function rtrim;
use function strcmp;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function umask;
use Closure;
use InvalidArgumentException;
use RuntimeException;


/**
 * ABI-native cache configuration.
 *
 * Mirrors the array-driven, constant-defaulted shape used across Bootgly so a
 * cache can be built from a plain config array or a prepared Config value.
 */
class Config
{
   public const string DEFAULT_DRIVER = 'file';
   public const string DEFAULT_PREFIX = '';
   public const int DEFAULT_TTL = 0;
   public const array DEFAULT_CLASSES = [];
   public const int DEFAULT_SEGMENT = 0;
   public const int DEFAULT_SIZE = 16777216;
   public const int DEFAULT_PERMISSIONS = 0600;
   public const string DEFAULT_HOST = '127.0.0.1';
   public const int DEFAULT_PORT = 6379;
   public const string DEFAULT_PASSWORD = '';
   public const int DEFAULT_DATABASE = 0;
   public const float DEFAULT_TIMEOUT = 5.0;
   public const bool DEFAULT_SECURE = false;
   public const bool DEFAULT_PERSISTENT = false;

   /**
    * Every option `__construct()` accepts — anything else is refused by name.
    *
    * @var array<int,string>
    */
   public const array OPTIONS = [
      'driver',
      'prefix',
      'TTL',
      'classes',
      'path',
      'segment',
      'size',
      'permissions',
      'host',
      'port',
      'password',
      'database',
      'timeout',
      'secure',
      'persistent',
      'clock'
   ];

   // * Config
   public private(set) string $driver;
   public private(set) string $prefix;
   /**
    * Default time-to-live in seconds applied when a write receives 0.
    */
   public private(set) int $TTL;
   /**
    * Classes the cache is allowed to reconstruct from a stored record.
    *
    * The `File` and `Redis` drivers decode records through a fail-closed
    * `unserialize()` allow-list: each permits its own record wrapper, and no
    * other class is reconstructed unless it is named here — so a tampered store
    * cannot run an object-injection gadget while a record is being read. An
    * object cached without being declared reads back as a miss, at any depth.
    *
    * `Shared` and `APCu` apply the same list, but later: their extension
    * functions deserialize before any driver code runs, so the list governs
    * what those two hand back rather than what gets built. A store an attacker
    * can write is still able to fire a destructor on them — protect the SysV
    * segment and the APCu store by their permissions, not by this option.
    *
    * Enums are restored regardless of this list — PHP restores them outside
    * `allowed_classes` — though an enum can carry no destructor, so none is a
    * gadget.
    *
    * @var array<int,string>
    */
   public private(set) array $classes;
   /**
    * Base directory used by the File driver. With no `path` given it is this
    * process's own cache directory (see locate()), resolved for the identity
    * that reads it: a Config built by a root master before the fork resolves
    * again in each demoted worker.
    */
   public private(set) string $path {
      get => $this->located ? self::locate() : $this->path;
   }
   /**
    * System V IPC key for the Shared-memory driver; 0 derives an application-local key.
    */
   public private(set) int $segment;
   /**
    * Shared-memory segment size in bytes.
    */
   public private(set) int $size;
   /**
    * Unix permission bits used when Shared creates its SysV objects.
    */
   public private(set) int $permissions;
   /**
    * Redis server host.
    */
   public private(set) string $host;
   /**
    * Redis server port.
    */
   public private(set) int $port;
   /**
    * Redis AUTH password ('' disables AUTH).
    */
   public private(set) string $password;
   /**
    * Redis logical database index.
    */
   public private(set) int $database;
   /**
    * Redis connect/read timeout in seconds.
    */
   public private(set) float $timeout;
   /**
    * Whether the Redis connection uses TLS.
    */
   public private(set) bool $secure;
   /**
    * Whether the Redis connection is persistent across requests/processes.
    */
   public private(set) bool $persistent;
   /**
    * Optional clock override returning a Unix timestamp; null uses time().
    */
   public private(set) null|Closure $clock;

   // * Data
   // ...

   // * Metadata
   /** Whether `path` is the located default rather than a given one. */
   public private(set) bool $located = false;
   /**
    * The located cache directory of the booted storage root, per identity,
    * with the private temporary directory it sits in (null: the storage tree)
    * and the entry watched above it (null: none, see guard()).
    * @var array<int,array{0:string,1:null|string,2:null|array{0:string,1:int,2:int,3:int}}>
    */
   private static array $directories = [];


   /**
    * Create a configuration value.
    *
    * @param array<string,mixed> $config
    */
   public function __construct (array $config = [])
   {
      // ? Unknown option — a misspelled key would silently take the default
      $unknown = array_diff(array_keys($config), self::OPTIONS);
      if ($unknown !== []) {
         $option = (string) reset($unknown);
         throw new InvalidArgumentException(
            "Unknown cache option '{$option}'."
            . ' Valid options: ' . implode(', ', self::OPTIONS) . '.'
         );
      }
      // ? Invalid TTL. The property is an int and `0` means forever, so every
      //   value that cannot survive the cast is refused here instead of
      //   becoming one: '1h' and 0.5 would truncate to 0 (forever), -5 would
      //   never expire, INF/NAN would throw out of the cast itself, and an
      //   explicit null is a misconfiguration, not an omission
      if (array_key_exists('TTL', $config) && self::check($config['TTL']) === false) {
         throw new InvalidArgumentException(
            "Invalid cache option 'TTL': expected whole, non-negative seconds."
         );
      }

      // !
      $driver = $config['driver'] ?? self::DEFAULT_DRIVER;
      $prefix = $config['prefix'] ?? self::DEFAULT_PREFIX;
      $TTL = $config['TTL'] ?? self::DEFAULT_TTL;
      $classes = $config['classes'] ?? self::DEFAULT_CLASSES;
      $path = $config['path'] ?? null;
      $segment = $config['segment'] ?? self::DEFAULT_SEGMENT;
      $size = $config['size'] ?? self::DEFAULT_SIZE;
      $permissions = $config['permissions'] ?? self::DEFAULT_PERMISSIONS;
      $host = $config['host'] ?? self::DEFAULT_HOST;
      $port = $config['port'] ?? self::DEFAULT_PORT;
      $password = $config['password'] ?? self::DEFAULT_PASSWORD;
      $database = $config['database'] ?? self::DEFAULT_DATABASE;
      $timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT;
      $secure = $config['secure'] ?? self::DEFAULT_SECURE;
      $persistent = $config['persistent'] ?? self::DEFAULT_PERSISTENT;
      $clock = $config['clock'] ?? null;

      // * Config
      $this->driver = is_scalar($driver) ? (string) $driver : self::DEFAULT_DRIVER;
      $this->prefix = is_scalar($prefix) ? (string) $prefix : self::DEFAULT_PREFIX;
      $this->TTL = is_scalar($TTL) ? (int) $TTL : self::DEFAULT_TTL;
      $this->classes = self::filter($classes);
      $this->located = is_scalar($path) === false;
      $this->path = is_scalar($path) ? rtrim((string) $path, '/') : '';
      $this->segment = is_scalar($segment) ? (int) $segment : self::DEFAULT_SEGMENT;
      $this->size = is_scalar($size) ? (int) $size : self::DEFAULT_SIZE;
      $this->permissions = is_scalar($permissions)
         ? ((int) $permissions & 0777)
         : self::DEFAULT_PERMISSIONS;
      $this->host = is_scalar($host) ? (string) $host : self::DEFAULT_HOST;
      $this->port = is_scalar($port) ? (int) $port : self::DEFAULT_PORT;
      $this->password = is_scalar($password) ? (string) $password : self::DEFAULT_PASSWORD;
      $this->database = is_scalar($database) ? (int) $database : self::DEFAULT_DATABASE;
      $this->timeout = is_scalar($timeout) ? (float) $timeout : self::DEFAULT_TIMEOUT;
      $this->secure = is_bool($secure) ? $secure : self::DEFAULT_SECURE;
      $this->persistent = is_bool($persistent) ? $persistent : self::DEFAULT_PERSISTENT;
      $this->clock = $clock instanceof Closure ? $clock : null;
   }

   /**
    * Locate this process's own cache directory.
    *
    * A process uses a cache directory only when it can trust it: a plain
    * directory of its own, writable by nobody else, under ancestors that only
    * root or itself can change (the session store's rule). Root-run code
    * `include`s the compiled templates it finds in its cache, so trusting a
    * tree another identity controls would run that identity's code as root.
    * In order: `storage/cache` (the storage administrator's — root, on a root
    * launch), then `storage/cache-<euid>` (the demoted workers', provisioned
    * by a root launch), then a private `bootgly-<euid>/<app>` in the system
    * temporary directory — or in `/tmp` when that one cannot be trusted —,
    * one per storage root (a storage tree this process cannot trust: a
    * group-shared or another user's `storage/`, a mount that cannot hold
    * modes). The storage root is checked as written before it is resolved —
    * relative to the working directory, `/` keeping its own `/cache` —: a
    * component that is missing or someone else's, on the written path or
    * where one of its links leads, or a directory others may write without
    * the sticky bit, keeps the process out of that tree. A missing candidate
    * is created owner-only when its parent is trusted and this process's
    * own. The default (booted) storage root is located once per identity: a
    * location in the storage tree is kept until forget() — under a sticky
    * shared directory, only while the entry below it is the same directory of
    * the same owner (see guard()) —, a temporary one is proven again on every
    * call.
    *
    * @param null|string $storage The storage root; the booted one by default.
    *
    * @throws RuntimeException When not even a private temporary directory can be trusted (naming the ones tried).
    *
    * @return string The cache directory, without a trailing slash.
    */
   public static function locate (null|string $storage = null): string
   {
      // ! No identity to tell apart (no posix, e.g. Windows): today's paths
      if (function_exists('posix_geteuid') === false) {
         return $storage === null && defined('BOOTGLY_STORAGE_DIR') === false
            ? sys_get_temp_dir() . '/bootgly-cache'
            : rtrim($storage ?? BOOTGLY_STORAGE_DIR, '/') . '/cache';
      }
      $EUID = posix_geteuid();

      // ? The booted storage root: located once per identity. A location in the
      //   storage tree under directories only root or this user can change is
      //   handed out as is (a consumer that finds it gone calls forget()); under
      //   a sticky shared one (`/tmp/app/storage`) the entry just below it is
      //   watched by inode — removed, anyone could recreate the name; a private
      //   temporary one is proven again on every use, for the same reason
      $booted = $storage === null;
      if ($booted && isSet(self::$directories[$EUID])) {
         [$located, $temporary, $guard] = self::$directories[$EUID];
         $state = $guard === null ? null : @lstat($guard[0]);
         $kept = $temporary === null
            ? $guard === null || (
               is_array($state)
               && ((int) $state['mode'] & 0170000) === 0040000
               && (int) $state['dev'] === $guard[1]
               && (int) $state['ino'] === $guard[2]
               && (int) $state['uid'] === $guard[3]
               && ((int) $state['mode'] & 0022) === 0
            )
            : self::own($temporary, $EUID) && self::own($located, $EUID);
         if ($kept) {
            return $located;
         }
         unset(self::$directories[$EUID]);
      }

      // !
      $candidates = [];
      $root = '';
      if ($booted === false || defined('BOOTGLY_STORAGE_DIR')) {
         $root = rtrim($storage ?? BOOTGLY_STORAGE_DIR, '/');
         // ! The filesystem root itself, and relative to the working directory
         //   as realpath() would resolve it
         if ($root === '') {
            $root = '/';
         }
         else if ($root[0] !== '/') {
            $root = rtrim((string) getcwd(), '/') . "/{$root}";
         }
         // ! The configured path is checked as written before it is resolved: a
         //   component someone else could replace would choose where it leads
         $steady = self::steady($root, $EUID);
         $resolved = realpath($root);
         $root = $resolved === false ? $root : $resolved;
         if ($steady) {
            $tree = rtrim($root, '/');
            $candidates[] = "{$tree}/cache";
            $candidates[] = "{$tree}/cache-{$EUID}";
         }
      }
      // ! The private fallback: one per user AND storage root — two apps of one
      //   user never share a store, nor clear each other's. Resolved, since
      //   the system temporary directory is a link on some hosts (macOS); and
      //   `/tmp` itself after it (a root process that inherited a user's
      //   private TMPDIR through `sudo -E` cannot trust that one)
      $application = substr(hash('sha256', $root === '' ? 'unbooted' : $root), 0, 16);
      $temporaries = [];
      foreach ([sys_get_temp_dir(), '/tmp'] as $system) {
         $resolved = realpath($system);
         $temporaries[] = rtrim($resolved === false ? $system : $resolved, '/') . "/bootgly-{$EUID}";
      }
      $temporaries = array_values(array_unique($temporaries));

      // @@
      $located = null;
      foreach ($candidates as $candidate) {
         if (self::trust($candidate, $EUID)) {
            $located = $candidate;
            break;
         }
      }
      // ? Nothing in the storage tree: a private temporary directory
      $temporary = null;
      if ($located === null) {
         foreach ($temporaries as $candidate) {
            if (self::trust($candidate, $EUID, sticky: true) && self::trust("{$candidate}/{$application}", $EUID, sticky: true)) {
               $temporary = $candidate;
               $located = "{$candidate}/{$application}";
               break;
            }
         }
         if ($located === null) {
            $tried = implode(', ', $temporaries);
            throw new RuntimeException("No cache directory this process (uid {$EUID}) can trust: neither the storage tree nor {$tried}.");
         }
      }

      // :
      if ($booted) {
         self::$directories[$EUID] = [$located, $temporary, $temporary === null ? self::guard($located) : null];
      }

      return $located;
   }

   /**
    * Drop this user's located cache directory, so the next locate() resolves
    * it again — for a consumer that found it gone (cleared by hand): the
    * template cache when its tree cannot be made again or trusted, the File
    * driver on its next write.
    */
   public static function forget (): void
   {
      if (function_exists('posix_geteuid')) {
         unset(self::$directories[posix_geteuid()]);
      }
   }

   /**
    * The entry to watch above a located storage directory: the one just below
    * its deepest sticky group/world-writable ancestor, by path, device, inode
    * and owner — once removed, anyone could make that name again (and a
    * filesystem may hand the freed inode number to their directory; nobody
    * else can make one of root's or this user's). Null when no ancestor is
    * shared.
    *
    * @param string $directory The located directory, by its resolved path.
    *
    * @return null|array{0:string,1:int,2:int,3:int}
    */
   private static function guard (string $directory): null|array
   {
      // @@
      $child = $directory;
      $ancestor = dirname($directory);
      $guard = null;
      while ($ancestor !== $child) {
         $state = @lstat($ancestor);
         if (is_array($state) && ((int) $state['mode'] & 0022) !== 0 && ((int) $state['mode'] & 01000) !== 0) {
            $entry = @lstat($child);
            $guard = is_array($entry) ? [$child, (int) $entry['dev'], (int) $entry['ino'], (int) $entry['uid']] : null;
            break;
         }
         $child = $ancestor;
         $ancestor = dirname($ancestor);
      }

      // :
      return $guard;
   }

   /**
    * Whether a configured path can be trusted as written, before it is
    * resolved: every component — a directory or a link, and every component of
    * where a link leads — exists, belongs to root or this user, and no
    * directory on the way is group/world-writable without the sticky bit — a
    * component someone else made or can replace would choose where the path
    * leads.
    *
    * @param string $path
    * @param int $EUID
    * @param int $hops How deep in link targets this walk is (refused past 40 nested links).
    */
   private static function steady (string $path, int $EUID, int $hops = 0): bool
   {
      // ?
      if ($hops > 40) {
         return false;
      }

      // !
      $rootState = @lstat('/');
      $rootUID = is_array($rootState) ? (int) $rootState['uid'] : 0;

      // @@ Up to `/` — a missing component is refused too: anyone able to
      //    create it before realpath() runs would choose where it leads
      $component = rtrim($path, '/');
      $component = $component === '' ? '/' : $component;
      while (true) {
         $state = @lstat($component);
         $type = is_array($state) ? (int) $state['mode'] & 0170000 : 0;
         if (
            is_array($state) === false
            || in_array((int) $state['uid'], [0, $rootUID, $EUID], true) === false
            || ($type === 0040000 && ((int) $state['mode'] & 0022) !== 0 && ((int) $state['mode'] & 01000) === 0)
         ) {
            return false;
         }
         // ? A link: where it leads is held to the same rule
         if ($type === 0120000) {
            $target = readlink($component);
            if ($target === false) {
               return false;
            }
            // ! A relative target from the link's parent as resolved — the walk
            //   below checks that parent as written, so nothing is walked twice
            $parent = realpath(dirname($component));
            $target = $target[0] === '/' ? $target : ($parent === false ? dirname($component) : rtrim($parent, '/')) . "/{$target}";
            if (self::steady($target, $EUID, $hops + 1) === false) {
               return false;
            }
         }

         $parent = dirname($component);
         if ($parent === $component) {
            break;
         }
         $component = $parent;
      }

      // :
      return true;
   }

   /**
    * Whether a directory is still a plain one of this user that nobody else may
    * write — the per-use proof of a directory trusted earlier.
    *
    * @param string $directory
    * @param int $EUID
    */
   private static function own (string $directory, int $EUID): bool
   {
      $state = @lstat($directory);

      // :
      return is_array($state)
         && ((int) $state['mode'] & 0170000) === 0040000
         && (int) $state['uid'] === $EUID
         && ((int) $state['mode'] & 0022) === 0;
   }

   /**
    * Whether this process can trust a cache directory — creating it,
    * owner-only, when it is missing and its parent is this process's own.
    *
    * Trusted: a plain directory (not a link) of the given user that its
    * group and others cannot write, under ancestors that only root (uid 0 or
    * the owner of `/`) or that user can change — a group/world-writable one
    * only with the sticky bit. Callers that keep a subdirectory of the
    * located directory (compiled templates) hold it to the same rule. Every
    * ancestor is checked before anything is created.
    *
    * @param string $directory The directory, by its resolved path.
    * @param int $EUID The user it must belong to.
    * @param bool $sticky Whether a missing directory may be created inside a sticky one of root's (the system temporary directory only).
    *
    * @return bool Whether it is (now) a directory this user can trust.
    */
   public static function trust (string $directory, int $EUID, bool $sticky = false): bool
   {
      // ! Root is uid 0 — and whoever owns `/` (a user namespace shows the
      //   host's root as an overflow uid there)
      $rootState = @lstat('/');
      $rootUID = is_array($rootState) ? (int) $rootState['uid'] : 0;

      // @@ Every ancestor first — nothing is created under a chain that would
      //    be refused: a directory of root's or this user's, group/world-
      //    writable only with the sticky bit (nobody else can then replace an
      //    entry they do not own)
      $ancestor = dirname($directory);
      while (true) {
         $state = @lstat($ancestor);
         if (
            is_array($state) === false
            || ((int) $state['mode'] & 0170000) !== 0040000
            || in_array((int) $state['uid'], [0, $rootUID, $EUID], true) === false
            || (((int) $state['mode'] & 0022) !== 0 && ((int) $state['mode'] & 01000) === 0)
         ) {
            return false;
         }

         $parent = dirname($ancestor);
         if ($parent === $ancestor) {
            break;
         }
         $ancestor = $parent;
      }

      // ? Missing: created only inside a parent of this user's own — or, where
      //   the caller allows it (the system temporary directory), a sticky one
      //   of root's, where a name someone else took first is refused below
      $state = @lstat($directory);
      if ($state === false) {
         $parent = @lstat(dirname($directory));
         $mode = is_array($parent) ? (int) $parent['mode'] : 0;
         $owner = is_array($parent) ? (int) $parent['uid'] : -1;
         $own = $owner === $EUID && ($mode & 0022) === 0;
         $shared = $sticky && in_array($owner, [0, $rootUID], true) && ($mode & 01000) !== 0;
         if ($own === false && $shared === false) {
            return false;
         }
         $mask = umask(0077);
         try {
            @mkdir($directory, 0700);
         }
         finally {
            umask($mask);
         }
         $state = @lstat($directory);
      }

      // :
      return is_array($state)
         && ((int) $state['mode'] & 0170000) === 0040000
         && (int) $state['uid'] === $EUID
         && ((int) $state['mode'] & 0022) === 0;
   }

   /**
    * Whether a TTL option is whole, non-negative seconds an int can hold.
    */
   private static function check (mixed $seconds): bool
   {
      // ?: An int only has to be non-negative
      if (is_int($seconds)) {
         return $seconds >= 0;
      }
      // ?: Digits are compared as digits — a 19-digit string near the ceiling
      //    rounds up in float space and would be refused or, worse, cast
      if (is_string($seconds) && preg_match('/\A\+?[0-9]+\z/', $seconds) === 1) {
         $digits = ltrim(ltrim($seconds, '+'), '0');

         return strlen($digits) < 19
            || (strlen($digits) === 19 && strcmp($digits, '9223372036854775807') <= 0);
      }
      // : Everything else goes through float space, where the ceiling is the
      //   float 2^63 — `(float) PHP_INT_MAX` already rounds up to it
      return is_numeric($seconds)
         && is_finite((float) $seconds)
         && (float) $seconds >= 0
         && (float) $seconds < 9223372036854775808.0
         && (float) (int) $seconds === (float) $seconds;
   }

   /**
    * Normalize a configured deserialization allow-list to plain class names.
    *
    * `class_exists()` is deliberately not called: it would autoload every
    * declared class at config time, and a class a later autoloader resolves is
    * still a valid rule. Only the shape is enforced, and an entry
    * `unserialize()` could not honor is dropped rather than widening the guard.
    *
    * @return array<int,string>
    */
   private static function filter (mixed $classes): array
   {
      // ?
      if (is_array($classes) === false) {
         return self::DEFAULT_CLASSES;
      }

      // @
      $filtered = [];
      foreach ($classes as $class) {
         // ! `unserialize()` matches against the name as serialized, which never
         //   carries a leading separator — while `\App\Product` is exactly how
         //   a plain-array config file tends to spell it. Left as written it
         //   would be kept, never match, and turn every read of that class into
         //   a silent miss. Case needs no normalizing: PHP folds it already
         $class = is_string($class) ? ltrim($class, '\\') : '';

         if ($class !== '') {
            $filtered[] = $class;
         }
      }

      // :
      return $filtered;
   }
}
