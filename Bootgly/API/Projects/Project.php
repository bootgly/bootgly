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


use function basename;
use function debug_backtrace;
use function define;
use function defined;
use function dirname;
use Closure;
use Error;

use Bootgly\API\Projects;


/**
 * Represents a Bootgly project.
 *
 * The constructor captures metadata and derives path/folder from the caller.
 * Registration (defining the BOOTGLY_PROJECT constant and adding to Projects)
 * happens only when boot() is called.
 *
 * Only one Project can be booted per process. Attempting to boot a second
 * Project throws a fatal Error.
 */
class Project
{
   // * Config
   // # Explicit
   public string $name;
   public string $description;
   public string $version;
   public string $author;
   // # Implicit
   public readonly string $path;
   public string $folder;

   // * Data
   public Closure $boot;


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
      // # Implicit
      /** @var string $callerFile */
      $callerFile = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '';
      $this->path = dirname($callerFile) . '/';
      $this->folder = $folder !== '' ? $folder : basename(dirname($callerFile));
      // # Explicit
      $this->name = $name;
      $this->description = $description;
      $this->version = $version;
      $this->author = $author;

      // * Data
      $this->boot = $boot;
   }

   /**
    * Boot the project.
    *
    * Defines BOOTGLY_PROJECT constant and registers in Projects on first call.
    * A second boot in the same process throws a fatal Error.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    */
   public function boot (array $arguments = [], array $options = []): void
   {
      // @ Register
      if ( defined('BOOTGLY_PROJECT') ) {
         throw new Error(
            'Only one Project can be booted per process. '
            . 'BOOTGLY_PROJECT is already defined.'
         );
      }

      define('BOOTGLY_PROJECT', $this);
      Projects::add($this);

      // @
      ($this->boot)($arguments, $options);
   }
}
