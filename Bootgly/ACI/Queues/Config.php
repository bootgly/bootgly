<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues;


use const BOOTGLY_STORAGE_DIR;
use function defined;
use function is_bool;
use function is_scalar;
use function rtrim;
use function sys_get_temp_dir;
use Closure;

use Bootgly\ACI\Queues\Backoffs;


/**
 * Queue configuration.
 *
 * Array-driven and constant-defaulted, matching the shape used across Bootgly
 * (mirrors `ABI\Resources\Cache\Config`). A queue can be built from a plain
 * config array or a prepared Config value.
 */
class Config
{
   public const string DEFAULT_DRIVER = 'file';
   public const string DEFAULT_PREFIX = '';
   public const int DEFAULT_ATTEMPTS = 3;
   public const int DEFAULT_BASE = 10;
   public const int DEFAULT_VISIBILITY = 60;
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
    * Base directory used by the File driver.
    */
   public string $path;
   /**
    * Maximum attempts before a job is buried (dead-lettered).
    */
   public int $attempts;
   /**
    * Retry backoff policy.
    */
   public Backoffs $backoff;
   /**
    * Backoff base delay in seconds.
    */
   public int $base;
   /**
    * Reserved-job visibility timeout in seconds (reaper releases stale claims).
    */
   public int $visibility;
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


   /**
    * @param array<string,mixed> $config
    */
   public function __construct (array $config = [])
   {
      $driver = $config['driver'] ?? self::DEFAULT_DRIVER;
      $prefix = $config['prefix'] ?? self::DEFAULT_PREFIX;
      $path = $config['path'] ?? self::locate();
      $attempts = $config['attempts'] ?? self::DEFAULT_ATTEMPTS;
      $backoff = $config['backoff'] ?? Backoffs::Exponential;
      $base = $config['base'] ?? self::DEFAULT_BASE;
      $visibility = $config['visibility'] ?? self::DEFAULT_VISIBILITY;
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
      $this->path = rtrim(is_scalar($path) ? (string) $path : self::locate(), '/');
      $this->attempts = is_scalar($attempts) ? (int) $attempts : self::DEFAULT_ATTEMPTS;
      $this->backoff = $backoff instanceof Backoffs ? $backoff : Backoffs::Exponential;
      $this->base = is_scalar($base) ? (int) $base : self::DEFAULT_BASE;
      $this->visibility = is_scalar($visibility) ? (int) $visibility : self::DEFAULT_VISIBILITY;
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
    * Locate the default queue directory.
    */
   private static function locate (): string
   {
      // ?: Inside a booted Bootgly working dir
      if (defined('BOOTGLY_STORAGE_DIR') === true) {
         return BOOTGLY_STORAGE_DIR . 'queues';
      }

      // :
      return sys_get_temp_dir() . '/bootgly-queues';
   }
}
