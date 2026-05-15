<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema;


use const PHP_INT_SIZE;
use function count;
use function hash;
use function hexdec;
use function is_array;
use function is_scalar;
use function is_string;
use function strtolower;
use function substr;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Schema as SQLSchema;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Directions;
use Bootgly\ADI\Databases\SQL\Transaction;


/**
 * Migration runner for one SQL database and migrations path.
 */
class Runner
{
   // * Config
   public private(set) SQLDatabase $Database;
   public private(set) SQLSchema $Schema;
   public private(set) Migrations $Migrations;
   public private(set) Repository $Repository;
   public private(set) Lock $Lock;

   // * Data
   // ...

   // * Metadata
   private bool $advised = false;
   private bool $bootstrapped = false;


   public function __construct (
      SQLDatabase $Database,
      string $path,
      string $lock,
      null|string $table = null,
      null|Repository $Repository = null
   )
   {
      // * Config
      $this->Database = $Database;
      $this->Schema = $Database->structure();
      $this->Migrations = new Migrations($path);
      $this->Repository = $Repository ?? new Repository($Database->Dialect, $table ?? $Database->SQLConfig->migrations);
      $this->Lock = new Lock($lock);
   }

   /**
    * Create one migration file.
    */
   public function create (string $name): string
   {
      return $this->Migrations->create($name);
   }

   /**
    * Return migration status data.
    *
    * @return array{applied: array<int,array<string,mixed>>, pending: array<int,string>, missing: array<int,string>, files: array<string,string>}
    */
   public function report (): array
   {
      $this->boot();

      $applied = $this->fetch($this->Repository->fetch());
      $files = $this->Migrations->discover();
      $known = [];
      $missing = [];

      foreach ($applied as $row) {
         $migration = $row['migration'] ?? null;
         if (is_scalar($migration)) {
            $name = (string) $migration;
            $known[$name] = true;

            if (isset($files[$name]) === false) {
               $missing[] = $name;
            }
         }
      }

      $pending = [];
      foreach ($files as $name => $file) {
         if (isset($known[$name]) === false) {
            $pending[] = $name;
         }
      }

      return [
         'applied' => $applied,
         'pending' => $pending,
         'missing' => $missing,
         'files'   => $files,
      ];
   }

   /**
    * Apply pending migrations.
    *
    * @return array<int,string>
    */
   public function up (int $limit = 0): array
   {
      $this->lock();

      try {
         $Status = $this->report();
         $batch = $this->peek() + 1;
         $applied = [];

         foreach ($Status['pending'] as $name) {
            if ($limit > 0 && count($applied) >= $limit) {
               break;
            }

            $Migration = $this->Migrations->load($Status['files'][$name]);
            $this->apply($Migration, Directions::Up, $batch);
            $applied[] = $name;
         }
      }
      finally {
         $this->unlock();
      }

      return $applied;
   }

   /**
    * Revert the requested number of applied migrations.
    *
    * @return array<int,string>
    */
   public function down (int $steps, null|int $batch = null): array
   {
      if ($steps <= 0) {
         throw new InvalidArgumentException('Migration down requires a positive step count.');
      }

      if ($batch !== null && $batch <= 0) {
         throw new InvalidArgumentException('Migration down requires a positive batch number.');
      }

      $this->lock();

      try {
         $Status = $this->report();
         $applied = [];
         $reverted = [];

         foreach ($Status['applied'] as $row) {
            if ($batch !== null) {
               $value = $row['batch'] ?? null;
               if (is_scalar($value) === false || (int) $value !== $batch) {
                  continue;
               }
            }

            $applied[] = $row;
            if (count($applied) >= $steps) {
               break;
            }
         }

         foreach ($applied as $row) {
            $migration = $row['migration'] ?? null;
            if (is_scalar($migration) === false) {
               throw new RuntimeException('Migration history row is missing the migration name.');
            }

            $name = (string) $migration;
            if ($name === '' || isset($Status['files'][$name]) === false) {
               throw new RuntimeException("Migration file not found: {$name}.");
            }

            $Migration = $this->Migrations->load($Status['files'][$name]);
            $this->apply($Migration, Directions::Down, 0);
            $reverted[] = $name;
         }
      }
      finally {
         $this->unlock();
      }

      return $reverted;
   }

   /**
    * Synchronize migration history with local migration files.
    *
    * @return array{added: array<int,string>, deleted: array<int,string>}
    */
   public function sync (): array
   {
      $this->lock();

      try {
         $Status = $this->report();
         $added = [];
         $deleted = [];

         foreach ($Status['missing'] as $name) {
            $this->execute($this->Repository->delete($name));
            $deleted[] = $name;
         }

         if ($Status['pending'] !== []) {
            $batch = $this->peek() + 1;

            foreach ($Status['pending'] as $name) {
               $this->execute($this->Repository->insert($name, $batch));
               $added[] = $name;
            }
         }
      }
      finally {
         $this->unlock();
      }

      return [
         'added'   => $added,
         'deleted' => $deleted,
      ];
   }

   /**
    * Bootstrap the migration repository once per runner instance.
    */
   private function boot (): void
   {
      if ($this->bootstrapped) {
         return;
      }

      $this->execute($this->Repository->create());
      $this->bootstrapped = true;
   }

   /**
    * Acquire local and dialect advisory locks.
    */
   private function lock (): void
   {
      if ($this->Lock->acquire() === false) {
         throw new RuntimeException('Migration lock is already active.');
      }

      try {
         $this->advise();
      }
      catch (Throwable $Throwable) {
         $this->Lock->release();

         throw $Throwable;
      }
   }

   /**
    * Release dialect advisory and local locks.
    */
   private function unlock (): void
   {
      try {
         if ($this->advised === false) {
            return;
         }

         $Query = $this->Schema->Dialect->unlock($this->hash());
         if ($Query !== null) {
            $this->execute($Query);
         }

         $this->advised = false;
      }
      finally {
         $this->Lock->release();
      }
   }

   /**
    * Acquire a dialect advisory lock when supported.
    */
   private function advise (): void
   {
      $Query = $this->Schema->Dialect->lock($this->hash());
      if ($Query === null) {
         return;
      }

      $rows = $this->fetch($Query);
      $locked = $rows[0]['locked'] ?? false;

      if ($this->check($locked)) {
         $this->advised = true;

         return;
      }

      throw new RuntimeException('Migration advisory lock is already active.');
   }

   /**
    * Build the advisory lock key from the migrations path.
    */
   private function hash (): int
   {
      if (PHP_INT_SIZE < 8) {
         throw new RuntimeException('Migration advisory lock hashing requires 64-bit PHP.');
      }

      $hash = hash('sha256', $this->Migrations->path);
      $high = (int) hexdec(substr($hash, 0, 8));
      $low = (int) hexdec(substr($hash, 8, 8));

      return ($high << 32) | $low;
   }

   /**
    * Normalize database boolean result values.
    */
   private function check (mixed $value): bool
   {
      if ($value === true || $value === 1) {
         return true;
      }

      if (is_string($value) === false) {
         return false;
      }

      $value = strtolower($value);

      return $value === '1' || $value === 't' || $value === 'true';
   }

   /**
    * Apply or revert one migration inside a transaction when supported.
    */
   private function apply (Migration $Migration, Directions $Direction, int $batch): void
   {
      $up = $Direction === Directions::Up;

      if ($this->Schema->Dialect->transactions) {
         $Transaction = $this->Database->begin();
         if ($Transaction->Operation !== null) {
            $this->Database->Pool->wait($Transaction->Operation);
         }

         try {
            $queries = $up
               ? $Migration->up($this->Schema)
               : $Migration->down($this->Schema);
            $this->execute($queries, $Transaction);
            $this->execute(
               $up
                  ? $this->Repository->insert($Migration->name, $batch)
                  : $this->Repository->delete($Migration->name),
               $Transaction
            );
            $this->Database->Pool->wait($Transaction->commit());
         }
         catch (Throwable $Throwable) {
            $this->Database->Pool->wait($Transaction->rollback());

            throw $Throwable;
         }

         return;
      }

      $queries = $up
         ? $Migration->up($this->Schema)
         : $Migration->down($this->Schema);
      $this->execute($queries);
      $this->execute(
         $up
            ? $this->Repository->insert($Migration->name, $batch)
            : $this->Repository->delete($Migration->name)
      );
   }

   /**
    * Execute one query or list of queries.
    */
   private function execute (mixed $queries, null|Transaction $Transaction = null): void
   {
      if ($queries === null) {
         return;
      }

      if ($queries instanceof SQLQuery || is_string($queries)) {
         $Operation = $Transaction === null
            ? $this->Database->query($queries)
            : $Transaction->query($queries);
         $this->Database->Pool->wait($Operation);

         return;
      }

      if (is_array($queries)) {
         foreach ($queries as $query) {
            $this->execute($query, $Transaction);
         }
      }
   }

   /**
    * Fetch rows for one query.
    *
    * @return array<int,array<string,mixed>>
    */
   private function fetch (SQLQuery $Query): array
   {
      $Operation = $this->Database->query($Query);
      $this->Database->Pool->wait($Operation);

      return $Operation->Result === null ? [] : $Operation->Result->rows;
   }

   /**
    * Fetch the current migration batch number.
    */
   private function peek (): int
   {
      $Operation = $this->Database->query($this->Repository->peek());
      $this->Database->Pool->wait($Operation);

      $cell = $Operation->Result === null ? 0 : $Operation->Result->cell;

      return is_scalar($cell) ? (int) $cell : 0;
   }
}
