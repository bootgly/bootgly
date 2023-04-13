<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu\Items\collections;


final class Header
{
   // * Config
   // ...

   // * Data
   public string $header;

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
