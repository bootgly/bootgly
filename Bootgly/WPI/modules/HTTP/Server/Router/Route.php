<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\modules\HTTP\Server\Router;


use Bootgly\WPI\modules\HTTP\Server\Router;


final class Route
{
   public Router $Router;

   // * Config
   private ? string $name;

   // * Data
   private object $Params;
   // private object $Path;

   // * Meta
   private string $path;
   public int $matched; // 0 -> none; 1 = route path; 2 = route path and route condition(s)
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


   public function __construct (Router $Router)
   {
      $this->Router = $Router;


      // * Config
      $this->name = null;

      // * Data
      // TODO deny user to set Catch-All this object
      // TODO validate Param value (Regex)
      // TODO validate Param name
      $this->Params = new class
      {
         public function __get ($name)
         {
            return $this->$name ?? null;
         }
         public function __set ($param, $regex)
         {
            $this->$param = $regex;
         }
      };

      // * Meta
      $this->path = '';
      $this->matched = 0;
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
      switch ($name) {
         case 'parameterized':
            if (strpos($this->path, ':') !== false) {
               return $this->parameterized = true;
            } else {
               return $this->parameterized = false;
            }
         case 'node':
            $node = strstr($this->path, ':');
            return $node;
         default:
            return $this->$name;
      }
   }
   public function __set (string $name, $value)
   {
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
         $this->path = rtrim($path, '/');
      }
   }
}
