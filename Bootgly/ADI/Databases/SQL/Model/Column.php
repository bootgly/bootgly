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
 * Persistent entity property mapping.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
   // * Config
   public private(set) null|string $name;
   public private(set) bool $insert;
   public private(set) bool $update;
   public private(set) bool $generated;
   public private(set) bool $nullable;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (
      null|string $name = null,
      bool $insert = true,
      bool $update = true,
      bool $generated = false,
      bool $nullable = false
   )
   {
      if ($name === '') {
         throw new InvalidArgumentException('ORM column name cannot be empty.');
      }

      // * Config
      $this->name = $name;
      $this->insert = $insert;
      $this->update = $update;
      $this->generated = $generated;
      $this->nullable = $nullable;
   }
}
