<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Seed;


use function is_array;
use RuntimeException;
use Throwable;

use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Modes;
use Bootgly\ADI\Databases\SQL\Schema\Guard;
use Bootgly\ADI\Databases\SQL\Seed;


/**
 * Seeder runner for one SQL database and seeders path.
 */
class Runner
{
   // * Config
   public private(set) SQLDatabase $Database;
   public private(set) Guard $Guard;
   public private(set) Seeders $Seeders;

   // * Data
   public private(set) Seed $Seed;

   // * Metadata
   // ...


   public function __construct (SQLDatabase $Database, string $path, string $lock, null|Seed $Seed = null)
   {
      $Schema = $Database->structure();

      // * Config
      $this->Database = $Database;
      $this->Guard = new Guard($Database, $Database->Pool, $Schema->Dialect, $path, $lock, 'Seeder');
      $this->Seeders = new Seeders($path);

      // * Data
      $this->Seed = $Seed ?? new Seed;
   }

   /**
    * Create one seeder file.
    */
   public function create (string $name): string
   {
      return $this->Seeders->create($name);
   }

   /**
    * Return discovered seeders.
    *
    * @return array<string,string>
    */
   public function discover (): array
   {
      return $this->Seeders->discover();
   }

   /**
    * Run all seeders or one named seeder.
    *
    * @return array<int,string>
    */
   public function run (null|string $name = null): array
   {
      $this->Guard->lock();

      try {
         $files = $this->collect($name);
         $ran = [];

         foreach ($files as $seeder => $file) {
            $Seeder = $this->Seeders->load($file);
            $this->apply($Seeder);
            $ran[] = $seeder;
         }
      }
      finally {
         $this->Guard->unlock();
      }

      return $ran;
   }

   /**
    * Preview all seeders or one named seeder without executing SQL.
    *
    * @return array<string,array<int,array{sql:string,parameters:array<int|string,mixed>}>>
    */
   public function preview (null|string $name = null): array
   {
      $preview = [];

      foreach ($this->collect($name) as $seeder => $file) {
         $Seeder = $this->Seeders->load($file);
         $queries = $this->resync($Seeder->run($this->Database, $this->Seed));
         $preview[$seeder] = [];

         foreach ($this->Guard->normalize($queries) as $Query) {
            $preview[$seeder][] = [
               'sql'        => $Query->SQL,
               'parameters' => $Query->parameters,
            ];
         }
      }

      return $preview;
   }

   /**
    * Apply one seeder inside a transaction when supported.
    */
   private function apply (Seeder $Seeder): void
   {
      // ! Composed before any transaction opens. The closure is handed the pool and has no
      //   route to the transaction, so composing it inside one leaves a seeder that reads
      //   the database asking a pool whose only connection is already locked. `preview()`
      //   composes outside a transaction too, and `Guard::execute()` takes the compiled SQL,
      //   so nothing here needs the transaction open before the queries exist.
      $queries = $this->resync($Seeder->run($this->Database, $this->Seed));

      if ($this->Guard->Dialect->transactions) {
         $Transaction = $this->Database->begin();
         if ($Transaction->Operation !== null) {
            $this->Database->Pool->wait($Transaction->Operation);
         }

         try {
            $this->Guard->execute($queries, $Transaction);
            $this->Database->Pool->wait($Transaction->commit());
         }
         catch (Throwable $Throwable) {
            $this->Database->Pool->wait($Transaction->rollback());

            throw $Throwable;
         }

         return;
      }

      $this->Guard->execute($queries);
   }

   /**
    * Follow each Builder INSERT with the dialect's identity resync, when it compiles one.
    *
    * Explicit keys do not advance a PostgreSQL identity sequence, so the resync runs right
    * after its own INSERT: a later generated insert — in the same seeder or in the
    * application — does not reuse a seeded integer key. Anything else passes through untouched.
    */
   private function resync (mixed $queries): mixed
   {
      // @@ A list keeps its order, each item followed by its own resync
      if (is_array($queries)) {
         $resynced = [];
         foreach ($queries as $key => $query) {
            $resynced[$key] = $this->resync($query);
         }

         return $resynced;
      }

      // ? Only an INSERT into a named table writes keys
      if (
         $queries instanceof Builder === false
         || $queries->Mode !== Modes::Insert
         || $queries->table === null
      ) {
         return $queries;
      }

      // : A dialect that needs no resync answers `null`, which `Guard::normalize()` drops
      return [$queries, $this->Guard->Dialect->resync($queries->table, $queries->assignments)];
   }

   /**
    * Collect all seeder files or one named seeder.
    *
    * @return array<string,string>
    */
   private function collect (null|string $name = null): array
   {
      $files = $this->Seeders->discover();

      if ($name === null) {
         return $files;
      }

      if (isset($files[$name]) === false) {
         throw new RuntimeException("Seeder file not found: {$name}.");
      }

      return [$name => $files[$name]];
   }
}
