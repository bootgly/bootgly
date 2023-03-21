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


use Bootgly\{
   __String,
   Web
};
use Exception;


// TODO Refactor class (too old)
class Database
{
   public Bootgly $Bootgly;

   // * Config
   public $debug = true;
   // * Data
   public $driver = 'pgsql';

   public $host = 'localhost';

   public $user = '';
   public $password = '';

   public $db = '';

   protected $charset = 'UTF8';
   protected $collate = 'utf8_unicode_ci';
   // PDO
   public $PDO;
   public $query;

   public $PDOException = null;

   //
   private $rows;

   public function __construct (Bootgly $Bootgly)
   {
      $this->Bootgly = &$Bootgly;

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
         } else {
            /*
            $Error = new Error;
            $Error->title = 'Database -> prepare()';
            $Error->fatal = true;
            $Error->message = 'Database not connected!';
            $Error->throw();
            */
         }
      } catch (\PDOException $e) {
         $this->PDOException = $e->getMessage();
      }
   }


   public function config (string $instance)
   {
      // TODO validate / parse $instance
      try {
         $configFile = Bootgly::$Project . '/database/' . $instance . '.config.php';
         if ( is_file($configFile) ) {
            require $configFile;
         } else {
            throw new Exception('Database configuration file not found.', 1);
         }
      } catch (Exception $Exception) {
         /*
         $Error = new Error;
         $Error->title = 'Database -> config()';

         $Error->fatal = true;
         $Error->code = $Exception->getCode();
         $Error->message = $Exception->getMessage();

         $Error->throw();
         */
      }
   }

   public function connect ()
   {
      try {
         if (@$this->PDO === null) {
            $this->PDO = new \PDO("$this->driver:host=$this->host;dbname=$this->db;", $this->user, $this->password);

            $this->PDO->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
            $this->PDO->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->PDO->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            // $this->PDO->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
         }

         return true;
      } catch (\PDOException $e) {
         // $code = $e->getCode();

         /*
         $Error = new Error;
         $Error->title = 'Database -> connect()';

         switch ($code) {
            case 1045:
               $Error->fatal = true;
               $Error->code = 1045;
               $Error->message = 'Incorrect user or password.';
               break;
            default:
               $Error->fatal = true;
               $Error->code = $code;
               $Error->message = $e->getMessage();
         }

         $Error->throw();
         */
      }
   }

   public function use (string $database = '') {
      try {
         if ($database === '') {
            $database = $this->db;
         }

         if (@$this->PDO) {
            // TODO validate database name
            // TODO parameterized query
            $this->PDO->exec('use `'. $database . '`;');

            return TRUE;
         }
      } catch (\PDOException $e) {
         // $code = $e->getCode();

         /*
         $Error = new Error;
         $Error->title = 'Database -> use()';
         $Error->fatal = true;
         $Error->code = $code;
         $Error->message = $e->getMessage();

         $Error->throw();
         */
      }
   }
   private function prepare ($query)
   {
      try {
         if ($this->driver === 'pgsql') {
            $query = __String::replace('`', '"', $query);
         }

         $this->query = $this->PDO->prepare($query);

         return $this->query;
      } catch (\PDOException $e) {
         $this->PDOException = $e->getMessage();

         /*
         $code = $e->getCode();

         $Error = new Error;
         $Error->title = 'Database -> prepare()';
         $Error->fatal = true;
         $Error->code = $code;
         $Error->message = $e->getMessage();

         $Error->throw();
         */
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
