<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Process;


use function array_keys;
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
    * Send a signal to the master and/or every child — the children that
    * serve a slot and the retirees still finishing their work
    * (`Children->retirees`): both are processes of this one, so commands,
    * log routing and stats reach them alike; a signal that only means
    * something to a serving child (a pause, a certificate swap) is theirs to
    * ignore. Returns true only when EVERY `posix_kill` to the master and the
    * serving children succeeded — a dead/forbidden target makes the whole
    * dispatch report failure (callers relying on delivery must know). A
    * retiree may exit at any moment: its delivery is best-effort, never
    * fails the dispatch and is never spaced out — the 0.1 s between serving
    * children gives each its turn at the command slot, which a retiree only
    * reads best-effort; the caller (a master loop) is not stalled for it.
    */
   public function send (
      int $signal, bool $master = true, bool $children = true
   ): bool
   {
      $sent = true;

      if ($master) {
         if (posix_kill($this->Process->master, $signal) === false) {
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
         // @@ The retirees — best-effort, at once
         foreach (array_keys($this->Process->Children->retirees) as $id) {
            posix_kill($id, $signal);
         }
      }

      return $sent;
   }
   public function dispatch (): void
   {
      pcntl_signal_dispatch();
   }
}
