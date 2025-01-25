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


class Database
{
   // * Config
   public bool $debug;

   // * Data
   /** @var array<string,string> */
   protected array $configs;

   // * Metadata
   // PDO
   public null|PDO $PDO;
   public PDOStatement|bool $Query;
   public null|PDOException $Exception;


   /**
    * Database constructor.
    * 
    * @param array<string,string> $configs
    */
   public function __construct (array $configs)
   {
      // * Config
      $this->debug = true;

      // * Data
      $this->configs = $configs;

      // * Metadata
      // PDO
      $this->PDO = null;
      $this->Query = false;
      $this->Exception = null;
   }

   public function __get (string $index): mixed
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
   public function connect (): bool
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
      }
      catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return false;
   }
   public function disconnect (): bool
   {
      $this->PDO = null;

      return true;
   }
   public function use (string $database = ''): bool
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
      }
      catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return false;
   }

   // ! Query
   public function prepare (string $query): PDOStatement|bool
   {
      try {
         if ($this->configs['driver'] === 'pgsql') {
            $query = str_replace('`', '"', $query);
         }

         // ! PDO
         $PDO = $this->PDO;
         // ?
         if ($PDO === null) {
            return false;
         }

         // @
         $this->Query = $PDO->prepare($query);

         return $this->Query;
      }
      catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return false;
   }

   // ! Transaction
   public function transact (): bool|null
   {
      try {
         // ! PDO
         $PDO = $this->PDO;
         // ?
         if ($PDO === null) {
            return null;
         }

         return $PDO->beginTransaction();
      }
      catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return null;
   }
   public function commit (): bool|null
   {
      try {
         // ! PDO
         $PDO = $this->PDO;
         // ?
         if ($PDO === null) {
            return null;
         }

         return $PDO->commit();
      }
      catch (PDOException $PDOException) {
         $this->Exception = $PDOException;
      }

      return null;
   }
   public function rollback (): bool|null
   {
      try {
         // ! PDO
         $PDO = $this->PDO;
         // ?
         if ($PDO === null) {
            return null;
         }

         return $PDO->rollBack();
      }
      catch (PDOException $PDOException) {
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
