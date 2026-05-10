<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;


use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;


class Basic implements Authentication
{
   // * Config
   // ...

   // * Data
   /**
    * Authentication protection space advertised to the client.
    */
   public string $realm;

   // * Metadata
   // ...


   /**
    * Configure a Basic `WWW-Authenticate` challenge descriptor.
    */
   public function __construct (string $realm = 'Protected area')
   {
      // * Data
      $this->realm = $realm;
   }
}
