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


use BackedEnum;
use Closure;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder as SQLBuilder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Junctions;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Matches;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;


/**
 * Fluent SQL predicate conjunction proxy.
 */
final class Conjunction
{
   // * Config
   public private(set) SQLBuilder $Builder;
   public private(set) Junctions $Junction;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Create a conjunction proxy for the next predicate.
    */
   public function __construct (SQLBuilder $Builder, Junctions $Junction)
   {
      $this->Builder = $Builder;
      $this->Junction = $Junction;
   }

   /**
    * Filter rows with one parameterized SQL predicate.
    */
   public function filter (BackedEnum|Stringable $Column, Operators $Operator, mixed $value = null): SQLBuilder
   {
      return $this->Builder->filter($Column, $Operator, $value, $this->Junction);
   }

   /**
    * Nest one grouped filter scope.
    */
   public function nest (Closure $Group): SQLBuilder
   {
      return $this->Builder->nest($Group, $this->Junction);
   }

   /**
    * Match text with LIKE, ILIKE or PostgreSQL full-text predicates.
    */
   public function match (BackedEnum|Stringable $Column, mixed $value, Matches $Match = Matches::Like): SQLBuilder
   {
      return $this->Builder->match($Column, $value, $Match, $this->Junction);
   }

   /**
    * Filter grouped rows with a parameterized SQL predicate.
    */
   public function having (BackedEnum|Stringable $Column, Operators $Operator, mixed $value = null): SQLBuilder
   {
      return $this->Builder->having($Column, $Operator, $value, $this->Junction);
   }
}
