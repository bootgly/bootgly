<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\API\Projects;


class Bootgly
{
   public const BOOT_FILE = 'Bootgly.boot.php';

   // * Config
   // ...

   // * Data
   // ...

   // * Meta
   private static bool $booted = false;


   public function __construct ()
   {
      if (self::$booted)
         throw new \Exception("Bootgly class can only be instantiated once.");

      // @ Boot
      // Consumer
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         // Multi projects
         self::$booted = (@include Projects::CONSUMER_DIR . self::BOOT_FILE);
      }
      // Author
      if (self::$booted === false) {
         require(Projects::AUTHOR_DIR . self::BOOT_FILE);
      }

      self::$booted = true;
   }
}
