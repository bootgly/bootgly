<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Progress;


use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\components\Progress;


class Bar
{
   private Progress $Progress;

   // * Config
   public int $units;

   // * Data
   public Bar\Symbols $Symbols;

   // * Meta
   // @ State
   // indetermined
   public bool $completing;
   public bool $emptying;


   public function __construct (Progress $Progress)
   {
      $this->Progress = $Progress;


      // * Config
      $this->units = Terminal::$width / 2;

      // * Data
      $this->Symbols = new Bar\Symbols;

      // * Meta
      // @ State
      // indetermined
      $this->completing = true;
      $this->emptying = false;
   }
   public function __get ($name)
   {
      return $this->Progress->$name;
   }

   public function render () : string
   {
      $units = $this->units;

      // done
      $done = (int)($units * ($this->Progress->percent / 100));
      if ($done > $units) {
         $done = $units;
      }
      // left
      $left = (int)($units - $done);

      // ? Determined state
      // @ Construct symbols
      $Symbols = $this->Symbols;
      $symbols = [];
      // complete
      $complete = $Symbols->complete;
      for ($i = 0; $i < $done; $i++) {
         $symbols[] = $complete;
      }
      // current
      $current = $Symbols->current;
      if ($current) {
         $symbols[] = $current;
      }
      // incomplete
      $incomplete = $Symbols->incomplete;
      for ($i = 0; $i < $left; $i++) {
         $symbols[] = $incomplete;
      }

      // ? Indetermined state
      // @ Construct symbols
      if ($this->Progress->indetermined) {
         if ($this->completing) {
            for ($i = 0; $i <= $units - 1; $i++) {
               if ($done > $i + 5) {
                  $symbols[$i] = $incomplete;
               }
            }
         }

         if ($this->emptying) {
            $loops = $units / 2;

            for ($i = 0; $i < $units; $i++) {
               if ($i <= $loops + $done) {
                  $symbols[$i] = $incomplete;
                  continue;
               }

               $symbols[$i] = $complete;
            }

            if ($done === $loops - 1) {
               $this->redirect(10.0);
            }
         }

         if ($this->Progress->percent >= 100) {
            $this->redirect();
         }
      }

      $bar = implode('', $symbols);

      return $bar;
   }

   private function redirect (float $value = 0.0)
   {
      $this->Progress->percent = $value;

      $this->emptying = ! $this->emptying;
      $this->completing = ! $this->completing;
   }
}



namespace Bootgly\CLI\Terminal\components\Progress\Bar;

class Symbols
{
   public string $incomplete   = ' ';
   public string $current      = '>';
   public string $complete     = '=';
}
