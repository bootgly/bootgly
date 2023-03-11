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


use Bootgly\CLI;

use Bootgly\CLI\Escaping\text\Formatting;
use Bootgly\CLI\Escaping\text\Modifying;

use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


class Text
{
   use Formatting;
   use Modifying;


   private Output $Output;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;
   }

   // @ Formatting
   public function color (string $foreground, string $background)
   {
      // TODO
   }

   // @ Modifying
   /**
    * Trims the current line of the screen, removing characters to the right and/or left of the cursor position.
    *
    * @param bool $right Whether to remove characters to the right of the cursor position.
    * @param bool $left Whether to remove characters to the left of the cursor position.
    */
   function trim (bool $left = false, bool $right = true): Output
   {
      if ($right && $left) {
         return $this->Output->escape(self::_TEXT_ERASE_IN_LINE_2);
      } else if ($right) {
         return $this->Output->escape(self::_TEXT_ERASE_IN_LINE_0);
      } else if ($left) {
         return $this->Output->escape(self::_TEXT_ERASE_IN_LINE_1);
      }
   }
}
