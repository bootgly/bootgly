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


use function in_array;

use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Aggregates;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Capabilities;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Joins;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Junctions;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Locks;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Matches;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Modes;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Nulls;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;


/**
 * SQL builder auxiliary enum registry.
 */
final class Auxiliaries
{
   // * Config
   public const array ENUMS = [
      Aggregates::class,
      Capabilities::class,
      Joins::class,
      Junctions::class,
      Locks::class,
      Matches::class,
      Modes::class,
      Nulls::class,
      Operators::class,
      Orders::class,
   ];

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Check if one enum class belongs to the SQL builder auxiliary registry.
    *
    * @param class-string $class
    */
   public static function check (string $class): bool
   {
      return in_array($class, self::ENUMS, true);
   }
}
