<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Model;


use Attribute;
use InvalidArgumentException;


/**
 * Persistent entity table mapping.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Table
{
   // * Config
   public private(set) string $name;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (string $name)
   {
      if ($name === '') {
         throw new InvalidArgumentException('ORM table name cannot be empty.');
      }

      // * Config
      $this->name = $name;
   }
}
