<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

class Bootgly
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   private static bool $booted = false;


   public function autoboot ()
   {
      // ?
      if (self::$booted)
         throw new \Exception("Bootgly has already been booted.");

      // * Metadata
      self::$booted = true;

      // !
      [
         $CLI,
         $WPI
      ] = require(__DIR__ . '/Bootgly/autoload.php');

      // @
      $CLI->autoboot();
      $WPI->autoboot();
   }
}
