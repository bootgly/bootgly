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
use function defined;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function ltrim;
use function rtrim;
use function sys_get_temp_dir;
use Closure;


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

   // * Config
   public string $driver;
   public string $prefix;
   /**
    * Default time-to-live in seconds applied when store()/increment() receive 0.
    */
   public int $TTL;
   /**
    * Classes the cache is allowed to reconstruct from a stored record.
    *
    * The `File` and `Redis` drivers decode records through a fail-closed
    * `unserialize()` allow-list: each permits its own record wrapper, and no
    * other class is reconstructed unless it is named here — so a tampered store
    * cannot run an object-injection gadget while a record is being read. An
    * object cached without being declared reads back as a miss, at any depth.
    *
    * It does not reach `Shared` or `APCu`: those deserialize inside
    * `shm_get_var()` and `apcu_fetch()`, which accept no options, so both trust
    * their backing store completely. Enums are restored regardless of this
    * list — PHP restores them outside `allowed_classes` — though an enum can
    * carry no destructor, so none is a gadget.
    *
    * @var array<int,string>
    */
   public array $classes;
   /**
    * Base directory used by the File driver.
    */
   public string $path;
   /**
    * System V IPC key for the Shared-memory driver; 0 derives an application-local key.
    */
   public int $segment;
   /**
    * Shared-memory segment size in bytes.
    */
   public int $size;
   /**
    * Unix permission bits used when Shared creates its SysV objects.
    */
   public int $permissions;
   /**
    * Redis server host.
    */
   public string $host;
   /**
    * Redis server port.
    */
   public int $port;
   /**
    * Redis AUTH password ('' disables AUTH).
    */
   public string $password;
   /**
    * Redis logical database index.
    */
   public int $database;
   /**
    * Redis connect/read timeout in seconds.
    */
   public float $timeout;
   /**
    * Whether the Redis connection uses TLS.
    */
   public bool $secure;
   /**
    * Whether the Redis connection is persistent across requests/processes.
    */
   public bool $persistent;
   /**
    * Optional clock override returning a Unix timestamp; null uses time().
    */
   public null|Closure $clock;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Create a configuration value.
    *
    * @param array<string,mixed> $config
    */
   public function __construct (array $config = [])
   {
      $driver = $config['driver'] ?? self::DEFAULT_DRIVER;
      $prefix = $config['prefix'] ?? self::DEFAULT_PREFIX;
      $TTL = $config['ttl'] ?? self::DEFAULT_TTL;
      $classes = $config['classes'] ?? self::DEFAULT_CLASSES;
      $path = $config['path'] ?? self::locate();
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
      $this->path = rtrim(is_scalar($path) ? (string) $path : self::locate(), '/');
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
    * Locate the default cache directory.
    */
   private static function locate (): string
   {
      // ?: Inside a booted Bootgly working dir
      if (defined('BOOTGLY_STORAGE_DIR') === true) {
         return BOOTGLY_STORAGE_DIR . 'cache';
      }

      // :
      return sys_get_temp_dir() . '/bootgly-cache';
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
