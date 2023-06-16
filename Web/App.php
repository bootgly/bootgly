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


use Bootgly\ABI\streams\File;
use Bootgly\Web;


class App
{
   public \Web $Web;

   // * Config
   // Dynamic
   public string $indexer1 = 'index.php';
   // Static
   public string $indexer2 = 'index.html';

   public string $pathbase = ''; // Request->paths[0] | $pathbase

   public string $template = '';
   // * Data
   // ...

   // * Meta
   // ...


   public function __construct ()
   {
      $Web = $this->Web = new \Web;
      // ---
      $Web->App = $this;
      // ---
      // TODO TEMP
      $Web->Request = Web::$Request;
      $Web->Response = Web::$Response;
      $Web->Router = Web::$Router;

      $Web->Response->use('App', $this);
      $Web->Response->use('Web', $Web);
   }
   public function boot ()
   {
      switch ($this->template) {
         case 'spa':
         case 'static':
            if (Web::$Request->path == $this->pathbase) {
               readfile(\Bootgly::$Project->path . 'index.html');
            } else {
               if ($this->pathbase) {
                  Web::$Router->Route->prefix = $this->pathbase;
               }

               $Static = new File(\Bootgly::$Project->path . Web::$Request->path);
               // TODO save and get list of all files in project->path and compare here to optimize performance
               if ($Static->File) {
                  header('Content-Type: '. $Static->type);
                  $Static->read();
               } else {
                  readfile(\Bootgly::$Project->path . 'index.html');
               }
            }

            break;
         default:
            $Web = &$this->Web;

            $Router = Web::$Router;

            if ( is_file(\Bootgly::$Project->path . 'index.php') ) {
               require_once \Bootgly::$Project->path . 'index.php';
            } else if ( is_file(\Bootgly::$Project->path . 'App.boot.php') ) {
               require_once \Bootgly::$Project->path . 'App.boot.php';
            }
      }
   }
}
