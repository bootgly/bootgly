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


class Frontend extends App
{
   public Web $Web;

   // * Config
   public const INDEXER = 'index.html';
   public string $pathbase = ''; // Request->paths[0] | $pathbase

   // * Data
   // ...

   // * Meta
   // ...


   public function boot () // TODO REFACTOR to Work with SAPI CLI too
   {
      if (WPI::$Request->path == $this->pathbase) {
         readfile(Bootgly::$Project->path . self::INDEXER);
      } else {
         if ($this->pathbase) {
            WPI::$Router->Route->prefix = $this->pathbase;
         }

         $Static = new File(Bootgly::$Project->path . WPI::$Request->path);

         if ($Static->File) {
            header('Content-Type: ' . $Static->type);
            $Static->read();
         } else {
            readfile(Bootgly::$Project->path . self::INDEXER);
         }
      }
   }
}
