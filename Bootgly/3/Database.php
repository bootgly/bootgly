<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use PDO;
use PDOStatement;
use PDOException;

use Bootgly\__String;


class Database
{
   // * Config
   public bool $debug;

   // * Data
   protected array $configs;

   // * Meta
   // PDO
   public ? PDO $PDO;
   public PDOStatement|bool $Query; // TODO rename to Statement?
   public ? PDOException $Exception;
   // TODO
   private $rows;


   public function __construct (array $configs)
   {
      // * Config
      $this->debug = true;

      // * Data
      $this->configs = $configs;

      // * Meta
      // PDO
      $this->PDO = null;
      $this->Query = false;
      $this->Exception = null;
      // TODO
      $this->rows = [];
   }

   public function __get (string $index)
   {
      switch ($index) {
         case 'connected':
            if ($this->PDO instanceof PDO) {
               return true;
            }

            return false;

         case 'id':
            if ($this->PDO instanceof PDO) {
               return $this->PDO->lastInsertId();
            }

            return null;

         case 'PDOException':
            return $this->Exception;

         default:
            return null;
      }
   }

   // ! Database
   public function connect () : bool
   {
      try {
         if ($this->PDO === null) {
            $configs = $this->configs;
            // ---
            $driver = $configs['driver'];

            $host = $configs['host'];
            $db = $configs['db'];
            $user = $configs['user'];
            $password = $configs['password'];
            // ---
            $this->PDO = new PDO("$driver:host=$host;dbname=$db;", $user, $password);

            $this->PDO->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->PDO->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // $this->PDO->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
         }

         return true;
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return false;
   }
   public function disconnect () : bool
   {
      $this->PDO = null;

      return true;
   }
   public function use (string $database = '') : bool
   {
      try {
         if ($database === '') {
            $database = $this->configs['db'];
         }

         if ($this->PDO instanceof PDO) {
            $Statement = $this->PDO->prepare('USE :database');
            $Statement->bindParam(':database', $database);
            $Statement->execute();

            return true;
         }
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return false;
   }

   // ! Query
   public function prepare (string $query) : PDOStatement|bool
   {
      try {
         if ($this->configs['driver'] === 'pgsql') {
            $query = __String::replace('`', '"', $query);
         }

         $this->Query = $this->PDO->prepare($query);

         return $this->Query;
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return false;
   }

   // ! Table
   /**
    * Prepares and executes a SELECT statement with given columns, tables and optional conditions,
    * then returns the result set as an array.
    * 
    * @param array $columns  The columns to be selected.
    * @param string $table   The table to select from.
    * @param array $params   Optional conditions for the SQL query.
    *
    * @return PDOStatement|bool|null     The PDOStatement return; null on failure.
    */
   public function select (array $columns, string $table, array $params = []) : PDOStatement|bool|null
   {
      try {
         // @ Prepare the columns for the SQL query
         $columnsString = implode(', ', $columns);

         // @ Prepare the conditions for the SQL query
         $paramsString = '';
         if ( ! empty($params) ) {
            $conditions = array_map(fn ($key, $val) => "$key = :$key", array_keys($params), $params);
            $paramsString = " WHERE " . implode(' AND ', $conditions);
         }

         // @ Prepare the SQL query
         $query = "SELECT {$columnsString} FROM {$table}{$paramsString}";
         $Statement = $this->prepare($query);

         // @ Bind parameters if any
         if ( ! empty($params) ) {
            foreach ($params as $key => &$value) {
               // PDOStatement::bindValue() allows binding parameters by reference
               $Statement->bindValue(":$key", $value);
            }
         }

         // @ Execute the SQL query
         $Statement->execute();

         // @ Return Statement
         return $Statement;
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return null;
   }
   public function insert (string $table, array $data) : bool|string|null
   {
      try {
         $columns = implode(',', array_keys($data));
         $values = implode(',', array_fill(0, count($data), '?'));

         $Statement = $this->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$values})");
         $Statement->execute(array_values($data));

         return $this->PDO->lastInsertId();
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return null;
   }
   public function update (string $table, array $data, array $where) : int|null
   {
      try {
         $set = implode(',', array_map(fn($col) => "$col = ?", array_keys($data)));
         $conditions = implode(' AND ', array_map(fn($col) => "$col = ?", array_keys($where)));

         $Statement = $this->prepare("UPDATE {$table} SET {$set} WHERE {$conditions}");
         $Statement->execute(array_merge(array_values($data), array_values($where)));

         return $Statement->rowCount();
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return null;
   }
   public function delete (string $table, array $where) : int|null
   {
      try {
         $conditions = implode(' AND ', array_map(fn($col) => "$col = ?", array_keys($where)));

         $Statement = $this->prepare("DELETE FROM {$table} WHERE {$conditions}");
         $Statement->execute(array_values($where));

         return $Statement->rowCount();
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return null;
   }

   // ! Transaction
   public function transact () : bool|null
   {
      try {
         return $this->PDO->beginTransaction();
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return null;
   }
   public function commit () : bool|null
   {
      try {
         return $this->PDO->commit();
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return null;
   }
   public function rollback () : bool|null
   {
      try {
         return $this->PDO->rollBack();
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return null;
   }

   // ! Database
   // ? User

   // ? Connection

   // ? Query
   // ? Result

   // ? Transaction

   // ! Table
   // ? Column
   // ? Row
   // ? Cell
}
