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
   /** @var array<int> */
   public protected(set) array $PIDs = [];


   public function push (int $PID, null|int $index = null): void
   {
      if ($index !== null) {
         $this->PIDs[$index] = $PID;

         return;
      }

      $this->PIDs[] = $PID;
   }
   public function remove (int $PID): void
   {
      $index = array_search($PID, $this->PIDs, true);

      if ($index !== false) {
         unset($this->PIDs[$index]);
      }
   }
   public function count (): int
   {
      return count($this->PIDs);
   }
   public function terminate (int $timeout = 5, int $signal = SIGTERM): void
   {
      // @ Send the stop signal to all children (SIGTERM = fast; SIGQUIT = graceful drain)
      foreach ($this->PIDs as $PID) {
         posix_kill($PID, $signal);
      }

      // @ Wait for children to exit gracefully
      $elapsed = 0;
      while ($elapsed < $timeout && $this->PIDs !== []) {
         usleep(50000); // 50ms
         $elapsed += 0.05;

         foreach ($this->PIDs as $index => $PID) {
            $result = pcntl_waitpid($PID, $status, WNOHANG);

            if ($result > 0 || $result === -1) {
               unset($this->PIDs[$index]);
            }
         }
      }

      // @ Force kill remaining children
      foreach ($this->PIDs as $index => $PID) {
         posix_kill($PID, SIGKILL);

         unset($this->PIDs[$index]);
      }
   }
}
