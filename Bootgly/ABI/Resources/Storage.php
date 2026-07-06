<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources;


use function is_string;
use InvalidArgumentException;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Resources\Storage\Config;
use Bootgly\ABI\Resources\Storage\Driver;
use Bootgly\ABI\Resources\Storage\Drivers;
use Bootgly\ABI\Resources\Storage\Events;


/**
 * Storage facade.
 *
 * Single entry point for the storage layer: resolves named disks to driver
 * instances and proxies file operations to the default disk. Each disk is
 * built once, lazily, from its configuration. The default `local` disk is
 * rooted at the storage directory; remote disks (S3, ...) plug in through the
 * driver registry's register().
 */
class Storage
{
   // * Config
   public Config $Config;

   // * Data
   public Drivers $Drivers;
   /** @var array<string,Driver> */
   protected array $Disks = [];


   /**
    * Build a storage layer from a config array or a prepared Config value.
    *
    * @param array<string,mixed>|Config $config
    */
   public function __construct (array|Config $config = [])
   {
      // * Config
      $this->Config = $config instanceof Config
         ? $config
         : new Config($config);

      // * Data
      $this->Drivers = new Drivers();
   }

   /**
    * Open a disk by name, building its driver once on first access.
    */
   public function open (string $name = ''): Driver
   {
      // ? Default disk
      if ($name === '') {
         $name = $this->Config->default;
      }

      // ?: Already built
      if (isset($this->Disks[$name]) === true) {
         return $this->Disks[$name];
      }

      // ? Unknown disk
      $disk = $this->Config->disks[$name] ?? null;
      if ($disk === null) {
         throw new InvalidArgumentException("Storage disk is not configured: {$name}.");
      }

      // @ Build from the disk definition
      $type = isset($disk['driver']) && is_string($disk['driver']) ? $disk['driver'] : 'local';
      $root = isset($disk['root']) && is_string($disk['root']) ? $disk['root'] : '';
      $options = $disk;
      unset($options['driver'], $options['root']);

      $Driver = $this->Drivers->build($type, $root, $options);
      $this->Disks[$name] = $Driver;

      // :
      return $Driver;
   }

   /**
    * Upload to a path on the default disk from a readable stream.
    *
    * @param resource $source
    * @param array<string,mixed> $options Driver-specific write options (e.g. S3 `type`, `meta`).
    */
   public function write (string $path, $source, array $options = []): bool
   {
      $written = $this->open()->write($path, $source, $options);

      // @ Events — guarded so a no-listener write stays zero-allocation
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Written) && $Emitter->emit(Events::Written, $path, $written);

      // :
      return $written;
   }

   /**
    * Download a path on the default disk into a writable stream.
    *
    * @param resource $sink
    */
   public function read (string $path, $sink): bool
   {
      $read = $this->open()->read($path, $sink);

      // @ Events — guarded so a no-listener read stays zero-allocation
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Read) && $Emitter->emit(Events::Read, $path, $read);

      // :
      return $read;
   }

   /**
    * Delete a file on the default disk.
    */
   public function delete (string $path): bool
   {
      $deleted = $this->open()->delete($path);

      // @ Events — guarded so a no-listener delete stays zero-allocation
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Deleted) && $Emitter->emit(Events::Deleted, $path, $deleted);

      // :
      return $deleted;
   }

   /**
    * Whether a file or directory exists on the default disk.
    */
   public function check (string $path): bool
   {
      return $this->open()->check($path);
   }

   /**
    * List file paths under a directory on the default disk.
    *
    * @return array<int,string>
    */
   public function list (string $path = '', bool $recursive = false): array
   {
      return $this->open()->list($path, $recursive);
   }

   /**
    * Copy a file on the default disk.
    */
   public function copy (string $from, string $to): bool
   {
      return $this->open()->copy($from, $to);
   }

   /**
    * Move a file on the default disk.
    */
   public function move (string $from, string $to): bool
   {
      return $this->open()->move($from, $to);
   }

   /**
    * File size in bytes on the default disk.
    */
   public function measure (string $path): int|false
   {
      return $this->open()->measure($path);
   }

   /**
    * File metadata (size + modified time) on the default disk.
    *
    * @return array{size:int,modified:int}|false
    */
   public function inspect (string $path): array|false
   {
      return $this->open()->inspect($path);
   }

   /**
    * Create a directory on the default disk.
    */
   public function make (string $path): bool
   {
      return $this->open()->make($path);
   }

   /**
    * Remove every entry under a directory on the default disk.
    */
   public function clear (string $path = ''): bool
   {
      return $this->open()->clear($path);
   }
}
