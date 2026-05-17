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
use function hash;
use function hexdec;
use function is_array;
use function is_string;
use function strtolower;
use function substr;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Lock;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Querying;


/**
 * Shared guard for SQL runners that execute files under a coordinated lock.
 */
class Guard
{
   // * Config
   public private(set) Querying $Database;
   public private(set) Pool $Pool;
   public private(set) Dialect $Dialect;
   public private(set) Lock $Lock;

   // * Data
   // ...

   // * Metadata
   private string $kind;
   private string $path;
   private bool $advised = false;


   public function __construct (
      Querying $Database,
      Pool $Pool,
      Dialect $Dialect,
      string $path,
      string $lock,
      string $kind
   )
   {
      // * Config
      $this->Database = $Database;
      $this->Pool = $Pool;
      $this->Dialect = $Dialect;
      $this->Lock = new Lock($lock);

      // * Metadata
      $this->kind = $kind;
      $this->path = $path;
   }

   /**
    * Acquire local and dialect advisory locks.
    */
   public function lock (): void
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
   public function unlock (): void
   {
      try {
         if ($this->advised === false) {
            return;
         }

         $Query = $this->Dialect->unlock($this->hash());
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
    * Execute one query or list of queries.
    */
   public function execute (mixed $queries, null|Querying $Querying = null): void
   {
      $Querying ??= $this->Database;

      foreach ($this->normalize($queries) as $Query) {
         $Operation = $Querying->query($Query->sql, $Query->parameters);
         $this->Pool->wait($Operation);
      }
   }

   /**
    * Fetch rows for one query.
    *
    * @return array<int,array<string,mixed>>
    */
   public function fetch (SQLQuery $Query): array
   {
      $Operation = $this->Database->query($Query);
      $this->Pool->wait($Operation);

      return $Operation->Result === null ? [] : $Operation->Result->rows;
   }

   /**
    * Normalize one query or list of queries.
    *
    * @return array<int,Normalized>
    */
   public function normalize (mixed $queries): array
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
    * Acquire a dialect advisory lock when supported.
    */
   private function advise (): void
   {
      $Query = $this->Dialect->lock($this->hash());
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
}
