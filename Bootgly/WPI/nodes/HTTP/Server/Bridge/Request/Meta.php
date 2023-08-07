<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\nodes\HTTP\Server\Bridge\Request;


class Meta
{
   // * Config
   // ...

   // * Data
   public string $raw;

   public string $method;
   public string $uri; // @ Resource
   public string $protocol;

   // * Meta
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
      $this->uri = @$_SERVER['REDIRECT_URI'] ?? $_SERVER['REQUEST_URI'];
      $this->protocol = $_SERVER['SERVER_PROTOCOL'];

      // * Meta
      $this->length = null;
   }
}
