<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use InvalidArgumentException;

use Bootgly\ABI\Data\Registry;
use Bootgly\ABI\Resources\Storage\Driver;
use Bootgly\ABI\Resources\Storage\Drivers\Local;
use Bootgly\ABI\Resources\Storage\Drivers\Memory;
use Bootgly\ABI\Resources\Storage\Drivers\S3;


/**
 * Registry of available storage drivers (by driver type).
 *
 * Maps a driver type name to its class. The Storage facade builds one driver
 * instance per configured disk, so the registry only resolves and validates
 * driver classes — instance caching lives in the facade, keyed by disk name.
 *
 * @extends Registry<Driver>
 */
class Drivers extends Registry
{
   // * Data
   /** @var array<string,class-string<Driver>> */
   protected array $map;


   /**
    * Register the built-in drivers (local, memory).
    */
   public function __construct ()
   {
      parent::__construct('Storage driver');

      // * Data
      // ! Built-in drivers
      $this->map = [
         'local' => Local::class,
         'memory' => Memory::class,
         's3' => S3::class,
      ];
   }

   /**
    * Register a driver class by driver type name.
    *
    * @param class-string<Driver> $driver
    */
   public function register (string $type, string $driver): self
   {
      $this->map[$type] = $driver;

      return $this;
   }

   /**
    * Build a new driver instance of the given type bound to a disk root.
    *
    * @param array<string,mixed> $options
    */
   public function build (string $type, string $root, array $options = []): Driver
   {
      // ? Unknown driver
      if (isset($this->map[$type]) === false) {
         throw new InvalidArgumentException("Storage driver is not registered: {$type}.");
      }

      // @
      $class = $this->map[$type];

      // :
      return new $class($root, $options);
   }
}
