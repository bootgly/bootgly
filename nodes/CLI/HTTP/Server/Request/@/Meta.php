<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\HTTP\Server\Request\_;


use Bootgly\CLI\HTTP\Server\Request\_\Meta\Authentication;


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

   #public Authentication $Authentication;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->raw = '';

      $this->method = '';
      $this->uri = '';
      $this->protocol = '';

      // * Meta
      $this->length = null;


      #$this->Authentication = $Authentication;
   }
}
