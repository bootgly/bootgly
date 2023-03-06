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
   Command,
   Terminal
};


class CLI
{
   // * Data
   // * Meta
   // ! Escaping
   public const _START_ESCAPE = "\033[";

   public Command $Command;
   public Terminal $Terminal;


   public function __construct ()
   {
      if (PHP_SAPI !== 'cli') {
         return;
      }

      $Command = $this->Command = new Command($this);
      $Terminal = $this->Terminal = new Terminal($this);

      // @ Load CLI constructor
      @include Bootgly::$Project::PROJECT_DIR . 'cli.constructor.php';
   }
}
