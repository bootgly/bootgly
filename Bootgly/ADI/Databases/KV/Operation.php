<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\KV;


use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation as DatabaseOperation;


/**
 * Pending key-value database operation.
 */
class Operation extends DatabaseOperation
{
   // * Config
   public string $command;
   /** @var array<int,mixed> */
   public array $arguments;

   // * Data
   public mixed $response = null;
   public string $write = '';

   // * Metadata
   // ...


   /**
    * Create a pending key-value operation.
    *
    * @param array<int,mixed> $arguments
    */
   public function __construct (null|Connection $Connection, string $command, array $arguments = [], float $timeout = 0.0)
   {
      parent::__construct($Connection, [], $timeout);

      // * Config
      $this->command = $command;
      $this->arguments = $arguments;
   }
}
