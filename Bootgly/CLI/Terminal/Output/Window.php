<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Output;


use function min;


/**
 * Visible-window calculator for scrollable lists.
 * Pure state — no stream I/O: consumers slide the window and slice their rows.
 */
class Window
{
   // * Config
   /** Visible rows (0 disables windowing) */
   public int $size;

   // * Data
   /** Total rows */
   public int $total;

   // * Metadata
   public private(set) int $first;
   public int $last {
      get => min($this->first + $this->size, $this->total) - 1;
   }


   public function __construct (int $size = 0, int $total = 0)
   {
      // * Config
      $this->size = $size;

      // * Data
      $this->total = $total;

      // * Metadata
      $this->first = 0;
   }

   /**
    * Slides the window so the aimed row stays visible.
    *
    * @param int $aimed The aimed row index.
    *
    * @return self
    */
   public function slide (int $aimed): self
   {
      // ? Windowing disabled or everything fits
      if ($this->size < 1 || $this->total <= $this->size) {
         $this->first = 0;

         // :
         return $this;
      }

      // @ Keep the aimed row inside [first, last]
      if ($aimed < $this->first) {
         $this->first = $aimed;
      }
      else if ($aimed > $this->first + $this->size - 1) {
         $this->first = $aimed - $this->size + 1;
      }

      // @ Clamp to the valid range
      $max = $this->total - $this->size;
      if ($this->first > $max) {
         $this->first = $max;
      }
      if ($this->first < 0) {
         $this->first = 0;
      }

      // :
      return $this;
   }
}
