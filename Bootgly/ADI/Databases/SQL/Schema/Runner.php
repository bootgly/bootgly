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


use function count;
use function is_scalar;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Directions;


/**
 * Migration runner for one SQL database and migrations path.
 */
class Runner
{
   // * Config
   public private(set) SQLDatabase $Database;
   public private(set) Migrating $Schema;
   public private(set) Dialect $Dialect;
   public private(set) Guard $Guard;
   public private(set) Migrations $Migrations;
   public private(set) Repository $Repository;

   // * Data
   // ...

   // * Metadata
   private bool $bootstrapped = false;


   public function __construct (
      SQLDatabase $Database,
      string $path,
      string $lock,
      null|string $table = null,
      null|Repository $Repository = null
   )
   {
      $Schema = $Database->structure();

      // * Config
      $this->Database = $Database;
      $this->Schema = $Schema;
      $this->Dialect = $Schema->Dialect;
      $this->Guard = new Guard($Database, $Database->Pool, $this->Dialect, $path, $lock, 'Migration');
      $this->Migrations = new Migrations($path);
      $this->Repository = $Repository
         ?? new Repository($Database->Dialect, $this->Dialect, $table ?? $Database->SQLConfig->migrations);
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

      $applied = $this->Guard->fetch($this->Repository->fetch());
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
      $this->Guard->lock();

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
         $this->Guard->unlock();
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

      $this->Guard->lock();

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
         $this->Guard->unlock();
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
      $this->Guard->lock();

      try {
         $Status = $this->report();
         $added = [];
         $deleted = [];

         foreach ($Status['missing'] as $name) {
            $this->Guard->execute($this->Repository->delete($name));
            $deleted[] = $name;
         }

         if ($Status['pending'] !== []) {
            $batch = $this->peek() + 1;

            foreach ($Status['pending'] as $name) {
               $this->Guard->execute($this->Repository->insert($name, $batch));
               $added[] = $name;
            }
         }
      }
      finally {
         $this->Guard->unlock();
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

      $this->Guard->execute($this->Repository->create());
      $this->bootstrapped = true;
   }

   /**
    * Apply or revert one migration inside a transaction when supported.
    */
   private function apply (Migration $Migration, Directions $Direction, int $batch): void
   {
      $up = $Direction === Directions::Up;

      if ($this->Dialect->transactions) {
         $Transaction = $this->Database->begin();
         if ($Transaction->Operation !== null) {
            $this->Database->Pool->wait($Transaction->Operation);
         }

         try {
            $queries = $up
               ? $Migration->up($this->Schema)
               : $Migration->down($this->Schema);
            $this->Guard->execute($queries, $Transaction);
            $this->Guard->execute(
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
      $this->Guard->execute($queries);
      $this->Guard->execute(
         $up
            ? $this->Repository->insert($Migration->name, $batch)
            : $this->Repository->delete($Migration->name)
      );
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
