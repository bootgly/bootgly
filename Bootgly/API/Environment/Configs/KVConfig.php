<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment\Configs;


use function is_scalar;
use InvalidArgumentException;

use Bootgly\ADI\Database\Config as ADIConfig;
use Bootgly\API\Environment\Configs\Config;


/**
 * Converts the native `kv` config scope into an ADI database config.
 *
 * The KV counterpart to `DatabaseConfig`: it lives in API so ADI stays
 * independent from the higher `Environment\Configs` feature while WPI/project
 * code can use the canonical Bootgly configuration loader. KV speaks to a
 * single key-value driver (Redis), so the scope is flat — no replicas.
 */
class KVConfig
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
      if ($Config->scope !== 'kv') {
         throw new InvalidArgumentException('KV config adapter expects the kv scope.');
      }

      // * Config
      $this->Config = $Config;
   }

   /**
    * Build the ADI-native key-value configuration value.
    */
   public function configure (): ADIConfig
   {
      // ! Driver
      $config = [
         'driver' => $this->normalize($this->resolve($this->Config, 'Driver') ?? 'redis'),
      ];

      // @ Connection
      foreach ([
         'host' => 'Host',
         'port' => 'Port',
         'timeout' => 'Timeout',
      ] as $key => $name) {
         $value = $this->resolve($this->Config, $name);

         if ($value !== null) {
            $config[$key] = $value;
         }
      }

      // @ Pool
      $Pool = $this->Config->Pool;
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

      // :
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
    * Normalize one supported key-value driver.
    */
   private function normalize (mixed $driver): string
   {
      if (is_scalar($driver) === false) {
         throw new InvalidArgumentException('KV driver config value must be scalar.');
      }

      return match ((string) $driver) {
         'redis' => 'redis',
         default => throw new InvalidArgumentException("Unsupported KV driver: {$driver}."),
      };
   }
}
