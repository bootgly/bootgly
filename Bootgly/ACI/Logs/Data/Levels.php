<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Data;


use function strtolower;
use function strtoupper;


enum Levels: int
{
   // RFC5424 severities — lower backing value = more severe.
   case Emergency = 1;
   case Alert     = 2;
   case Critical  = 3;
   case Error     = 4;
   case Warning   = 5;
   case Notice    = 6;
   case Info      = 7;
   case Debug     = 8;


   /**
    * Resolve a level from its case name (case-insensitive).
    *
    * @param string $name Level name (e.g. `error`, `INFO`).
    * @return null|self The matching level, or null when the name is unknown.
    */
   public static function fetch (string $name): null|self
   {
      $name = strtolower($name);

      foreach (self::cases() as $Level) {
         if (strtolower($Level->name) === $name) {
            return $Level;
         }
      }

      return null;
   }

   /**
    * Render this level as its uppercase severity string (e.g. `ERROR`, `INFO`).
    */
   public function render (): string
   {
      return strtoupper($this->name);
   }
}
