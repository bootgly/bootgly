<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache;


use const BOOTGLY_WORKING_DIR;
use function defined;
use function is_bool;
use function is_scalar;
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
   public const int DEFAULT_SEGMENT = 0;
   public const int DEFAULT_SIZE = 16777216;
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
    * Base directory used by the File driver.
    */
   public string $path;
   /**
    * System V IPC key for the Shared-memory driver; 0 derives one from the driver file.
    */
   public int $segment;
   /**
    * Shared-memory segment size in bytes.
    */
   public int $size;
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
      $path = $config['path'] ?? self::locate();
      $segment = $config['segment'] ?? self::DEFAULT_SEGMENT;
      $size = $config['size'] ?? self::DEFAULT_SIZE;
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
      $this->path = rtrim(is_scalar($path) ? (string) $path : self::locate(), '/');
      $this->segment = is_scalar($segment) ? (int) $segment : self::DEFAULT_SEGMENT;
      $this->size = is_scalar($size) ? (int) $size : self::DEFAULT_SIZE;
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
      if (defined('BOOTGLY_WORKING_DIR') === true) {
         return BOOTGLY_WORKING_DIR . '/workdata/cache';
      }

      // :
      return sys_get_temp_dir() . '/bootgly-cache';
   }
}
