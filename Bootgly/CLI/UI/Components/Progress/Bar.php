<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Progress;


use function implode;

use Bootgly\CLI\Terminal;
use Bootgly\CLI\UI\Components\Progress;


class Bar
{
   private Progress $Progress;

   // * Config
   public int $units;

   // * Data
   public Symbols $Symbols;
   // # Track (multi-bar): total > 0 makes this Bar an independent track
   public float $current;
   public float $total;
   public string $description;

   // * Metadata
   // @ State
   // indetermined
   public bool $completing;
   public bool $emptying;
   // # Track
   public private(set) float $percent;


   public function __construct (Progress $Progress)
   {
      $this->Progress = $Progress;


      // * Config
      $this->units = Terminal::$width / 2;

      // * Data
      $this->Symbols = new Symbols;
      // # Track
      $this->current = 0.0;
      $this->total = 0.0;
      $this->description = '';

      // * Metadata
      // @ State
      // indetermined
      $this->completing = true;
      $this->emptying = false;
      // # Track
      $this->percent = 0.0;
   }
   public function __get (string $name): mixed
   {
      return $this->Progress->$name;
   }

   /**
    * Advances this track (multi-bar mode — requires `total` > 0).
    *
    * @param float $amount The amount to advance.
    *
    * @return self
    */
   public function advance (float $amount = 1.0): self
   {
      // ?
      if ($this->total <= 0.0) {
         return $this;
      }

      $this->current += $amount;

      if ($this->current > $this->total) {
         $this->current = $this->total;
      }

      $this->percent = ($this->current / $this->total) * 100;

      // :
      return $this;
   }

   public function render (): string
   {
      $units = $this->units;

      // ? Independent tracks derive the fill from their own percent
      $percent = $this->total > 0.0 ? $this->percent : $this->Progress->percent;

      // done
      $done = (int)($units * ($percent / 100));
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

   private function redirect (float $value = 0.0): void
   {
      $this->Progress->percent = $value;

      $this->emptying = ! $this->emptying;
      $this->completing = ! $this->completing;
   }
}


// * Configs
class Symbols
{
   public string $incomplete   = ' ';
   public string $current      = '>';
   public string $complete     = '=';
}
