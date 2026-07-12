<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
   /**
    * Send a signal to the master and/or every child. Returns true only when
    * EVERY `posix_kill` succeeded — a dead/forbidden target makes the whole
    * dispatch report failure (callers relying on delivery must know).
    */
   public function send (
      int $signal, bool $master = true, bool $children = true
   ): bool
   {
      $sent = true;

      if ($master) {
         if (posix_kill(Process::$master, $signal) === false) {
            $sent = false;
         }

         if ($children === false) {
            pcntl_signal_dispatch();
         }
      }

      if ($children) {
         foreach ($this->Process->Children->PIDs as $id) {
            if (posix_kill($id, $signal) === false) {
               $sent = false;
            }
            usleep(100000); // 0.1s
         }
      }

      return $sent;
   }
   public function dispatch (): void
   {
      pcntl_signal_dispatch();
   }
}
