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

   public const COLORS_FOREGROUND = [
      'black'   => self::_BLACK_FOREGROUND,
      'red'     => self::_RED_FOREGROUND,
      'green'   => self::_GREEN_FOREGROUND,
      'yellow'  => self::_YELLOW_FOREGROUND,
      'blue'    => self::_BLUE_FOREGROUND,
      'magenta' => self::_MAGENTA_FOREGROUND,
      'cyan'    => self::_CYAN_FOREGROUND,
      'white'   => self::_WHITE_FOREGROUND,

      'default' => self::_DEFAULT_FOREGROUND,
   ];
   public const COLORS_BACKGROUND = [
      'black'   => self::_BLACK_BACKGROUND,
      'red'     => self::_RED_BACKGROUND,
      'green'   => self::_GREEN_BACKGROUND,
      'yellow'  => self::_YELLOW_BACKGROUND,
      'blue'    => self::_BLUE_BACKGROUND,
      'magenta' => self::_MAGENTA_BACKGROUND,
      'cyan'    => self::_CYAN_BACKGROUND,
      'white'   => self::_WHITE_BACKGROUND,

      'default' => self::_DEFAULT_BACKGROUND,
   ];


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;
   }

   // @ Formatting
   public function colorize (null|int|string $foreground = 'default', null|int|string $background = 'default')
   {
      $codes = [];

      // @ Use Preset colors
      if ( is_string($foreground) || $foreground === null )
         $codes[] = self::COLORS_FOREGROUND[$foreground] ?? self::_DEFAULT_FOREGROUND;
      if ( is_string($background) || $background === null )
         $codes[] = self::COLORS_BACKGROUND[$background] ?? self::_DEFAULT_BACKGROUND;

      // @ Use Extended colors
      if ( is_int($foreground) && $foreground >= 0 && $foreground <= 255 ) {
         $codes[] = self::_EXTENDED_FOREGROUND;
         $codes[] = '5';
         $codes[] = (string) $foreground;
      }
      if ( is_int($background) && $background >= 0 && $background <= 255 ) {
         $codes[] = self::_EXTENDED_BACKGROUND;
         $codes[] = '5';
         $codes[] = (string) $background;
      }

      $color = $this->wrap(...$codes);

      $this->Output->escape($color);
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
