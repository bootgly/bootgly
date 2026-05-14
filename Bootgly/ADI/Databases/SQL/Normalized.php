<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use function trim;
use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;


/**
 * Normalized SQL text and parameters.
 */
class Normalized
{
   // * Config
   public private(set) string $sql;
   /** @var array<int|string,mixed> */
   public private(set) array $parameters;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Normalize raw SQL, a builder, or a compiled query.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function __construct (string|Builder|Query $query, array $parameters = [])
   {
      if ($query instanceof Builder) {
         $Query = $query->compile();
         $query = $Query->sql;
         $parameters = $Query->parameters;
      }
      else if ($query instanceof Query) {
         $parameters = $query->parameters;
         $query = $query->sql;
      }

      if (trim($query) === '') {
         throw new InvalidArgumentException('SQL cannot be empty.');
      }

      // * Config
      $this->sql = $query;
      $this->parameters = $parameters;
   }
}
