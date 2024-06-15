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


class Route
{
   public const START_PARAM = ':';

   // * Config
   private ? string $name;

   // * Data
   protected string $path;
   protected object $Params;

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
      $this->Params = new class // TODO move to class
      {
         public function __get ($name)
         {
            return $this->$name ?? null;
         }
         public function __set ($param, $regex)
         {
            @$this->$param = $regex;
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
   public function __get ($name)
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
   public function __set (string $name, $value)
   {
      switch ($name) {
         // * Data
         case 'path':
            $this->path = $value;
            break;

         // * Metadata
         case 'base':
         case 'prefix': // TODO refactor
            Router::$Server::$Request->base = $value;
            break;

         default:
            $this->$name = $value;
      }
   }
}
