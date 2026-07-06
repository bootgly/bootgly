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


use function is_array;
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
   public const float DEFAULT_ROUTING_STICKY = 5.0;

   // * Config
   /** Migration repository table name. */
   public string $migrations;
   /** Maximum number of prepared statements cached per connection. */
   public int $statements;
   /** @var array{sticky:float} Process-wide best-effort seconds to keep reads on primary after any write. */
   public array $routing;

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
      $routing = $config['routing'] ?? [];

      if (is_array($routing) === false) {
         $routing = [];
      }

      // * Config
      $migrations = is_scalar($migrations) ? trim((string) $migrations) : '';
      $this->migrations = $migrations === '' ? self::DEFAULT_MIGRATIONS : $migrations;
      $this->statements = is_scalar($statements) ? max(0, (int) $statements) : self::DEFAULT_STATEMENTS;
      $this->routing = [
         'sticky' => is_scalar($routing['sticky'] ?? null) ? max(0.0, (float) $routing['sticky']) : self::DEFAULT_ROUTING_STICKY,
      ];

      $this->complete($config['replicas'] ?? []);
   }

   /**
    * Complete SQL-specific replica configuration.
    */
   private function complete (mixed $replicas): void
   {
      foreach ($this->replicas as $id => $replica) {
         $this->replicas[$id]['statements'] = $this->statements;
      }

      if (is_array($replicas) === false) {
         return;
      }

      $id = 0;

      foreach ($replicas as $replica) {
         $replica = $this->accept($replica);

         if ($replica === null) {
            continue;
         }

         if (isset($this->replicas[$id]) === false) {
            break;
         }

         $statements = $replica['statements'];
         $this->replicas[$id]['statements'] = is_scalar($statements) ? max(0, (int) $statements) : $this->statements;
         $id++;
      }
   }
}
