<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web;


use const Bootgly\HOME_DIR;
use Bootgly\{
   Debugger,
   Path,
   Web
};


class App
{
   public Web $Web;

   // * Config
   // Dynamic
   public string $indexer1 = 'index.php';
   // Static
   public string $indexer2 = 'index.html';

   public string $pathbase = ''; // Request->paths[0] | $pathbase

   public string $template = '';
   // * Meta


   public function __construct (Web $Web)
   {
      $this->Web = $Web;
   }
   // public function preload () {}
   public function load () 
   {
      // $this->preload();

      switch ($this->template) {
         case 'spa':
         case 'static':
            if ($this->Web->Request->path == $this->pathbase) {
               readfile($this->Web->Bootgly->Project->path . 'index.html');
            } else {
               if ($this->pathbase) {
                  $this->Web->Router->Route->prefix = $this->pathbase;
               }

               $Static = new \Bootgly\File($this->Web->Bootgly->Project->path . $this->Web->Request->path);
               // TODO save and get list of all files in project->path and compare here to optimize performance
               if ($Static->File) {
                  header('Content-Type: '. $Static->type);
                  $Static->read();
               } else {
                  readfile($this->Web->Bootgly->Project->path . 'index.html');
               }
            }

            break;
         default:
            $Web = &$this->Web;

            if ( is_file($this->Web->Bootgly->Project->path . 'index.php') ) {
               require_once $this->Web->Bootgly->Project->path . 'index.php';
            } else if ( is_file($this->Web->Bootgly->Project->path . 'app.constructor.php') ) {
               require_once $this->Web->Bootgly->Project->path . 'app.constructor.php';
            }
      }
   }
}
