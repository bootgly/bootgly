<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function max;
use InvalidArgumentException;


/**
 * ADI-native database configuration.
 *
 * API config scopes may build this value, but this class deliberately has no
 * API dependency so the ADI layer remains standalone.
 */
class Config
{
   public const string DEFAULT_DRIVER = 'pgsql';
   public const string DEFAULT_HOST = '127.0.0.1';
   public const int DEFAULT_PORT = 5432;
   public const string DEFAULT_DATABASE = 'bootgly';
   public const string DEFAULT_USERNAME = 'postgres';
   public const string DEFAULT_PASSWORD = '';
   public const float DEFAULT_TIMEOUT = 30.0;
   public const int DEFAULT_POOL_MIN = 0;
   public const int DEFAULT_POOL_MAX = 8;
   public const int DEFAULT_STATEMENTS = 256;
   public const string SECURE_DISABLE = 'disable';
   public const string SECURE_PREFER = 'prefer';
   public const string SECURE_REQUIRE = 'require';
   public const string SECURE_VERIFY_CA = 'verify-ca';
   public const string SECURE_VERIFY_FULL = 'verify-full';
   public const string DEFAULT_SECURE_MODE = self::SECURE_PREFER;
   public const string DEFAULT_SECURE_PEER = '';
   public const string DEFAULT_SECURE_CAFILE = '';
   public const array SECURE_MODES = [
      self::SECURE_DISABLE,
      self::SECURE_PREFER,
      self::SECURE_REQUIRE,
      self::SECURE_VERIFY_CA,
      self::SECURE_VERIFY_FULL,
   ];

   // * Config
   public string $driver;
   public string $host;
   public int $port;
   public string $database;
   public string $username;
   public string $password;
   public float $timeout;
   /** @var array{mode:string,verify:bool,name:bool,peer:string,cafile:string} */
   public array $secure;
   /** @var array{min:int,max:int} */
   public array $pool;
   /** Maximum number of prepared statements cached per connection. */
   public int $statements;

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
      $pool = $config['pool'] ?? [];
      if (is_array($pool) === false) {
         $pool = [];
      }

      $secure = $config['secure'] ?? [];
      if (is_array($secure) === false) {
         $secure = [];
      }

      $driver = $config['driver'] ?? self::DEFAULT_DRIVER;
      $host = $config['host'] ?? self::DEFAULT_HOST;
      $port = $config['port'] ?? self::DEFAULT_PORT;
      $database = $config['database'] ?? self::DEFAULT_DATABASE;
      $username = $config['username'] ?? self::DEFAULT_USERNAME;
      $password = $config['password'] ?? self::DEFAULT_PASSWORD;
      $timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT;
      $poolMin = $pool['min'] ?? self::DEFAULT_POOL_MIN;
      $poolMax = $pool['max'] ?? self::DEFAULT_POOL_MAX;
      $statements = $config['statements'] ?? self::DEFAULT_STATEMENTS;
      $secureMode = $secure['mode'] ?? self::DEFAULT_SECURE_MODE;
      $securePeer = $secure['peer'] ?? self::DEFAULT_SECURE_PEER;
      $secureCA = $secure['cafile'] ?? self::DEFAULT_SECURE_CAFILE;

      // * Config
      $this->driver = is_scalar($driver) ? (string) $driver : self::DEFAULT_DRIVER;
      $this->host = is_scalar($host) ? (string) $host : self::DEFAULT_HOST;
      $this->port = is_scalar($port) ? (int) $port : self::DEFAULT_PORT;
      $this->database = is_scalar($database) ? (string) $database : self::DEFAULT_DATABASE;
      $this->username = is_scalar($username) ? (string) $username : self::DEFAULT_USERNAME;
      $this->password = is_scalar($password) ? (string) $password : self::DEFAULT_PASSWORD;
      $this->timeout = is_scalar($timeout) ? (float) $timeout : self::DEFAULT_TIMEOUT;

      $secureMode = $this->validate(is_scalar($secureMode) ? (string) $secureMode : self::DEFAULT_SECURE_MODE);
      $secureVerify = $secure['verify'] ?? $secureMode !== self::SECURE_DISABLE;
      $secureVerify = is_bool($secureVerify) ? $secureVerify : $secureMode !== self::SECURE_DISABLE;
      $secureName = $secure['name'] ?? ($secureVerify && $secureMode !== self::SECURE_VERIFY_CA && $secureMode !== self::SECURE_DISABLE);

      if ($secureMode === self::SECURE_VERIFY_CA) {
         $secureVerify = true;
         $secureName = false;
      }

      if ($secureMode === self::SECURE_DISABLE) {
         $secureVerify = false;
         $secureName = false;
      }

      if ($secureMode === self::SECURE_VERIFY_FULL) {
         $secureVerify = true;
         $secureName = true;
      }

      $this->secure = [
         'mode' => $secureMode,
         'verify' => $secureVerify,
         'name' => is_bool($secureName) ? $secureName : true,
         'peer' => is_scalar($securePeer) && (string) $securePeer !== '' ? (string) $securePeer : $this->host,
         'cafile' => is_scalar($secureCA) ? (string) $secureCA : self::DEFAULT_SECURE_CAFILE,
      ];
      $this->pool = [
         'min' => is_scalar($poolMin) ? (int) $poolMin : self::DEFAULT_POOL_MIN,
         'max' => is_scalar($poolMax) ? (int) $poolMax : self::DEFAULT_POOL_MAX,
      ];
      $this->statements = is_scalar($statements) ? max(0, (int) $statements) : self::DEFAULT_STATEMENTS;
   }

   /**
    * Validate one TLS mode.
    */
   private function validate (string $mode): string
   {
      if (in_array($mode, self::SECURE_MODES, true)) {
         return $mode;
      }

      throw new InvalidArgumentException("Unsupported database TLS mode: {$mode}.");
   }
}
