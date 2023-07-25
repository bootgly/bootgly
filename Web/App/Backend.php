<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Web\App;


use Bootgly;
use Bootgly\ABI\streams\File;
use Bootgly\WPI;

use Web;
use Web\App;


class Backend extends App
{
   public Web $Web;

   // * Config
   public const INDEXER = 'index.php';

   // * Data
   // ...

   // * Meta
   // ...


   public function boot ()
   {
      $Web = &$this->Web;

      $Router = WPI::$Router;

      if ( is_file(Bootgly::$Project->path . self::INDEXER) ) {
         require_once(Bootgly::$Project->path . self::INDEXER);
      }
   }
}
