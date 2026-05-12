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


use function is_scalar;
use function max;


/**
 * SQL database configuration.
 */
class Config extends \Bootgly\ADI\Database\Config
{
   public const int DEFAULT_STATEMENTS = 256;

   // * Config
   /** Maximum number of prepared statements cached per connection. */
   public int $statements;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Create a SQL configuration value.
    *
    * @param array<string,mixed> $config
    */
   public function __construct (array $config = [])
   {
      parent::__construct($config);

      $statements = $config['statements'] ?? self::DEFAULT_STATEMENTS;

      // * Config
      $this->statements = is_scalar($statements) ? max(0, (int) $statements) : self::DEFAULT_STATEMENTS;
   }
}
