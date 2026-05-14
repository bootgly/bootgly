<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Builder;


use InvalidArgumentException;
use Stringable;


/**
 * Trusted raw SQL expression escape hatch.
 */
class Expression implements Stringable
{
   // * Config
   public private(set) string $sql;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (string $sql)
   {
      if ($sql === '') {
         throw new InvalidArgumentException('SQL expression cannot be empty.');
      }

      // * Config
      $this->sql = $sql;
   }

   /**
    * Render the trusted SQL expression.
    */
   public function __toString (): string
   {
      return $this->sql;
   }
}
