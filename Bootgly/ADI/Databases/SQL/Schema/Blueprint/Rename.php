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
 * Column rename collected by an ALTER TABLE blueprint.
 */
class Rename
{
   // * Config
   public private(set) string $from;
   public private(set) string $to;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (string $from, string $to)
   {
      // * Config
      $this->from = $from;
      $this->to = $to;
   }
}