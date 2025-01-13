<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI;


trait Resources
{
   public const string BOOTSTRAP_FILENAME = '@.php';

   protected string $resource {
      get {
         // ?:
         if ($this->resource ?? false) {
            return $this->resource;
         }

         // @
         $parent = static::class;
         $parts = explode('\\', $parent);

         $class = end($parts);
         $resource = strtolower($class);

         // !:
         return $this->resource = $resource;
      }
   }
}
