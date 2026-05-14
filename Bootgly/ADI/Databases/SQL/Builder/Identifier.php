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


use function is_string;
use BackedEnum;
use InvalidArgumentException;
use Stringable;


/**
 * SQL identifier wrapper for dynamic tables and columns.
 *
 * Use this as the explicit escape hatch when an identifier cannot be modeled as
 * a string-backed enum.
 */
class Identifier implements Stringable
{
   // * Config
   public private(set) string $name;

   // * Data
   // ...

   // * Metadata
   private static null|Dialect $Dialect = null;


   public function __construct (string $name)
   {
      if ($name === '') {
         throw new InvalidArgumentException('SQL identifier cannot be empty.');
      }

      // * Config
      $this->name = $name;
   }

   /**
    * Render the unquoted identifier name.
    */
   public function __toString (): string
   {
      return $this->name;
   }

   /**
    * Quote one enum or object identifier for SQL.
    */
   public static function quote (BackedEnum|Stringable $Identifier, null|Dialect $Dialect = null): string
   {
      if ($Dialect === null) {
         if (self::$Dialect === null) {
            $Dialects = new Dialects;
            self::$Dialect = $Dialects->fetch();
         }

         $Dialect = self::$Dialect;
      }

      $name = self::normalize($Identifier);

      return $Dialect->quote($name);
   }

   /**
    * Configure the default dialect used by direct quote() calls.
    */
   public static function configure (null|Dialect $Dialect = null): void
   {
      self::$Dialect = $Dialect;
   }

   /**
    * Normalize one enum or object identifier to its string name.
    */
   private static function normalize (BackedEnum|Stringable $Identifier): string
   {
      if ($Identifier instanceof BackedEnum) {
         $value = $Identifier->value;

         if (is_string($value) === false || $value === '') {
            throw new InvalidArgumentException('SQL enum identifiers must be non-empty strings.');
         }

         return $value;
      }

      $value = (string) $Identifier;

      if ($value === '') {
         throw new InvalidArgumentException('SQL object identifiers must render to non-empty strings.');
      }

      return $value;
   }
}
