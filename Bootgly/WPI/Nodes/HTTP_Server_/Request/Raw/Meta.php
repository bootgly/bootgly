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
   // @ Raw
   public string $method;
   public string $URI; // @ Resource
   public string $protocol;
   public string $raw;

   // * Metadata
   // @ Raw
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
      $this->method = $_SERVER['REQUEST_METHOD'] ?? '';
      $this->URI = @$_SERVER['REDIRECT_URI'] ?? $_SERVER['REQUEST_URI'] ?? '';
      $this->protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';

      $this->raw = <<<RAW
      {$this->method} {$this->URI} {$this->protocol}
      RAW;

      // * Metadata
      $this->length = \strlen($this->raw);
   }
}
