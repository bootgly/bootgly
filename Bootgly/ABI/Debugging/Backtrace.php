<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging;


class Backtrace
{
   // * Config
   // ...

   // * Data
   public array $calls;

   // * Meta
   private array $trace;


   public function __construct (int $limit = 0)
   {
      $calls = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);

      // * Config
      // ...

      // * Data
      $this->calls = $calls;

      // * Meta
      $this->trace = $calls[$limit];
   }
   public function __get (string $name)
   {
      switch ($name) {
         case 'dir':
            return \dirname($this->trace['file']);
         default:
            return $this->trace[$name];
      }
   }
}
