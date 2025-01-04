<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI\Process;


use function posix_kill;


class Children
{
   // * Data
   /** @var array<int> */
   public protected(set) array $PIDs = [];


   public function push (null|int $index = null, int $PID): void
   {
      if ($index !== null) {
         $this->PIDs[$index] = $PID;

         return;
      }

      $this->PIDs[] = $PID;
   }
   public function kill (): void
   {
      foreach ($this->PIDs as $index => $PID) {
         posix_kill($PID, SIGKILL);

         unset($this->PIDs[$index]);
      }
   }
}
