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
use Bootgly\ABI\IO\FS\File;


class Scripts
{
   public const ROOT_BASE = BOOTGLY_ROOT_BASE . '/scripts';
   public const WORKING_BASE = BOOTGLY_WORKING_BASE . '/scripts';

   public const ROOT_DIR = BOOTGLY_ROOT_BASE . '/scripts/';
   public const WORKING_DIR = BOOTGLY_WORKING_BASE . '/scripts/';

   // * Config
   // ...

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
         'bootstraps' => [
            'bootgly',
            '/usr/local/bin/bootgly'
         ],
         'filenames' => []
      ];
      // * Metadata
      $this->path = null;
      $this->filename = null;


      // @
      $resource_dirs = [
         self::ROOT_DIR
      ];
      if (self::ROOT_DIR !== self::WORKING_DIR) {
         $resource_dirs[] = self::WORKING_DIR;
      }
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

   public function validate () : int
   {
      $this->path ??= @$_SERVER['PWD'];
      $this->filename ??= @$_SERVER['SCRIPT_FILENAME'];
      // ?
      if ($this->path === null || $this->filename === null) {
         return -2;
      }

      // !
      $this->filename = Path::normalize($this->filename);
      $this->filename = Path::relativize($this->filename, 'scripts/');

      // @
      $this->validations = [];
      $this->validations['paths'] = \array_search($this->path, $this->includes['paths']);
      $this->validations['bootstraps'] = \array_search($this->filename, $this->includes['bootstraps']);
      $this->validations['filenames'] = \array_search($this->filename, $this->includes['filenames']);

      if ($this->validations['filenames'] !== false) {
         return 0;
      }
      if ($this->validations['paths'] === false && $this->validations['bootstraps'] === false) {
         return 0;
      }
      if ($this->validations['paths'] !== false && $this->validations['bootstraps'] === false && $this->validations['filenames'] === false) {
         return -1;
      }

      return 1;
   }

   public static function execute (string $script)
   {
      $basedirs = [
         self::WORKING_DIR,
         self::ROOT_DIR
      ];

      foreach ($basedirs as $basedir) {
         $Script = new File(
            $basedir . Path::normalize($script)
         );

         if ($Script->exists) {
            require $Script->file;
            // TODO register commands, etc.
            break;
         }
      }

      throw new \Exception("Script not found: `$script`");
   }
}
