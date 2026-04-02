<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Process;


use function pcntl_signal;
use function pcntl_signal_dispatch;
use function posix_kill;
use function usleep;
use Closure;

use Bootgly\ACI\Process;


class Signals
{
   private Process $Process;

   // * Config
   public Closure $handler;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (Process &$Process)
   {
      $this->Process = $Process;
   }

   /**
    * @param array<int> $signals
    */
   public function install (array $signals): void
   {
      $callback = function (int $signal): void {
         ($this->handler)($signal);
      };

      foreach ($signals as $signal) {
         pcntl_signal($signal, $callback, false);
      }
   }
   public function send (
      int $signal, bool $master = true, bool $children = true
   ): bool
   {
      if ($master) {
         posix_kill(Process::$master, $signal);

         if ($children === false) {
            pcntl_signal_dispatch();
         }
      }

      if ($children) {
         foreach ($this->Process->Children->PIDs as $id) {
            posix_kill($id, $signal);
            usleep(100000); // 0.1s
         }
      }

      return true;
   }
   public function dispatch (): void
   {
      pcntl_signal_dispatch();
   }
}
