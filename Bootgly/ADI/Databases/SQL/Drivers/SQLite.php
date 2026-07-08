<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Drivers;


use const SQLITE3_ASSOC;
use const SQLITE3_FLOAT;
use const SQLITE3_INTEGER;
use const SQLITE3_NULL;
use const SQLITE3_TEXT;
use function array_key_first;
use function count;
use function extension_loaded;
use function fopen;
use function is_bool;
use function is_float;
use function is_int;
use function is_scalar;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function strtoupper;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use SQLite3;
use SQLite3Stmt;
use Throwable;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL\Driver;
use Bootgly\ADI\Databases\SQL\Events;
use Bootgly\ADI\Databases\SQL\Operation;


/**
 * SQLite driver over the `sqlite3` extension (file or `:memory:` databases).
 *
 * The `sqlite3` extension is synchronous: operations resolve inside
 * `prepare()` and never expose Readiness — the Pool wait/advance loops break
 * on `finished` before consulting it. Because the Pool drops connections
 * whose socket is not a live resource, `open()` attaches a placeholder
 * `php://memory` stream to the Connection; nothing ever selects on it since
 * no Readiness is exposed.
 */
class SQLite extends Driver
{
   // * Config
   // ...

   // * Data
   /** @var array<string,SQLite3Stmt> */
   public private(set) array $statements = [];

   // * Metadata
   private null|SQLite3 $Handle = null;


   /**
    * Create a SQLite query operation.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function query (string $sql, array $parameters = []): Operation
   {
      $Operation = new Operation($this->Connection, $sql, $parameters, $this->Config->timeout);
      $this->prepare($Operation);

      return $Operation;
   }

   /**
    * Prepare and synchronously execute an operation.
    */
   public function prepare (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof Operation === false) {
         return $Operation->fail('SQLite requires an SQL operation.');
      }

      /** @var Operation $Operation */

      // ?
      if (extension_loaded('sqlite3') === false) {
         return $Operation->fail('SQLite driver requires the sqlite3 extension.');
      }

      $Operation->Connection = $this->Connection;
      $Operation->Protocol = $this;
      $Operation->state = OperationStates::Querying;

      // @ Synchronous execution — the operation finishes here.
      try {
         $this->open();
         $this->execute($Operation);
      }
      catch (Throwable $Throwable) {
         $Operation->fail($Throwable->getMessage());
      }

      // :
      return $Operation;
   }

   /**
    * Advance an operation — a no-op after the synchronous execution.
    */
   public function advance (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof Operation === false) {
         return $Operation->fail('SQLite requires an SQL operation.');
      }

      /** @var Operation $Operation */

      // ? Operations normally finish inside prepare().
      if ($Operation->finished) {
         return $Operation;
      }

      // : Operations promoted from the pool pending queue restart here.
      return $this->prepare($Operation);
   }

   /**
    * Open the database handle and satisfy the pool connection contract.
    */
   private function open (): void
   {
      // ?
      if ($this->Handle === null) {
         // ! Database handle — file path or `:memory:`
         $Handle = new SQLite3($this->Config->database);
         $Handle->enableExceptions(true);
         $Handle->busyTimeout((int) ($this->Config->timeout * 1000));
         // SQLite ships with foreign keys off per connection — enforce them
         // for parity with PostgreSQL/MySQL referential integrity.
         $Handle->exec('PRAGMA foreign_keys = ON;');

         $this->Handle = $Handle;

         // @ Events — SQL connection opened (guarded: zero-alloc when no listeners)
         $Emitter = Emitter::$Instance;
         $Emitter->check(Events::Connected) && $Emitter->emit(Events::Connected, $this->Connection);
      }

      // ? Pool liveness — attach a placeholder stream (SQLite has no wire socket).
      if ($this->Connection->connected === false) {
         $placeholder = fopen('php://memory', 'r+');

         if ($placeholder === false) {
            throw new RuntimeException('SQLite driver could not allocate the placeholder connection stream.');
         }

         $this->Connection->attach($placeholder);
      }
   }

   /**
    * Execute an operation against the open database handle.
    */
   private function execute (Operation $Operation): void
   {
      $Handle = $this->Handle;

      // ?
      if ($Handle === null) {
         $Operation->fail('SQLite database handle is not open.');

         return;
      }

      // ? The sqlite3 extension steps RETURNING statements twice (internal
      //   step + reset before the fetch), duplicating the write — fail fast.
      $stripped = preg_replace(
         '/\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|\[[^\]]*\]|--[^\n]*|\/\*.*?\*\//s',
         '',
         $Operation->SQL
      );

      if ($stripped !== null && preg_match('/\bRETURNING\b/i', $stripped) === 1) {
         $Operation->fail('SQLite RETURNING is not supported: the sqlite3 extension executes the statement twice, duplicating the write. Read generated ids from Result->inserted.');

         return;
      }

      // @ Run the SQL — prepared statement when parameters exist, direct query otherwise.
      // ! Statement kept out of the cache (`statements === 0`) — closed after use.
      $Transient = null;

      if ($Operation->parameters === []) {
         $SQLite3Result = $Handle->query($Operation->SQL);
      }
      else {
         $cache = $this->SQLConfig->statements > 0;
         $Statement = $cache ? ($this->statements[$Operation->SQL] ?? null) : null;

         if ($Statement === null) {
            // ? Statement cache full — evict the least recently used entry.
            if ($cache && count($this->statements) >= $this->SQLConfig->statements) {
               $this->evict((string) array_key_first($this->statements));
            }

            $prepared = $Handle->prepare($Operation->SQL);

            if ($prepared === false) {
               $Operation->fail('SQLite could not prepare the statement.');

               return;
            }

            $Statement = $prepared;

            if ($cache) {
               $this->statements[$Operation->SQL] = $Statement;
            }
            else {
               $Transient = $Statement;
            }
         }
         else {
            // @ LRU touch — move the reused statement to the end of the cache.
            unset($this->statements[$Operation->SQL]);
            $this->statements[$Operation->SQL] = $Statement;

            $Statement->reset();
            $Statement->clear();
         }

         $Operation->statement = $Operation->SQL;
         $Operation->prepared = true;

         // @@
         foreach ($Operation->parameters as $key => $parameter) {
            $this->bind($Statement, $key, $parameter);
         }

         $SQLite3Result = $Statement->execute();
      }

      // ?
      if ($SQLite3Result === false) {
         $Operation->fail('SQLite query execution failed.');

         return;
      }

      // ! Result hydration
      $rows = [];
      $columns = [];
      $selected = $SQLite3Result->numColumns();

      if ($selected > 0) {
         for ($index = 0; $index < $selected; $index++) {
            $columns[] = $SQLite3Result->columnName($index);
         }

         // @@
         while (($row = $SQLite3Result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
         }
      }

      $SQLite3Result->finalize();
      $Transient?->close();

      // ! Command metadata — mirror the PostgreSQL CommandComplete tags
      $keyword = preg_match('/^\s*(\w+)/', $Operation->SQL, $matches) === 1
         ? strtoupper($matches[1])
         : '';
      $affected = match ($keyword) {
         'INSERT', 'UPDATE', 'DELETE', 'REPLACE' => $Handle->changes(),
         default => 0
      };
      $inserted = $keyword === 'INSERT' || $keyword === 'REPLACE'
         ? $Handle->lastInsertRowID()
         : 0;
      $status = match ($keyword) {
         'SELECT' => 'SELECT ' . count($rows),
         'INSERT' => "INSERT 0 {$affected}",
         'UPDATE', 'DELETE', 'REPLACE' => "{$keyword} {$affected}",
         default => $keyword
      };

      $Operation->status = $status;
      $Operation->rows = $rows;
      $Operation->columns = $columns;
      $Operation->affected = $affected;

      // :
      $Operation->resolve(new Result($status, $rows, $columns, $affected, $inserted));
   }

   /**
    * Bind one parameter to a prepared statement.
    */
   private function bind (SQLite3Stmt $Statement, int|string $key, mixed $parameter): void
   {
      // ! Target — 1-based position for `?N` placeholders; `:name` for named placeholders.
      $target = is_int($key)
         ? $key + 1
         : (str_starts_with($key, ':') ? $key : ":{$key}");

      // @
      if ($parameter === null) {
         $Statement->bindValue($target, null, SQLITE3_NULL);
      }
      elseif (is_bool($parameter)) {
         $Statement->bindValue($target, $parameter ? 1 : 0, SQLITE3_INTEGER);
      }
      elseif (is_int($parameter)) {
         $Statement->bindValue($target, $parameter, SQLITE3_INTEGER);
      }
      elseif (is_float($parameter)) {
         $Statement->bindValue($target, $parameter, SQLITE3_FLOAT);
      }
      elseif ($parameter instanceof DateTimeInterface) {
         $Statement->bindValue($target, $parameter->format('Y-m-d H:i:s.u'), SQLITE3_TEXT);
      }
      elseif (is_scalar($parameter)) {
         $Statement->bindValue($target, (string) $parameter, SQLITE3_TEXT);
      }
      else {
         throw new InvalidArgumentException("SQLite cannot bind the parameter \"{$key}\".");
      }
   }

   /**
    * Evict one prepared statement from the cache.
    */
   private function evict (string $sql): void
   {
      $Statement = $this->statements[$sql] ?? null;

      // ?
      if ($Statement === null) {
         return;
      }

      $Statement->close();
      unset($this->statements[$sql]);
   }
}
