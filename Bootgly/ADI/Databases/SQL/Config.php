<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
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
      $this->confine();
   }

   /**
    * Confine every SQLite pool in this configuration to a single connection.
    *
    * The `sqlite3` extension opens one database per driver instance and the pool builds one
    * driver per connection, so a second connection is a second database instead of a second
    * route to the same one: `:memory:` splits into disjoint databases, and a file database
    * refuses the write whose sibling holds the lock.
    */
   private function confine (): void
   {
      if ($this->driver === 'sqlite') {
         $this->pool = $this->narrow($this->pool);
      }

      // @ A replica carries its own driver and its own pool.
      foreach ($this->replicas as $id => $replica) {
         $pool = $replica['pool'] ?? null;

         if (($replica['driver'] ?? null) !== 'sqlite' || is_array($pool) === false) {
            continue;
         }

         $min = $pool['min'] ?? self::DEFAULT_POOL_MIN;
         $max = $pool['max'] ?? self::DEFAULT_POOL_MAX;

         $this->replicas[$id]['pool'] = $this->narrow([
            'min' => is_scalar($min) ? (int) $min : self::DEFAULT_POOL_MIN,
            'max' => is_scalar($max) ? (int) $max : self::DEFAULT_POOL_MAX,
         ]);
      }
   }

   /**
    * Narrow one pool configuration down to a single connection.
    *
    * @param array{min:int,max:int} $pool
    * @return array{min:int,max:int}
    */
   private function narrow (array $pool): array
   {
      // ? A pool configured to open nothing keeps refusing — only sharing is corrected.
      if ($pool['max'] > 1) {
         $pool['max'] = 1;
      }

      if ($pool['min'] > $pool['max']) {
         $pool['min'] = $pool['max'];
      }

      return $pool;
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
