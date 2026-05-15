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


use Stringable;

use Bootgly\ADI\Databases\SQL\Builder\Expression;


/**
 * Column default change collected by an ALTER TABLE blueprint.
 */
class DefaultValue
{
   // * Config
   public private(set) string $name;
   public private(set) bool $dropped;
   public private(set) null|bool|float|int|string|Stringable|Expression $value;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (
      string $name,
      null|bool|float|int|string|Stringable|Expression $value = null,
      bool $dropped = false
   )
   {
      // * Config
      $this->name = $name;
      $this->value = $value;
      $this->dropped = $dropped;
   }
}