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


use const SIGKILL;
use const SIGTERM;
use const WNOHANG;
use function array_search;
use function count;
use function pcntl_waitpid;
use function posix_kill;
use function usleep;


class Children
{
   // * Data
   /** @var array<int,int> Slot index => PID of the worker serving that slot. */
   public protected(set) array $PIDs = [];
   /**
    * Retiring children: replaced in their slot (a new child already serves
    * it) while they finish their in-flight work. Still owned — reaped,
    * signalled and terminated with the serving ones — never counted as a slot.
    *
    * @var array<int,int> PID => slot index.
    */
   public protected(set) array $retirees = [];


   public function push (int $PID, null|int $index = null): void
   {
      if ($index !== null) {
         $this->PIDs[$index] = $PID;

         return;
      }

      $this->PIDs[] = $PID;
   }
   /**
    * Retire a serving child: its slot is freed for a replacement while the
    * child itself stays owned (in `retirees`) until it exits.
    *
    * @return bool Whether `$PID` was a serving child.
    */
   public function retire (int $PID): bool
   {
      // ?
      $index = array_search($PID, $this->PIDs, true);
      if ($index === false) {
         return false;
      }

      // @
      unset($this->PIDs[$index]);
      $this->retirees[$PID] = $index;

      // :
      return true;
   }
   public function remove (int $PID): void
   {
      $index = array_search($PID, $this->PIDs, true);

      if ($index !== false) {
         unset($this->PIDs[$index]);
      }

      unset($this->retirees[$PID]);
   }
   /**
    * Count the serving children — one per slot; retirees are not counted.
    */
   public function count (): int
   {
      return count($this->PIDs);
   }
   public function terminate (int $timeout = 5, int $signal = SIGTERM): void
   {
      // ! Every owned child: the serving ones and the retirees
      /** @var array<int,int> $PIDs PID => PID */
      $PIDs = [];
      foreach ($this->PIDs as $PID) {
         $PIDs[$PID] = $PID;
      }
      foreach ($this->retirees as $PID => $index) {
         $PIDs[$PID] = $PID;
      }

      // @ Send the stop signal to all children (SIGTERM = fast; SIGQUIT = graceful drain)
      foreach ($PIDs as $PID) {
         posix_kill($PID, $signal);
      }

      // @ Wait for children to exit gracefully
      $elapsed = 0;
      while ($elapsed < $timeout && $PIDs !== []) {
         usleep(50000); // 50ms
         $elapsed += 0.05;

         foreach ($PIDs as $PID) {
            $result = pcntl_waitpid($PID, $status, WNOHANG);

            if ($result > 0) {
               unset($PIDs[$PID]);
               $this->remove($PID);
            }
            else if ($result === -1 && posix_kill($PID, 0) === false) {
               // ECHILD is not itself proof of death; remove only when the
               // process no longer exists. With the corrected topology every
               // live tracked worker remains a real child of this master.
               unset($PIDs[$PID]);
               $this->remove($PID);
            }
         }
      }

      // @ Force kill remaining children
      foreach ($PIDs as $PID) {
         posix_kill($PID, SIGKILL);
         pcntl_waitpid($PID, $status);

         $this->remove($PID);
      }
   }
}
