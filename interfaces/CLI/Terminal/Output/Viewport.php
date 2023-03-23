<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Output;


use Bootgly\CLI\Escaping\viewport\Positioning;

use Bootgly\CLI\Terminal\Output;


class Viewport
{
   use Positioning;


   private Output $Output;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;
   }

   public function panDown (? int $lines = null) : Output
   {
      return $this->Output->escape($lines . self::_VIEWPORT_SCROLL_UP);
   }
   public function panUp (? int $lines = null) : Output
   {
      return $this->Output->escape($lines . self::_VIEWPORT_SCROLL_DOWN);
   }
}
