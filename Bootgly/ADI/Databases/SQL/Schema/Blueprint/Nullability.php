<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema\Blueprint;


/**
 * Column nullability change collected by an ALTER TABLE blueprint.
 */
class Nullability
{
   // * Config
   public private(set) string $name;
   public private(set) bool $nullable;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (string $name, bool $nullable)
   {
      // * Config
      $this->name = $name;
      $this->nullable = $nullable;
   }
}