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
use function trim;


/**
 * SQL database configuration.
 */
class Config extends \Bootgly\ADI\Database\Config
{
   public const string DEFAULT_MIGRATIONS = '_bootgly_migrations';
   public const int DEFAULT_STATEMENTS = 256;

   // * Config
   /** Migration repository table name. */
   public string $migrations;
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

      $migrations = $config['migrations'] ?? self::DEFAULT_MIGRATIONS;
      $statements = $config['statements'] ?? self::DEFAULT_STATEMENTS;

      // * Config
      $migrations = is_scalar($migrations) ? trim((string) $migrations) : '';
      $this->migrations = $migrations === '' ? self::DEFAULT_MIGRATIONS : $migrations;
      $this->statements = is_scalar($statements) ? max(0, (int) $statements) : self::DEFAULT_STATEMENTS;
   }
}
