<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use const BOOTGLY_STORAGE_DIR;
use function defined;
use function is_array;
use function is_string;
use function rtrim;
use function sys_get_temp_dir;


/**
 * ABI-native storage configuration.
 *
 * Mirrors the array-driven, constant-defaulted shape used across Bootgly. A
 * storage layer is built from named disks; each disk names a driver type plus
 * its options (e.g. the filesystem root used by the local driver).
 */
class Config
{
   public const string DEFAULT_DISK = 'local';

   // * Config
   /**
    * Name of the disk used when no disk is named.
    */
   public string $default;
   /**
    * Disk definitions keyed by disk name; each is `['driver' => type, ...options]`.
    *
    * @var array<string,array<string,mixed>>
    */
   public array $disks;


   /**
    * Create a configuration value.
    *
    * @param array<string,mixed> $config
    */
   public function __construct (array $config = [])
   {
      $default = $config['default'] ?? self::DEFAULT_DISK;
      /** @var array<string,array<string,mixed>> $disks */
      $disks = isset($config['disks']) && is_array($config['disks'])
         ? $config['disks']
         : [
            'local' => ['driver' => 'local', 'root' => self::locate()],
            'memory' => ['driver' => 'memory'],
         ];

      // * Config
      $this->default = is_string($default) ? $default : self::DEFAULT_DISK;
      $this->disks = $disks;
   }

   /**
    * Locate the default local-disk root directory.
    */
   private static function locate (): string
   {
      // ?: Inside a booted Bootgly working dir
      if (defined('BOOTGLY_STORAGE_DIR') === true) {
         return rtrim(BOOTGLY_STORAGE_DIR, '/');
      }

      // :
      return sys_get_temp_dir() . '/bootgly-storage';
   }
}
