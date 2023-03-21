<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Debugger;


class Backtrace
{
   public array $backtraces;
   private array $trace;


   public function __construct (int $limit = 1)
   {
      $backtraces = $this->backtraces = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 1);

      $this->trace = $backtraces[$limit];
   }
   public function __get (string $name)
   {
      switch ($name) {
         case 'dir':
            return dirname($this->trace['file']);
         default:
            return $this->trace[$name];
      }
   }
}
