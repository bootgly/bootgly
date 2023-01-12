<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\_;

use Bootgly\Web\HTTP\Server\_\Meta\Authentication;

class Meta
{
   // * Data
   private string $method;
   private string $uri;
   private string $protocol;
   // * Meta
   private string $raw;
   // ? Resource
   // @ URI
   // @ URL
   // @ URN
   // @ Path
   // @ Query

   public Authentication $Authentication;


   public function __construct (Authentication $Authentication)
   {
      // * Data


      $this->Authentication = $Authentication;
   }
}
