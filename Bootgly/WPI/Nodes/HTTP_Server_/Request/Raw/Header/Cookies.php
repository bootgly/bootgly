<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Header;

use Bootgly\WPI\Modules\HTTP\Server\Request\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Header;


final class Cookies extends Raw\Header\Cookies
{
   // * Config
   // ...

   // * Data
   // ... inherited

   // * Metadata
   // ...


   public function __construct (Header $Header)
   {
      $this->Header = $Header;


      // * Config
      // ...

      // * Data
      $this->cookies = $_COOKIE;

      // * Metadata
      // ...
   }

   public function __get (string $name): mixed
   {
      switch ($name) {
         // * Config
         // ...

         // * Data
         case 'cookies':
            return $this->cookies;

         // * Metadata
         // ...
      }

      return null;
   }
}
