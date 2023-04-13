<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu\Items;


final class Separator
{
   // * Config
   // ...

   // * Data
   public string $separator;

   // * Meta
   public readonly string $type;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      // ...

      // * Meta
      $this->type = static::class;
   }
}
