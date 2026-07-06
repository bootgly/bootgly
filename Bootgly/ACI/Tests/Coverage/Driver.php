<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage;


/**
 * Abstract coverage backend.
 *
 * collect() returns a normalized hit map:
 *   [file_path => [line_number => hits]]
 */
abstract class Driver
{
   /**
    * Whether the backend is currently recording hits.
    */
   public private(set) bool $running = false;


   /**
    * Begin recording line execution.
    */
   public function start (): void
   {
      $this->begin();
      $this->running = true;
   }

   /**
    * Stop recording.
    */
   public function stop (): void
   {
      if (! $this->running) {
         return;
      }

      try {
         $this->end();
      }
      finally {
         $this->running = false;
      }
   }

   /**
    * Read recorded hits.
    *
    * @return array<string, array<int, int>>
    */
   abstract public function collect (): array;

   /**
    * Backend-specific start hook.
    */
   protected function begin (): void
   {
      // ...
   }

   /**
    * Backend-specific stop hook.
    */
   protected function end (): void
   {
      // ...
   }
}
