<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Exception;

use Bootgly\CLI;
use Bootgly\WPI;


class Bootgly
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   private static bool $booted = false;


   /**
    * @throws Exception
    */
   public function autoboot (): void
   {
      // ?
      if (self::$booted)
         throw new Exception("Bootgly has already been booted.");

      // * Metadata
      self::$booted = true;

      // !
      /** @var CLI $CLI */
      /** @var WPI $WPI */
      [
         $CLI,
         $WPI
      ] = require(__DIR__ . '/Bootgly/autoload.php');

      // @
      $CLI->autoboot();
      $WPI->autoboot();
   }
}
