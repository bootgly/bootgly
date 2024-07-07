<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates\Template;


use Bootgly\ABI\Data\__String\Escapeable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Positionable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Shapeable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Visualizable;
use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Data\__String\Escapeable\Text\Modifiable;
use Bootgly\ABI\Data\__String\Escapeable\Viewport\Scrollable;
use Bootgly\ABI\Resources;


class Escaped implements Resources
{
   use Escapeable;
   use Positionable;
   use Shapeable;
   use Visualizable;
   use Formattable;
   use Modifiable;
   use Scrollable;

   // * Config
   // ...

   // * Data
   /** @var array<string, callable> */
   protected static array $directives = [];

   // * Metadata
   /** @var array<string> */
   protected static array $names = [];


   public static function boot (): void
   {
      self::$directives = [
         '/@(#[a-zA-Z]+):(\s?)/m' => function ($matches) {
            $color = match ($matches[1]) {
               '#black' => self::_BLACK_FOREGROUND,
               '#red' => self::_RED_FOREGROUND,
               '#green' => self::_GREEN_FOREGROUND,
               '#yellow' => self::_YELLOW_FOREGROUND,
               '#blue' => self::_BLUE_FOREGROUND,
               '#magenta' => self::_MAGENTA_FOREGROUND,
               '#cyan' => self::_CYAN_FOREGROUND,
               '#white' => self::_WHITE_FOREGROUND,
      
               '#Black', '#BLACK' => self::_BLACK_BRIGHT_FOREGROUND,
               '#Red', '#RED' => self::_RED_BRIGHT_FOREGROUND,
               '#Green', '#GREEN' => self::_GREEN_BRIGHT_FOREGROUND,
               '#Yellow', '#YELLOW' => self::_YELLOW_BRIGHT_FOREGROUND,
               '#Blue', '#BLUE' => self::_BLUE_BRIGHT_FOREGROUND,
               '#Magenta', '#MAGENTA' => self::_MAGENTA_BRIGHT_FOREGROUND,
               '#Cyan', '#CYAN' => self::_CYAN_BRIGHT_FOREGROUND,
               '#White', '#WHITE' => self::_WHITE_BRIGHT_FOREGROUND,
      
               default => self::_DEFAULT_FOREGROUND
            };
      
            return self::wrap($color);
         },
   
         '/@(\\\\+);/m' => function ($matches) { // DEPRECATED
            if ($matches[0]) {
               return str_repeat(PHP_EOL, strlen($matches[1]));
            }
         },
         '/@(\.+);/m' => function ($matches) {
            if ($matches[0]) {
               return str_repeat(PHP_EOL, strlen($matches[1]));
            }
         },
   
         '/@(:[a-z]+):/m' => function ($matches) {
            $color = match ($matches[1]) {
               ':d', ':s', ':debug', ':success' => self::_GREEN_BRIGHT_FOREGROUND,
      
               ':i', ':info' => self::_CYAN_BRIGHT_FOREGROUND,
               ':n', ':notice' => self::_YELLOW_BRIGHT_FOREGROUND,
               ':w', ':warning' => self::_MAGENTA_BRIGHT_FOREGROUND,
               ':e', ':error' => self::_RED_BRIGHT_FOREGROUND,
      
               default => self::_DEFAULT_FOREGROUND
            };
      
            return self::wrap($color);
         },
   
         '/@([@*~_-]):[\s]?/m' => function ($matches) {
            $style = match ($matches[1]) {
               '@' => self::_BLINK_STYLE,
      
               '*' => self::_BOLD_STYLE,
               '~' => self::_ITALIC_STYLE,
               '_' => self::_UNDERLINE_STYLE,
               '-' => self::_STRIKE_STYLE,
      
               default => ''
            };
      
            return self::wrap($style);
         },
   
         '/\s?@(;)|([*~_-])@/m' => function ($matches) {
            return self::_RESET_FORMAT;
         }
      ];
   }

   public static function render (string $message): string
   {
      $message = preg_replace_callback_array(self::$directives, $message);

      return $message;
   }
}

Escaped::boot();
