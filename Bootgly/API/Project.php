<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API; // namespace Bootgly\API\Projects


class Project
{
   // * Config
   // @ path
   public string $vendor;       // Bootgly/
   public string $container;    // WPI/
   public string $package;      // App/
   // ---
   public string $type;         // Quasar
   public string $public;       // dist/
   public string $version;      // spa/

   // * Data
   protected string $name;
   protected array $paths;

   // * Meta
   private ? int $index;


   public function __construct ()
   {
      // * Config
      // @ path
      // TODO template path
      $this->vendor = '';
      $this->container = '';
      $this->package = '';
      // ---
      $this->type = '';
      $this->public = '';
      $this->version = '';

      // * Data
      $this->name = '';

      // * Meta
      $this->index = null;
   }

   public function __get (string $name)
   {
      switch ($name) {
         case 'path':
            $paths = $this->paths ?? [];
            foreach ($paths as $path) {
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

      if ($path) {
         $path = trim($path, '/');
         $path = Projects::CONSUMER_DIR . $path . '/';

         $this->paths[] = $path;

         // @ Add Project to Projects
         #$this->index = Projects::add($this);
      }

      return $path;
   }
   public function get (int $path = 0) : string
   {
      return $this->paths[$path] ?? '';
   }
   // ! ID
   public function name (string $name) : bool
   {
      if ($this->index === null) {
         return false;
      }

      $indexed = Projects::index($name);
      if ($indexed === false) {
         return false;
      }

      $this->name = $name;

      return true;
   }
}
