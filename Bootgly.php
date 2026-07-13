<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


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
    * Autoboot Bootgly interfaces.
    * 
    * @return void
    *
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
      ] = require(__DIR__ . '/Bootgly/autoboot.php');

      // @
      $CLI->autoboot();
      $WPI->autoboot();
   }
}
