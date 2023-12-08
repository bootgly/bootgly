<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use Bootgly\ABI\Data\__String\Path;


class Scripts
{
   public const ROOT_BASE = BOOTGLY_ROOT_BASE . '/scripts';
   public const WORKING_BASE = BOOTGLY_WORKING_BASE . '/scripts';

   public const ROOT_DIR = BOOTGLY_ROOT_BASE . '/scripts/';
   public const WORKING_DIR = BOOTGLY_WORKING_BASE . '/scripts/';

   // * Config

   // * Data
   protected array $includes;
   protected array $scripts;
   // * Metadata
   // @ Validating
   private ? string $path;
   private ? string $filename;
   private array $validations;


   public function __construct ()
   {
      // * Config
      // ...
      // * Data
      $this->includes = [
         'paths' => [
            BOOTGLY_ROOT_BASE,
            BOOTGLY_WORKING_BASE,

            BOOTGLY_ROOT_DIR,
            BOOTGLY_WORKING_DIR,

            self::WORKING_BASE,
            self::WORKING_DIR,
         ],
         'filenames' => [
            'bootgly',
            '/usr/local/bin/bootgly',
         ]
      ];
      // * Metadata
      $this->path = null;
      $this->filename = null;


      // @
      $resource_dirs = [
         self::ROOT_DIR,
         self::WORKING_DIR,
      ];
      foreach ($resource_dirs as $dir) {
         $bootstrap = (include $dir . '@.php');
         if ($bootstrap !== false) {
            $this->scripts = $bootstrap['scripts'];

            foreach ($this->scripts as $filename) {
               $this->includes['filenames'][] = $filename;
            }
         }
      }
   }
   public function __get ($name)
   {
      return match ($name) {
         'path' => $this->path,
         'filename' => $this->filename,
         'validations' => $this->validations,
         default => null
      };
   }

   public function validate () : bool
   {
      $this->path ??= @$_SERVER['PWD'];
      $this->filename ??= @$_SERVER['SCRIPT_FILENAME'];
      // ?
      if ($this->path === null || $this->filename === null) {
         return false;
      }

      // !
      $this->filename = Path::normalize($this->filename);
      $this->filename = Path::relativize($this->filename, 'scripts/');

      // @
      $this->validations = [];
      $this->validations[] = array_search($this->path, $this->includes['paths']);
      $this->validations[] = array_search($this->filename, $this->includes['filenames']);

      if ($this->validations[1] === false) {
         return false;
      }
      if ($this->validations[0] === false && $this->validations[1] === false) {
         return false;
      }

      return true;
   }

   public static function execute (string $path)
   {
      $path = Path::normalize($path);
      $location = self::ROOT_DIR . $path;

      if ( file_exists($location) ) {
         include $location;
         // TODO register commands, etc.
      } else {
         throw new \Exception("Script not found: $path");
      }
   }
}
