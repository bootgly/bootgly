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


class Project
{
   // ! Path
   // Bootgly Project (Author)
   public const BOOTGLY_PROJECTS_DIR = BOOTGLY_BASE . '/projects/';
   // Project (Consumer)
   public const PROJECT_DIR = BOOTGLY_WORKABLES_BASE . '/project/';
   public const PROJECTS_DIR = BOOTGLY_WORKABLES_BASE . '/projects/';

   // * Config
   // ...

   // * Data
   public string $vendor;       // Bootgly/
   public string $container;    // examples/
   public string $package;      // App/
   // ---
   public string $type;         // SPA, PWA, etc.
   public string $public;       // dist/
   public string $version;      // v1/

   // * Meta
   private array $paths;
   private static array $projects = [];
   private int $index;
   private static array $indexes = [];


   public function __construct ()
   {
      // ! Path
      // * Config
      // ...

      // * Data
      // TODO templates
      $this->vendor = '';
      $this->container = '';
      $this->package = '';
      // ---
      $this->type = '';
      $this->public = '';
      $this->version = '';

      // * Meta
      $this->paths = [];
      $this->index = count(self::$projects);
   }

   public function __get (string $name)
   {
      switch ($name) {
         case 'path':
            foreach ($this->paths as $path) {
               if ( is_dir($path) ) {
                  return $path;
               }
            }

            return '';
         default:
            return $this->$name;
      }
   }

   public function __toString () : string
   {
      return $this->path;
   }

   // ! ID
   public function name (string $name) : bool
   {
      if ($name === '') {
         return false;
      }

      self::$indexes[$name] ??= $this->index;

      return true;
   }
   // ! Path
   public function construct (? string $path = null) : string
   {
      if ($path) {
         $path = trim($path, '/');

         $paths = explode('/', $path);

         foreach ($paths as $index => $__path__) {
            match ($index) {
               0 => $this->vendor    = $__path__ . '/',
               1 => $this->container = $__path__ . '/',
               2 => $this->package   = $__path__ . '/',
               3 => $this->type      = $__path__ . '/',
               4 => $this->public    = $__path__ . '/',
               5 => $this->version   = $__path__ . '/'
            };
         }
      } else {
         $path .= $this->vendor;
         $path .= $this->container;
         $path .= $this->package;

         $path .= $this->type;
         $path .= $this->public;
         $path .= $this->version;
      }

      $path = trim($path, '/');
      $path = self::PROJECTS_DIR . $path . '/';

      $this->paths[] = $path;

      return $path;
   }
   public function save () : string
   {
      $paths = count($this->paths);

      $project = '';

      if ($paths > 0) {
         $path = $this->paths[$paths - 1];
         $project = self::$projects[$this->index][] = $path;
      }

      return $project;
   }

   public function get (int $path = 0) : string
   {
      return $this->paths[$path] ?? '';
   }

   public function select (null|string|int $project = null) : string
   {
      if ( is_string($project) ) {
         $project = self::$indexes[$project] ?? null;
      }

      $paths = self::$projects[$project] ?? '';

      return $paths[0] ?? '';
   }
   // DEPRECATED
   public function setPath ()
   {
      // Join paths
      $path = self::PROJECTS_DIR;

      $path .= $this->vendor;
      $path .= $this->container;
      $path .= $this->package;

      $path .= $this->type;

      $path .= $this->public;

      $path .= $this->version;

      // @ Push to paths array
      $this->paths[] = $path;
   }
}
