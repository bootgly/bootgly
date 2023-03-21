<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use const Bootgly\HOME_DIR;


class Project
{
   public const PROJECT_DIR = HOME_DIR . 'projects/';

   // * Config
   public bool $cache;

   // * Data
   public string $vendor;       // @bootgly/
   public string $container;    // examples/
   public string $package;      // app/

   public string $type;         // spa, pwa, etc.

   public string $public;       // dist/

   public string $version;      // v1/

   // * Meta
   public static string $path0;
   private array $paths;


   public function __construct ()
   {
      // * Config
      $this->cache = true;

      // * Data
      $this->vendor = '';
      $this->container = '';
      $this->package = '';

      $this->type = '';

      $this->public = '';

      $this->version = '';

      // * Meta
      $this->paths = [];
   }

   public function __get ($name)
   {
      switch ($name) {
         case 'path':
            if ($this->cache && isset($this->paths[1]) && !is_dir($this->paths[0])) {
               return $this->paths[1];
            }

            return $this->paths[0];
         default:
            return $this->$name;
      }
   }
   public function __toString () : string
   {
      return $this->path;
   }

   public function setPath ()
   {
      // Join paths
      $path = self::PROJECT_DIR;

      $path .= $this->vendor;
      $path .= $this->container;
      $path .= $this->package;

      $path .= $this->type;

      $path .= $this->public;

      $path .= $this->version;

      // Set static paths[0] (Main path)
      self::$path0 = $path;

      // Push to paths array
      $this->paths[] = $path;
   }
}
