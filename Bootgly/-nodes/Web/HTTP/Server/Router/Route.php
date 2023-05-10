<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\Router;


use Bootgly\Web\HTTP\Server;
use Bootgly\Web\HTTP\Server\Router;

use function Bootgly\__String;


final class Route
{
   public Router $Router;

   // * Config
   private ?string $name;
   public bool $status;

   // * Data
   private object $Params;
   // private object $Path;

   // * Meta
   private string $path;
   public int $matched; // 0 -> none; 1 = route path; 2 = route path and route conditions
   private bool $parameterized;

   // ! Parse
   public string $parsed;
   public string $catched; // Group of (.*) Catch-All Param
   // private string $node;
   public int $nodes; // Nodes parsed
   // ! Group
   private bool $nested;
   // ! Log
   public int $index; // Route index invoked | before callback
   public array $routed; // Route path and parsed | after callback
   public int $level; // Route group level | after callback


   public function __construct (Router $Router)
   {
      $this->Router = $Router;


      // * Config
      $this->name = null;
      $this->status = true;

      // * Data
      // TODO deny user to set Catch-All this object
      // TODO validate Param value (Regex)
      // TODO validate Param name
      $this->Params = new class
      {
         public function __get($name)
         {
            return $this->$name ?? null;
         }
         public function __set($param, $regex)
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
      $this->index = 0;
      $this->routed = [];
      $this->level = 0;
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
            return __String($this->path)->separateBefore(':');
         default:
            return $this->$name;
      }
   }
   public function __set (string $name, $value)
   {
      switch ($name) {
         case 'path':
            if ($value === '/') {
               $this->path = '';
            } else {
               $this->path = rtrim($value, '/');
            }

            break;

         case 'base':
         case 'prefix': // TODO refactor
            Server::$Request->base = $value;

            break;

         default:
            $this->$name = $value;
      }
   }
}
