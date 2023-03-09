<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal;


use Bootgly\CLI;


class Input
{
   // * Data
   public $stream;


   public function __construct ($stream = STDIN)
   {
      // * Data
      $this->stream = $stream;
   }

   public function read (int $length)
   {
      return fread($this->stream, $length);
   }
}
