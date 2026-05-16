<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema;


use function array_pop;
use function explode;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function str_replace;
use BackedEnum;
use InvalidArgumentException;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder\Dialect as QueryDialect;
use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Capabilities;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\References;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Index;


/**
 * Base SQL DDL dialect compiler.
 */
abstract class Dialect
{
   // * Config
   public QueryDialect $Dialect;
   public bool $transactions = false;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (QueryDialect $Dialect)
   {
      // * Config
      $this->Dialect = $Dialect;
   }

   /**
    * Check if this schema dialect supports one feature capability.
    */
   public function check (Capabilities $Capability): bool
   {
      return true;
   }

   /**
    * Guard one dialect feature capability before compiling syntax that needs it.
    */
   protected function guard (Capabilities $Capability): void
   {
      if ($this->check($Capability)) {
         return;
      }

      $this->fail($Capability);
   }

   /**
    * Throw the uniform schema capability exception.
    */
   protected function fail (Capabilities $Capability): never
   {
      $parts = explode('\\', static::class);
      $dialect = array_pop($parts);

      throw new InvalidArgumentException("{$dialect} schema dialect lacks capability: {$Capability->name}.");
   }

   /**
    * Compile CREATE TABLE.
    */
   abstract public function create (Blueprint $Blueprint, bool $exists = false): Query;

   /**
    * Compile ALTER TABLE.
    */
   abstract public function alter (Blueprint $Blueprint): Query;

   /**
    * Compile DROP TABLE.
    */
   abstract public function drop (BackedEnum|Stringable|string $Table, bool $exists = true): Query;

   /**
    * Compile RENAME TABLE.
    */
   abstract public function rename (BackedEnum|Stringable|string $From, BackedEnum|Stringable|string $To): Query;

   /**
    * Compile CREATE INDEX.
    */
   abstract public function index (Index $Index): Query;

   /**
    * Compile advisory lock acquisition when supported by the dialect.
    */
   public function lock (int $key): null|Query
   {
      return null;
   }

   /**
    * Compile advisory lock release when supported by the dialect.
    */
   public function unlock (int $key): null|Query
   {
      return null;
   }

   /**
    * Compile DROP INDEX.
    */
   abstract public function unindex (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Name,
      bool $exists = true
   ): Query;

   /**
    * Compile DROP CONSTRAINT.
    */
   abstract public function unconstrain (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Name,
      bool $exists = true
   ): Query;

   /**
    * Quote one identifier.
    */
   protected function quote (BackedEnum|Stringable|string $Identifier): string
   {
      return $this->Dialect->quote($this->normalize($Identifier));
   }

   /**
    * Render one safe literal or trusted expression.
    */
   protected function escape (mixed $value): string
   {
      if ($value instanceof Expression) {
         return $value->sql;
      }

      if ($value === null) {
         return 'NULL';
      }

      if (is_bool($value)) {
         return $value ? 'TRUE' : 'FALSE';
      }

      if (is_int($value) || is_float($value)) {
         return (string) $value;
      }

      if (is_string($value)) {
         return "'" . str_replace("'", "''", $value) . "'";
      }

      if ($value instanceof Stringable) {
         return "'" . str_replace("'", "''", (string) $value) . "'";
      }

      throw new InvalidArgumentException('Schema default value must be scalar, Stringable or Expression.');
   }

   /**
    * Render one foreign key referential action.
    */
   protected function refer (References $Reference): string
   {
      return match ($Reference) {
         References::Cascade => 'CASCADE',
         References::NoAction => 'NO ACTION',
         References::Restrict => 'RESTRICT',
         References::SetDefault => 'SET DEFAULT',
         References::SetNull => 'SET NULL',
      };
   }

   /**
    * Normalize one identifier input.
    */
   protected function normalize (BackedEnum|Stringable|string $Identifier): string
   {
      if ($Identifier instanceof BackedEnum) {
         return (string) $Identifier->value;
      }

      return (string) $Identifier;
   }
}
