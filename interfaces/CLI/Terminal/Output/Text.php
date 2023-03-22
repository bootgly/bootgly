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


use Bootgly\CLI\Escaping\text\Formatting;
use Bootgly\CLI\Escaping\text\Modifying;

use Bootgly\CLI\Terminal\Output;


class Text
{
   use Formatting;
   use Modifying;


   private Output $Output;

   // ! Coloring
   // @ type
   public const DEFAULT_COLORS = 1;
   public const BRIGHT_COLORS = 2;
   // default type
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
   // bright type
   public const COLORS_FOREGROUND_BRIGHT = [
      'black'   => self::_BLACK_BRIGHT_FOREGROUND,
      'red'     => self::_RED_BRIGHT_FOREGROUND,
      'green'   => self::_GREEN_BRIGHT_FOREGROUND,
      'yellow'  => self::_YELLOW_BRIGHT_FOREGROUND,
      'blue'    => self::_BLUE_BRIGHT_FOREGROUND,
      'magenta' => self::_MAGENTA_BRIGHT_FOREGROUND,
      'cyan'    => self::_CYAN_BRIGHT_FOREGROUND,
      'white'   => self::_WHITE_BRIGHT_FOREGROUND
   ];
   public const COLORS_BACKGROUND_BRIGHT = [
      'black'   => self::_BLACK_BRIGHT_BACKGROUND,
      'red'     => self::_RED_BRIGHT_BACKGROUND,
      'green'   => self::_GREEN_BRIGHT_BACKGROUND,
      'yellow'  => self::_YELLOW_BRIGHT_BACKGROUND,
      'blue'    => self::_BLUE_BRIGHT_BACKGROUND,
      'magenta' => self::_MAGENTA_BRIGHT_BACKGROUND,
      'cyan'    => self::_CYAN_BRIGHT_BACKGROUND,
      'white'   => self::_WHITE_BRIGHT_BACKGROUND
   ];
   // ! Styling
   public const STYLES = [
      'bold'      => self::_BOLD_STYLE,
      'italic'    => self::_ITALIC_STYLE,
      'underline' => self::_UNDERLINE_STYLE,
      'strike'    => self::_STRIKE_STYLE,
   ];

   // * Config
   // * Data
   // * Meta
   private ? string $color;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;


      // * Meta
      $this->color = null;
   }

   // @ Formatting
   public function colorize (
      int|string $foreground = 'default', int|string $background = 'default', int $type = self::DEFAULT_COLORS
   )
   {
      $codes = [];

      // @ Set Colors type
      $foregrounds = ($type !== self::BRIGHT_COLORS)
                     ? (self::COLORS_FOREGROUND) : (self::COLORS_FOREGROUND_BRIGHT);
      $backgrounds = ($type !== self::BRIGHT_COLORS)
                     ? self::COLORS_BACKGROUND : (self::COLORS_BACKGROUND_BRIGHT);

      // @ Use Preset colors
      if ( is_string($foreground) ) {
         $codes[] = $foregrounds[$foreground] ?? self::_DEFAULT_FOREGROUND;
      }
      if ( is_string($background) ) {
         $codes[] = $backgrounds[$background] ?? self::_DEFAULT_BACKGROUND;
      }

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

      // @ Wrap color codes
      $color = $this->wrap(...$codes);

      // @ Save the last defined color
      $this->color = $color;

      // @ Output color
      // TODO enqueue to Output and return self
      $this->Output->write($color);
   }
   public function stylize (string ...$styles)
   {
      $codes = [];

      if ( empty($styles) ) {
         $styles = [null];
      }

      foreach ($styles as $style) {
         $codes[] = match ($style) {
            'bold'      => self::_BOLD_STYLE,
            'italic'    => self::_ITALIC_STYLE,
            'underline' => self::_UNDERLINE_STYLE,
            'strike'    => self::_STRIKE_STYLE,
            default     => self::_DEFAULT_STYLE
         };
      }

      // @ Wrap style codes
      $wrapped = $this->wrap(...$codes);

      // @ Output style
      $this->Output->write($wrapped);

      // @ Try to keep the last defined colors?
      if (! $codes[0] && $this->color) {
         $this->Output->write($this->color);
      }
   }

   // @ Modifying
   /**
    * Insert <n> spaces at the current cursor position, shifting all existing text to the right.
    * Text exiting the screen to the right is removed.
    *
    * @param int $n The number of spaces to insert.
    *
    * @return Output
    */
   public function space (int $n = 1) : Output
   {
      return $this->Output->escape($n . self::_TEXT_INSERT_CHARACTER);
   }
   /**
    * Delete <n> characters and/or <n> lines at the current cursor position.
    *
    * Characteres: will be shifting in space characters, from the right edge of the screen.
    * Lines: deletes <n> lines from the buffer, starting with the row the cursor is on.
    *
    * @param int $characters The number of characters to delete.
    * @param int $lines The number of lines to delete.
    *
    * @return Output
    */
   public function delete (? int $characters = null, ? int $lines = null) : Output
   {
      $Output = &$this->Output;

      if ($characters > 0) {
         $Output->escape($characters . self::_TEXT_DELETE_CHARACTER);
      }

      if ($lines > 0) {
         $Output->escape($lines . self::_TEXT_DELETE_LINE);
      }

      return $Output;
   }
   /**
    * Erase <n> characters from the current cursor position, by overwriting them with a space character.
    *
    * @param int $characters The number of characters to erase.
    *
    * @return Output
    */
   public function erase (int $characters = 1) : Output
   {
      return $this->Output->escape($characters . self::_TEXT_ERASE_CHARACTER);
   }
   /**
    * Inserts <n> lines and/or <n> spaces at the cursor position.
    *
    * Lines: the line the cursor is on, and lines below it, will be shifted downwards.
    * Spaces: insert <n> spaces at the current cursor position, shifting all existing text to the right.
    *
    * @param int $lines The number of lines to insert.
    * @param int $spaces The number of spaces to insert.
    *
    * @return Output
    */
   public function insert (? int $lines = null, ? int $spaces = null) : Output
   {
      $Output = &$this->Output;

      if ($lines > 0) {
         $Output->escape($lines . self::_TEXT_INSERT_LINE);
      }

      if ($spaces > 0) {
         $Output->escape($spaces . self::_TEXT_INSERT_CHARACTER);
      }

      return $Output;
   }
   /**
    * Trims the current line of the screen, removing **all characters** to the right and/or left of the cursor position.
    *
    * @param bool $right Whether to remove all characters to the right of the cursor position.
    * @param bool $left Whether to remove all characters to the left of the cursor position.
    *
    * @return Output
    */
   public function trim (bool $left = false, bool $right = false) : Output
   {
      $Output = &$this->Output;

      if ($right && $left) {
         $Output->escape(self::_TEXT_ERASE_IN_LINE_2);
      } else if ($right) {
         $Output->escape(self::_TEXT_ERASE_IN_LINE_0);
      } else if ($left) {
         $Output->escape(self::_TEXT_ERASE_IN_LINE_1);
      }

      return $Output;
   }
}
