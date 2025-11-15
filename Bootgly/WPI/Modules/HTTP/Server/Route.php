<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server;


use ArrayIterator;
use IteratorAggregate;
use Traversable;
use function array_key_exists;

use const Bootgly\WPI;


class Route
{
   public const START_PARAM = ':';

   // * Config
   private string|null $name;
   public string $path;
   /** @var IteratorAggregate<string,string|int|array<int>> */
   public object $Params;

   // * Data
   public string $base {
      get {
         $WPI = WPI;
         return $WPI->Request->base;
      }
      set (string $value) {
         $WPI = WPI;
         $WPI->Request->base = $value;
      }
   }

   // * Metadata
   private bool $parameterized;
   // ! Parse
   public string $parsed;
   public string $catched; // Group of (.*) Catch-All Param
   // private string $node;
   public int $nodes; // Nodes parsed
   // ! Group
   private bool $nested;
   // ! Log
   public static int $level; // Route group level | after callback


   public function __construct ()
   {
      // * Config
      $this->name = null;

      // * Data
      $this->path = '';
      // TODO deny user to set Catch-All this object
      // TODO validate Param value (Regex)
      // TODO validate Param name
      $this->Params = new class implements IteratorAggregate // TODO move to class
      {
         /** @var array<string,string|int|array<int>|null> */
         private array $params = [];

         /** @return string|int|array<int>|null */
         public function &__get (string $name)
         {
            if (array_key_exists($name, $this->params) === false) {
               $this->params[$name] = null;
            }

            return $this->params[$name];
         }
         /**
          * @param string $param
          * @param string|int|array<int> $value
         */
         public function __set (string $param, string|int|array $value): void
         {
            $this->params[$param] = $value;
         }

         public function getIterator (): Traversable
         {
            return new ArrayIterator($this->params);
         }
      };

      // * Metadata
      $this->parameterized = false;
      // ! Parse
      $this->parsed = '';
      $this->catched = '';
      // $this->node = '';
      $this->nodes = 0;
      // ! Group
      $this->nested = false;
      // ! Log
      self::$level = 0;
   }
   public function __get (string $name): mixed
   {
      // * Metadata
      switch ($name) {
         case 'parameterized':
            $parameterized = false;

            if (\strpos($this->path, self::START_PARAM) !== false) {
               $parameterized = true;
            }

            return $this->parameterized = $parameterized;

         case 'node':
            $node = \strstr($this->path, ':');

            return $node;

         default:
            return $this->$name;
      }
   }
   public function __set (string $name, mixed $value): void
   {
      switch ($name) {
         // * Data
         default:
            $this->$name = $value;
      }
   }
}
