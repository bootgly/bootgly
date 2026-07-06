<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Driver as DatabaseDriver;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Operation;


/**
 * SQL database wire driver base.
 */
abstract class Driver extends DatabaseDriver
{
   // * Config
   public Config $SQLConfig;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (Config $Config, Connection $Connection)
   {
      parent::__construct($Config, $Connection);

      // * Config
      $this->SQLConfig = $Config;
   }

   /**
    * Create a driver-specific SQL query operation.
    *
    * @param array<int|string,mixed> $parameters
    */
   abstract public function query (string $sql, array $parameters = []): Operation;
}
