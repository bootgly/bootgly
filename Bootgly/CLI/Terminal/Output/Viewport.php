<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Output;


use Bootgly\ABI\Data\__String\Escapeable\Viewport\Scrollable;

use Bootgly\CLI\Terminal\Output;


class Viewport
{
   use Scrollable;


   private Output $Output;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;
   }

   public function panDown (? int $lines = null): Output
   {
      return $this->Output->escape($lines . self::_VIEWPORT_SCROLL_UP);
   }
   public function panUp (? int $lines = null): Output
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
