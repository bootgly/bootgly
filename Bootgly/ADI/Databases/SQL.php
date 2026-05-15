<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases;


use BackedEnum;
use Stringable;

use Bootgly\ADI\Database;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Dialect;
use Bootgly\ADI\Databases\SQL\Builder\Dialects;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Schema;
use Bootgly\ADI\Databases\SQL\Transaction;


/**
 * SQL database facade.
 */
class SQL extends Database
{
   // * Config
   public Config $SQLConfig;
   public private(set) Dialect $Dialect;

   // * Data
   // ...

   // * Metadata
   private null|Schema $Schema = null;
   // ...


   /**
    * Create a SQL database facade.
    *
    * @param array<string,mixed>|Config $config
    */
   public function __construct (array|Config $config = [])
   {
      // * Config
      $Config = $config instanceof Config
         ? $config
         : new Config($config);
      $this->SQLConfig = $Config;

      $Dialects = new Dialects;
      $this->Dialect = $Dialects->fetch($Config->driver);

      parent::__construct($Config, Drivers::class);
   }

   /**
    * Create an async SQL query operation.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = []): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new Operation(null, $Normalized->sql, $Normalized->parameters, $this->Config->timeout);
      $this->Pool->assign($Operation);

      return $Operation;
   }

   /**
    * Start a SQL query builder for one table.
    */
   public function table (BackedEnum|Stringable|Builder|Query $Table, null|BackedEnum|Stringable $Alias = null): Builder
   {
      $Builder = new Builder($this->Dialect);

      return $Builder->table($Table, $Alias);
   }

   /**
    * Start the SQL schema structure builder.
    */
   public function structure (): Schema
   {
      if ($this->Schema === null) {
         $this->Schema = new Schema($this->Dialect);
      }

      return $this->Schema;
   }

   /**
    * Begin a SQL transaction pinned to one pooled connection.
    */
   public function begin (): Transaction
   {
      return new Transaction($this);
   }

   /**
    * Advance an async SQL operation through the selected driver.
    */
   public function advance (Operation $Operation): Operation
   {
      $this->Pool->advance($Operation);

      return $Operation;
   }

   /**
    * Cancel one running SQL operation when supported by the driver.
    */
   public function cancel (Operation $Operation): Operation
   {
      $this->Pool->cancel($Operation);

      return $Operation;
   }
}
