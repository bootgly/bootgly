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


use InvalidArgumentException;

use Bootgly\ABI\Data\Registry;
use Bootgly\ABI\Resources\Cache\Config;
use Bootgly\ABI\Resources\Cache\Driver;
use Bootgly\ABI\Resources\Cache\Drivers\APCu;
use Bootgly\ABI\Resources\Cache\Drivers\File;
use Bootgly\ABI\Resources\Cache\Drivers\Redis;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;


/**
 * Registry of available cache drivers.
 *
 * Drivers are instantiated lazily on first fetch() so registering a backend
 * never builds or connects one that is not the configured driver.
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


   public function __construct (Config $Config)
   {
      parent::__construct('Cache driver');

      // * Config
      $this->Config = $Config;

      // * Data
      // ! Built-in drivers
      $this->map = [
         'apcu' => APCu::class,
         'file' => File::class,
         'redis' => Redis::class,
         'shared' => Shared::class,
      ];
   }

   /**
    * Register a driver class by driver name.
    *
    * @param class-string<Driver> $driver
    */
   public function register (string $name, string $driver): self
   {
      $this->map[$name] = $driver;

      return $this;
   }

   /**
    * Fetch a driver by name (defaults to the configured driver), building it once.
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
         throw new InvalidArgumentException("Cache driver is not registered: {$name}.");
      }

      // @ Lazily instantiate and cache
      $class = $this->map[$name];
      $Driver = new $class($this->Config);
      $this->store($name, $Driver);

      return $Driver;
   }
}
