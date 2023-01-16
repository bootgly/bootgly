<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Bootgly\CLI\{
   Command
};


class CLI
{
   // * Data
   // * Meta

   public Command $Command;


   public function __construct ()
   {
      if (@$_SERVER['TERM'] === NULL) {
         return;
      }

      $this->Command = new Command($this);

      // @ Load CLI constructor
      @include Bootgly::$Project::PROJECT_DIR . 'cli.constructor.php';
   }
}
