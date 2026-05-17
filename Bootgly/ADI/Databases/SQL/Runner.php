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


use const PHP_INT_SIZE;
use function hash;
use function hexdec;
use function is_array;
use function is_string;
use function strtolower;
use function substr;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Schema as SQLSchema;


/**
 * Shared engine for SQL runners that execute files under a coordinated lock.
 */
abstract class Runner
{
   // * Config
   public private(set) SQLDatabase $Database;
   public private(set) SQLSchema $Schema;
   public private(set) Lock $Lock;

   // * Data
   // ...

   // * Metadata
   private string $kind;
   private string $path;
   private bool $advised = false;


   public function __construct (SQLDatabase $Database, string $lock, string $path, string $kind)
   {
      // * Config
      $this->Database = $Database;
      $this->Schema = $Database->structure();
      $this->Lock = new Lock($lock);

      // * Metadata
      $this->kind = $kind;
      $this->path = $path;
   }

   /**
    * Acquire local and dialect advisory locks.
    */
   protected function lock (): void
   {
      if ($this->Lock->acquire() === false) {
         throw new RuntimeException("{$this->kind} lock is already active.");
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
   protected function unlock (): void
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

      throw new RuntimeException("{$this->kind} advisory lock is already active.");
   }

   /**
    * Build a positive signed advisory lock key from the runner path.
    */
   private function hash (): int
   {
      if (PHP_INT_SIZE < 8) {
         throw new RuntimeException("{$this->kind} advisory lock hashing requires 64-bit PHP.");
      }

      $hash = hash('sha256', $this->path);

      return (int) hexdec(substr($hash, 0, 15));
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
    * Execute one query or list of queries.
    */
   protected function execute (mixed $queries, null|Transaction $Transaction = null): void
   {
      foreach ($this->normalize($queries) as $Query) {
         $Operation = $Transaction === null
            ? $this->Database->query($Query->sql, $Query->parameters)
            : $Transaction->query($Query->sql, $Query->parameters);
         $this->Database->Pool->wait($Operation);
      }
   }

   /**
    * Normalize one query or list of queries.
    *
    * @return array<int,Normalized>
    */
   protected function normalize (mixed $queries): array
   {
      if ($queries === null) {
         return [];
      }

      if ($queries instanceof Builder || $queries instanceof SQLQuery || is_string($queries)) {
         return [new Normalized($queries)];
      }

      if (is_array($queries)) {
         $normalized = [];
         foreach ($queries as $query) {
            foreach ($this->normalize($query) as $Query) {
               $normalized[] = $Query;
            }
         }

         return $normalized;
      }

      throw new InvalidArgumentException(
         "{$this->kind} must return null, string, Builder, Query, or an array of those."
      );
   }

   /**
    * Fetch rows for one query.
    *
    * @return array<int,array<string,mixed>>
    */
   protected function fetch (SQLQuery $Query): array
   {
      $Operation = $this->Database->query($Query);
      $this->Database->Pool->wait($Operation);

      return $Operation->Result === null ? [] : $Operation->Result->rows;
   }
}
