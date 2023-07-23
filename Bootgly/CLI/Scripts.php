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


use Exception;


class Scripts
{
   public const SCRIPTS_DIR = BOOTGLY_ROOT_BASE . '/scripts/';

   // * Config
   protected array $includes;
   // * Data
   // ...
   // * Meta
   // ...


   public function __construct ()
   {
      // * Config
      $this->includes = [
         'directories' => [
            BOOTGLY_ROOT_DIR,
            BOOTGLY_WORKING_DIR,
         ],
         'filenames' => [
            'bootgly',
            './bootgly', // TODO normalize path
            '/usr/local/bin/bootgly',
         ]
      ];
      // * Data
      // ...
      // * Meta
      // ...
   }
   public function validate () : bool
   {
      $workdir = @$_SERVER['PWD'];
      $script  = @$_SERVER['SCRIPT_FILENAME'];

      if ($workdir === null || $script === null) {
         return false;
      }

      $matches = [];
      $matches[0] = array_search($workdir, $this->includes['directories']);
      $matches[1] = array_search($script, $this->includes['filenames']);

      if ($matches[0] === false && $matches[1] === false) {
         return false;
      }

      return true;
   }

   public function execute (string $path)
   {
      // TODO normalize path
      $location = self::SCRIPTS_DIR . $path;

      if ( file_exists($location) ) {
         include $location;
      } else {
         throw new Exception("Script not found: $path");
      }
   }
}
