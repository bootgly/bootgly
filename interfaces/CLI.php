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
      if ($_SERVER['_'] === NULL) {
         return;
      }

      $this->Command = new Command($this);

      @include $Project::PROJECT_DIR . 'cli.constructor.php';
   }
}
