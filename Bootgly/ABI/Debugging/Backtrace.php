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
   public static int $options = \DEBUG_BACKTRACE_IGNORE_ARGS;

   // * Data
   public array $calls;

   // * Meta
   // @ Last
   private array $trace;


   public function __construct (int $limit = 0)
   {
      // ?
      if ($limit < 0) {
         return;
      }
      // <
      $calls = \debug_backtrace(self::$options, $limit);
      unSet($calls[0]);
      $calls = \array_values($calls);
      // * Config
      // ...

      // * Data
      $this->calls = $calls;

      // * Meta
      // @ Last
      foreach ($calls as $call) {
         $this->trace = $call; break;
      }
   }
   public function __get (string $name)
   {
      // * Meta
      // @ Last
      switch ($name) {
         case 'dir':
            return \dirname($this->trace['file']);
         default:
            return $this->trace[$name];
      }
   }
}
