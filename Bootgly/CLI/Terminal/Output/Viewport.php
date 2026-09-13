<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Output;


use Bootgly\ABI\Code\__String\Escapeable\Viewport\Scrollable;

use Bootgly\CLI\Terminal\Output;


class Viewport
{
   use Scrollable;


   private Output $Output;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;
   }

   /**
    * Pans the viewport down: the content scrolls up and blank lines enter at the
    * bottom (SU). Without `$lines` the terminal pans a single line.
    *
    * @param null|int $lines The number of lines to pan.
    *
    * @return Output
    */
   public function down (null|int $lines = null): Output
   {
      return $this->Output->escape($lines . self::_VIEWPORT_SCROLL_UP);
   }
   /**
    * Pans the viewport up: the content scrolls down and blank lines enter at the
    * top (SD). Without `$lines` the terminal pans a single line.
    *
    * @param null|int $lines The number of lines to pan.
    *
    * @return Output
    */
   public function up (null|int $lines = null): Output
   {
      return $this->Output->escape($lines . self::_VIEWPORT_SCROLL_DOWN);
   }
   /**
    * Clips the scrolling region between two rows (DECSTBM) — text scrolls inside
    * the region only. No arguments reset the region to the full screen.
    * Side effect: the cursor homes to (1,1) — reposition after clipping.
    *
    * @param null|int $top The top row (1-based, inclusive).
    * @param null|int $bottom The bottom row (1-based, inclusive).
    *
    * @return Output
    */
   public function clip (null|int $top = null, null|int $bottom = null): Output
   {
      // ? No arguments reset the region to the full screen
      if ($top === null || $bottom === null) {
         return $this->Output->escape(self::_VIEWPORT_SCROLL_REGION);
      }

      return $this->Output->escape("{$top};{$bottom}" . self::_VIEWPORT_SCROLL_REGION);
   }
}
