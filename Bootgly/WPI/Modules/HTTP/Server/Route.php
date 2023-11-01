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
   // * Config
   private ? string $name;

   // * Data
   private string $path;
   private object $Params;

   // * Meta
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

      // * Meta
      $this->parameterized = false;
      // ! Parse
      $this->parsed = '';
      $this->catched = false;
      // $this->node = '';
      $this->nodes = 0;
      // ! Group
      $this->nested = false;
      // ! Log
      self::$level = 0;
   }
   public function __get ($name)
   {
      // * Meta
      switch ($name) {
         case 'parameterized':
            if (\strpos($this->path, ':') !== false) {
               return $this->parameterized = true;
            } else {
               return $this->parameterized = false;
            }
         case 'node':
            $node = \strstr($this->path, ':');
            return $node;
         default:
            return $this->$name;
      }
   }
   public function __set (string $name, $value)
   {
      // * Meta
      switch ($name) {
         case 'base':
         case 'prefix': // TODO refactor
            Router::$Server::$Request->base = $value;

            break;

         default:
            $this->$name = $value;
      }
   }

   public function set (string $path) : void
   {
      if ($path === '/') {
         $this->path = '';
      } else {
         $this->path = \rtrim($path, '/');
      }
   }
}
