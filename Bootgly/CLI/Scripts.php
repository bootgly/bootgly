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
   public const SCRIPTS_DIR = BOOTGLY_BASE . '/scripts/';


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
