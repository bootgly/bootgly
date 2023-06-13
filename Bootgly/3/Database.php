<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2015-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Bootgly\__String;


// TODO Refactor class (+1)
class Database
{
   // * Config
   public bool $debug = true;

   // * Data
   protected array $configs;

   // * Meta
   // PDO
   public $PDO;
   public $query;
   // PDOException
   public $PDOException = null;
   //
   private $rows;


   public function __construct (array $configs)
   {
      // * Data
      $this->configs = $configs;
      // * Meta
      $this->rows = [];
   }

   public function __get (string $index)
   {
      switch ($index) {
         case 'PDOException':
            return $this->PDOException;
         case 'connected':
            if ($this->PDO) {
               return true;
            }

            return false;
         default:
            return null;
      }
   }

   public function __set (string $key, $value)
   {
      switch ($key) {
         case 'row':
            $this->rows = array_merge($this->rows, $value); break;

         case 'database':
            $this->db = $value; break;
      }
   }

   public function __call ($name, $arguments)
   {
      try {
         if (@$this->PDO) {
            return $this->$name(...$arguments);
         }
      } catch (\PDOException $e) {
         $this->PDOException = $e->getMessage();
      }
   }

   public function connect ()
   {
      try {
         if (@$this->PDO === null) {
            $configs = $this->configs;
            // ---
            $driver = $configs['driver'];

            $host = $configs['host'];
            $db = $configs['db'];
            $user = $configs['user'];
            $password = $configs['password'];
            // ---
            $this->PDO = new \PDO("$driver:host=$host;dbname=$db;", $user, $password);

            $this->PDO->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
            $this->PDO->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->PDO->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            // $this->PDO->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
         }

         return true;
      } catch (\PDOException $e) {
         // $code = $e->getCode();
      }
   }

   public function use (string $database = '') {
      try {
         if ($database === '') {
            $database = $this->configs['db'];
         }

         if (@$this->PDO) {
            // TODO validate database name
            // TODO parameterized query
            $this->PDO->exec('use `'. $database . '`;');

            return TRUE;
         }
      } catch (\PDOException $e) {
         // $code = $e->getCode();
      }
   }
   public function prepare ($query)
   {
      try {
         if ($this->configs['driver'] === 'pgsql') {
            $query = __String::replace('`', '"', $query);
         }

         $this->query = $this->PDO->prepare($query);

         return $this->query;
      } catch (\PDOException $e) {
         $this->PDOException = $e->getMessage();
      }

      return $this;
   }

   // User
   // Database
   // Table
   // Column
   // Row
   // Cell
}
