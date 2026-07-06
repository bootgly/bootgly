<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
   public private(set) bool $reading;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param array<int,mixed> $parameters
    */
   public function __construct (string $sql, array $parameters = [], bool $reading = false)
   {
      // * Config
      $this->sql = $sql;
      $this->parameters = $parameters;
      $this->reading = $reading;
   }

   /**
    * Render the compiled SQL string.
    */
   public function __toString (): string
   {
      return $this->sql;
   }
}
