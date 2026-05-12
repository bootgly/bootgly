<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI;


use InvalidArgumentException;

use Bootgly\ADI\Database;
use Bootgly\ADI\Databases\SQL;


/**
 * Registry and factory for database paradigms.
 */
class Databases
{
   // * Config
   // ...

   // * Data
   /** @var array<string,class-string<Database>> */
   private array $classes = [];

   // * Metadata
   // ...


   public function __construct ()
   {
      // * Data
      $this->register('sql', SQL::class);
   }

   /**
    * Register one database paradigm class.
    *
    * @param class-string<Database> $class
    */
   public function register (string $paradigm, string $class): self
   {
      $this->classes[$paradigm] = $class;

      return $this;
   }

   /**
    * Resolve one database paradigm class.
    *
    * @return class-string<Database>
    */
   public function resolve (string $paradigm): string
   {
      if (isset($this->classes[$paradigm]) === false) {
         throw new InvalidArgumentException("Database paradigm is not registered: {$paradigm}.");
      }

      return $this->classes[$paradigm];
   }

   /**
    * Create one database paradigm instance.
    *
    * @param array<string,mixed> $config
    */
   public function create (string $paradigm, array $config = []): Database
   {
      $class = $this->resolve($paradigm);

      return new $class($config);
   }
}
