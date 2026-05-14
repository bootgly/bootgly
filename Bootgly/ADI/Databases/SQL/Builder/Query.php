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


use Stringable;


/**
 * Compiled SQL query and ordered parameters.
 */
class Query implements Stringable
{
   // * Config
   public private(set) string $sql;
   /** @var array<int,mixed> */
   public private(set) array $parameters;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param array<int,mixed> $parameters
    */
   public function __construct (string $sql, array $parameters = [])
   {
      // * Config
      $this->sql = $sql;
      $this->parameters = $parameters;
   }

   /**
    * Render the compiled SQL string.
    */
   public function __toString (): string
   {
      return $this->sql;
   }
}
