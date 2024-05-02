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
         'filenames' => [
            'bootstrap' => [
               'bootgly',
               '/usr/local/bin/bootgly'
            ]
         ]
      ];
      // * Metadata
      $this->path = null;
      $this->filename = null;


      // @ Bootstrap scripts
      $resource_dirs = [
         self::ROOT_DIR
      ];
      if (self::ROOT_DIR !== self::WORKING_DIR) {
         $resource_dirs[] = self::WORKING_DIR;
      }
      foreach ($resource_dirs as $dir) {
         $bootstrap = (include $dir . '@.php');
         if ($bootstrap !== false) {
            $this->includes['filenames'] += $bootstrap['scripts'];

            foreach ($this->includes['filenames'] as $group => $filenames) {
               foreach ($filenames as $filename) {
                  switch ($group) {
                     case 'bootstrap':
                        break;
                     case 'built-in':
                        $filename = self::ROOT_DIR . $filename;
                        break;
                     case 'imported':
                        $filename = BOOTGLY_WORKING_DIR . $filename;
                        break;
                     case 'user':
                        $filename = self::WORKING_DIR . $filename;
                        break;
                     default:
                        $filename = null;
                        break;
                  }

                  $this->scripts[] = $filename;
               }
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
      // ?:
      if ($this->path === null || $this->filename === null) {
         return -2;
      }

      // !
      $this->filename = Path::normalize($this->filename);

      // @
      // Global scripts (absolute paths)
      if (\in_array($this->filename, $this->scripts) !== false) {
         return 1;
      }
      // Local scripts (relative to scripts/ working directory)
      if (\in_array($this->path . '/' . $this->filename, $this->scripts) !== false) {
         return 0;
      }

      return -1;
   }

   public static function execute (string $script)
   {
      $basedirs = [
         self::WORKING_DIR,
         self::ROOT_DIR
      ];

      $found = false;
      foreach ($basedirs as $basedir) {
         $path = $basedir . Path::normalize($script);
         $Script = new File($path);

         if ($Script->exists) {
            require $Script->file;
            // TODO register commands, etc.
            $found = true;
            break;
         }
      }

      if ($found === false) {
         throw new \Exception("Script not found: `$script`");
      }
   }
}
