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


use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\Result;


/**
 * Pending SQL database operation.
 */
class Operation extends DatabaseOperation
{
   // * Config
   public string $sql;

   // * Data
   public string $statement = '';
   public string $portal = '';
   public bool $prepared = false;
   public string $write = '';
   public string $status = '';
   /** @var array<int,array<string,mixed>> */
   public array $rows = [];
   /** @var array<int,string> */
   public array $columns = [];
   /** @var array<int,int> */
   public array $types = [];
   /** @var array<int,int> */
   public array $parameterTypes = [];
   public int $affected = 0;

   // * Metadata
   // ...


   /**
    * Create a pending SQL operation.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function __construct (null|Connection $Connection, string $sql, array $parameters = [], float $timeout = 0.0)
   {
      parent::__construct($Connection, $parameters, $timeout);

      // * Config
      $this->sql = $sql;
   }

   /**
    * Resolve this operation with a result.
    */
   public function resolve (Result $Result): self
   {
      $this->write = '';
      parent::resolve($Result);

      return $this;
   }

   /**
    * Fail this operation with an error message.
    */
   public function fail (string $error): self
   {
      $this->write = '';
      parent::fail($error);

      return $this;
   }
}
