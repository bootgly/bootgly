<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Web;


use Bootgly;
use Web;


abstract class API
{
   public Web $Web;

   // * Config
   public bool $debugger;

   // * Data
   // ...

   // * Meta
   // ...

   public function boot ()
   {
      $Web = &$this->Web;

      if ( is_file(Bootgly::$Project . 'index.php') ) {
         require_once Bootgly::$Project . 'index.php';
      }
   }
   abstract public function debug ($data, string $password = '');
   abstract public function respond ();
}
