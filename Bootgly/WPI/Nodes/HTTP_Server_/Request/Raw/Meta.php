<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw;


class Meta
{
   // * Config
   // ...

   // * Data
   public string $raw;

   public string $method;
   public string $URI; // @ Resource
   public string $protocol;

   // * Metadata
   public ? int $length;
   // ? Resource
   // @ URI
   // @ URL
   // @ URN
   // @ Path
   // @ Query


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->raw = '';

      $this->method = $_SERVER['REQUEST_METHOD'];
      $this->URI = @$_SERVER['REDIRECT_URI'] ?? $_SERVER['REQUEST_URI'];
      $this->protocol = $_SERVER['SERVER_PROTOCOL'];

      // * Metadata
      $this->length = null;
   }
}
