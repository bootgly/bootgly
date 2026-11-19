<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\KV;


use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation as DatabaseOperation;


/**
 * Pending key-value database operation.
 *
 * Debug dumps mask its wire buffer: the first command on a fresh connection
 * carries the `AUTH` preamble with the password.
 */
class Operation extends DatabaseOperation
{
   use Redactable;


   // * Config
   public string $command;
   /** @var array<int,mixed> */
   public array $arguments;

   // * Data
   public mixed $response = null;
   #[Secret]
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
