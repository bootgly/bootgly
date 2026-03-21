<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Projects;


use Closure;


class Project
{
   // * Config
   // # Explicit
   public string $name;
   public string $description;
   public string $version;
   public string $author;
   // # Implicit
   public string $folder;

   // * Data
   public Closure $boot;

   // * Metadata
   public static null|Project $current = null;


   public function __construct (
      // * Data (required)
      Closure $boot,
      // * Config (optional)
      string $name = '',
      string $folder = '',
      string $description = '',
      string $version = '',
      string $author = '',
   )
   {
      // * Config
      $this->name = $name;
      $this->folder = $folder;
      $this->description = $description;
      $this->version = $version;
      $this->author = $author;

      // * Data
      $this->boot = $boot;
   }

   /**
    * Boot the project.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    */
   public function boot (array $arguments = [], array $options = []): void
   {
      // @
      ($this->boot)($arguments, $options);
   }
}
