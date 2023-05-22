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
   public bool $cache;

   // * Data
   public string $vendor;       // @bootgly/
   public string $container;    // examples/
   public string $package;      // app/
   // ---
   public string $type;         // spa, pwa, etc.
   public string $public;       // dist/
   public string $version;      // v1/

   // * Meta
   private static array $projects;
   private static string $selected;
   #private string $path;
   private array $paths;


   public function __construct (? string $path = null)
   {
      // ! Path
      // * Config
      $this->cache = true;

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
      self::$projects = [];
      self::$selected = '';
      $this->paths = [];

      // @
      if ($path) {
         $path = trim($path, '/');

         $paths = explode('/', $path);

         foreach ($paths as $index => $path) {
            match ($index) {
               0 => $this->vendor    = $path . '/',
               1 => $this->container = $path . '/',
               2 => $this->package   = $path . '/',
               3 => $this->type      = $path . '/',
               4 => $this->public    = $path . '/',
               5 => $this->version   = $path . '/'
            };
         }

         #$this->path = PROJECTS_DIR . $path;
      }
   }

   public function __get ($name)
   {
      switch ($name) {
         case 'path':
            if ($this->cache && isset($this->paths[1]) && !is_dir($this->paths[0])) {
               return $this->paths[1];
            }

            return $this->paths[0] ?? '';
         default:
            return $this->$name;
      }
   }

   public function __toString () : string
   {
      return $this->path;
   }

   // ! Path
   public function construct () : string
   {
      $path = self::PROJECTS_DIR;

      // @ 1 - Construct path
      $path .= $this->vendor ?? $this->name;
      $path .= $this->container;
      $path .= $this->package;

      $path .= $this->type;

      $path .= $this->public;

      $path .= $this->version;

      // @ Fix constructed path
      $path = trim($path, '/');
      $path .= '/';

      // @ Push constructed path to project paths
      $this->paths[] = $path;

      return $path;
   }
   public function save (? string $backup = null) : bool
   {
      $key = $this->path;
      $path = $backup ?? $key;

      $project = self::$projects[$key] ?? null;

      if ($project === null) {
         $path = $this->construct();

         // @ Save path to projects list
         self::$projects[$key][] = $path;

         // @ Set path to selected
         self::$selected = $key;

         return true;
      }

      return false;
   }

   public function get (string $version = '@') : string
   {
      $project = self::$projects[self::$selected];

      // @ Get project path version
      $path = $project[$version] ?? '';

      return $path;
   }

   public function select (string $name) : bool
   {
      $project = self::$projects[$name] ?? null;

      if ($project === null) {
         return false;
      }

      self::$selected = $name;

      return true;
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
