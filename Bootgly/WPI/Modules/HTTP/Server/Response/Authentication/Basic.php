<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
