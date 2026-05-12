<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment\Configs;


use function in_array;
use function is_scalar;
use InvalidArgumentException;

use Bootgly\ADI\Database\Config as ADIConfig;
use Bootgly\API\Environment\Configs\Config;


/**
 * Converts the native `database` config scope into an ADI database config.
 *
 * This adapter lives in API so ADI remains independent from the higher
 * `Environment\Configs` feature while WPI/project code can use the canonical
 * Bootgly configuration loader. Callers may pass an already-merged scope or
 * call `merge()` before `configure()` when they hold a base scope separately.
 */
class DatabaseConfig
{
   // * Config
   public Config $Config;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (Config $Config)
   {
      // ?
      if ($Config->scope !== 'database') {
         throw new InvalidArgumentException('Database config adapter expects the database scope.');
      }

      // * Config
      $this->Config = $Config;
   }

   /**
    * Merge a base database config scope under the current scope.
    */
   public function merge (Config $Base): self
   {
      if ($Base->scope !== 'database') {
         throw new InvalidArgumentException('Database config adapter can merge only database scopes.');
      }

      $this->Config->merge($Base);

      return $this;
   }

   /**
    * Build the ADI-native database configuration value.
    */
   public function configure (): ADIConfig
   {
      $driver = $this->normalize($this->resolve($this->Config, 'Default') ?? ADIConfig::DEFAULT_DRIVER);
      $Connection = $this->select($driver);
      $Secure = $Connection->Secure;
      $Pool = $Connection->Pool;
      $config = [
         'driver' => $this->normalize($this->resolve($Connection, 'Driver') ?? $driver),
      ];

      foreach ([
         'host' => 'Host',
         'port' => 'Port',
         'database' => 'Database',
         'username' => 'Username',
         'password' => 'Password',
         'timeout' => 'Timeout',
         'statements' => 'Statements',
      ] as $key => $name) {
         $value = $this->resolve($Connection, $name);

         if ($value !== null) {
            $config[$key] = $value;
         }
      }

      $secure = [];
      $mode = $this->resolve($Secure, 'Mode');

      if ($mode !== null) {
         $secure['mode'] = $this->validate($mode);
      }

      foreach ([
         'verify' => 'Verify',
         'peer' => 'Peer',
         'cafile' => 'CAFile',
      ] as $key => $name) {
         $value = $this->resolve($Secure, $name);

         if ($value !== null) {
            $secure[$key] = $value;
         }
      }

      if ($secure !== []) {
         $config['secure'] = $secure;
      }

      $pool = [];

      foreach ([
         'min' => 'Min',
         'max' => 'Max',
      ] as $key => $name) {
         $value = $this->resolve($Pool, $name);

         if ($value !== null) {
            $pool[$key] = $value;
         }
      }

      if ($pool !== []) {
         $config['pool'] = $pool;
      }

      return new ADIConfig($config);
   }

   /**
    * Resolve one node value.
    */
   private function resolve (Config $Config, string $name): mixed
   {
      return $Config->{$name}->get();
   }

   /**
    * Normalize one supported database driver.
    */
   private function normalize (mixed $driver): string
   {
      if (is_scalar($driver) === false) {
         throw new InvalidArgumentException('Database driver config value must be scalar.');
      }

      return match ((string) $driver) {
         'pgsql', 'postgres', 'postgresql' => ADIConfig::DEFAULT_DRIVER,
         default => throw new InvalidArgumentException("Unsupported database driver: {$driver}."),
      };
   }

   /**
    * Select one driver connection subtree.
      *
      * New drivers must add their `Connections->{Driver}` scope here and in the
      * project `database` config file; PostgreSQL is the only Phase 7 driver.
    */
   private function select (string $driver): Config
   {
      return match ($driver) {
         ADIConfig::DEFAULT_DRIVER => $this->Config->Connections->PostgreSQL,
         default => throw new InvalidArgumentException("Unsupported database driver: {$driver}."),
      };
   }

   /**
    * Validate one TLS mode at the API config boundary.
    */
   private function validate (mixed $mode): string
   {
      if (is_scalar($mode) === false) {
         throw new InvalidArgumentException('Database TLS mode config value must be scalar.');
      }

      $mode = (string) $mode;

      if (in_array($mode, ADIConfig::SECURE_MODES, true)) {
         return $mode;
      }

      throw new InvalidArgumentException("Unsupported database TLS mode: {$mode}.");
   }
}
