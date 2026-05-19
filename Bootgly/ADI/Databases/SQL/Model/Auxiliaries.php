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


use function in_array;

use Bootgly\ADI\Databases\SQL\Model\Auxiliaries\Relations;


/**
 * ORM model auxiliary enum registry.
 */
final class Auxiliaries
{
   // * Config
   public const array ENUMS = [
      Relations::class,
   ];

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Check if one enum class belongs to the ORM model auxiliary registry.
    *
    * @param class-string $class
    */
   public static function check (string $class): bool
   {
      return in_array($class, self::ENUMS, true);
   }
}
