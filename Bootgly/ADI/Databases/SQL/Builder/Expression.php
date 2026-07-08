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


use InvalidArgumentException;
use Stringable;


/**
 * Trusted raw SQL expression escape hatch.
 */
class Expression implements Stringable
{
   // * Config
   public private(set) string $SQL;

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
      $this->SQL = $sql;
   }

   /**
    * Render the trusted SQL expression.
    */
   public function __toString (): string
   {
      return $this->SQL;
   }
}
