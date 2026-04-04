<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


use function strpos;
use function strstr;

use const Bootgly\WPI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Route\Params;


class Route
{
   public const string START_PARAM = ':';

   // * Config
   #private string|null $name; // TODO: Use name for route groups and named routes
   public string $path;
   public Params $Params;

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
   public bool $parameterized {
      get {
         return strpos($this->path, self::START_PARAM) !== false;
      }
   }
   // # Parse
   public string $parsed;
   public string $catched; // Group of (.*) Catch-All Param
   public string $catchParam; // Named catch-all param name
   public string $node {
      get {
         return strstr($this->path, ':') ?: '';
      }
   }
   public int $nodes; // Nodes parsed
   // # Group
   public bool $nested;
   // # Log
   public static int $level; // Route group level | after callback


   public function __construct ()
   {
      $this->Params = new Params;


      // * Config
      #$this->name = null;

      // * Data
      $this->path = '';

      // * Metadata
      // # Parse
      $this->parsed = '';
      $this->catched = '';
      $this->catchParam = '';
      $this->nodes = 0;
      // # Group
      $this->nested = false;
      // # Log
      self::$level = 0;
   }
}
