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


use InvalidArgumentException;

use Bootgly\ABI\Data\Registry;
use Bootgly\ACI\Queues\Config;
use Bootgly\ACI\Queues\Driver;
use Bootgly\ACI\Queues\Drivers\File;
use Bootgly\ACI\Queues\Drivers\Redis;


/**
 * Registry of available queue drivers.
 *
 * Drivers are instantiated lazily on first fetch(). The registry is open via
 * register() so a higher layer (≥ ADI/WPI) can plug in drivers it can legally
 * reach — e.g. an async event-loop Redis driver wrapping `ADI/Databases/KV` —
 * without the ACI contract ever depending on that layer.
 *
 * @extends Registry<Driver>
 */
class Drivers extends Registry
{
   // * Config
   public Config $Config;

   // * Data
   /** @var array<string,class-string<Driver>> */
   protected array $map;


   /**
    * Register the built-in drivers against the queue configuration.
    *
    * @param Config $Config Queue configuration.
    */
   public function __construct (Config $Config)
   {
      parent::__construct('Queue driver');

      // * Config
      $this->Config = $Config;

      // * Data
      // ! Built-in drivers
      $this->map = [
         'file' => File::class,
         'redis' => Redis::class,
      ];
   }

   /**
    * Register a driver class by driver name.
    *
    * @param string $name Driver name.
    * @param class-string<Driver> $driver Driver class to instantiate.
    */
   public function register (string $name, string $driver): self
   {
      $this->map[$name] = $driver;

      return $this;
   }

   /**
    * Fetch a driver by name (defaults to the configured driver), building it once.
    *
    * @param string $name Driver name; empty uses the configured driver.
    */
   public function fetch (string $name = ''): Driver
   {
      if ($name === '') {
         $name = $this->Config->driver;
      }

      // ?: Already instantiated
      if (isset($this->items[$name]) === true) {
         return $this->load($name);
      }

      // ? Unknown driver
      if (isset($this->map[$name]) === false) {
         throw new InvalidArgumentException("Queue driver is not registered: {$name}.");
      }

      // @ Lazily instantiate and cache
      $class = $this->map[$name];
      $Driver = new $class($this->Config);
      $this->store($name, $Driver);

      return $Driver;
   }
}
