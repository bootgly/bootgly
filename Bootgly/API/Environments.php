<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API;


enum Environments
{
   case Development;
   case Production;
   case Staging;
   case Test;


   /**
    * Map an environment name (e.g. the BOOTGLY_ENVIRONMENT constant) to its case.
    * Unrecognized names fail safe to Production.
    */
   public static function fetch (string $name): self
   {
      // :
      return match ($name) {
         'development' => self::Development,
         'staging' => self::Staging,
         'test' => self::Test,
         default => self::Production
      };
   }
}
