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


class Database // TODO Refactor class (+2)
{
   // * Config
   public bool $debug;

   // * Data
   protected array $configs;

   // * Meta
   // PDO
   public ? PDO $PDO;
   public PDOStatement|bool $Query;
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
            if ($this->PDO) {
               return true;
            }

            return false;
         case 'PDOException':
            return $this->Exception;
         default:
            return null;
      }
   }

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

   public function use (string $database = '') : bool
   {
      try {
         if ($database === '') {
            $database = $this->configs['db'];
         }

         if ($this->PDO instanceof PDO) {
            // TODO validate database name
            // TODO parameterized query
            $this->PDO->exec('use `'. $database . '`;');

            return true;
         }
      } catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return false;
   }
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

   // User
   // Database
   // Table
   // Column
   // Row
   // Cell
}
