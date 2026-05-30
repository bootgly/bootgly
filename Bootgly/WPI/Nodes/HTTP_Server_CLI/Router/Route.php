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


   public function __construct ()
   {
      $this->Params = new Params;

      // * Config
      #$this->name = null;

      // * Data
      $this->path = '';
   }
}
