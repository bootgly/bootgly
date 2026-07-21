<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates\Template;


use const PHP_EOL;
use function is_string;
use function preg_replace_callback_array;
use function str_repeat;
use function strlen;

use Bootgly\ABI\Code\__String\Escapeable;
use Bootgly\ABI\Code\__String\Escapeable\Cursor\Positionable;
use Bootgly\ABI\Code\__String\Escapeable\Cursor\Shapeable;
use Bootgly\ABI\Code\__String\Escapeable\Cursor\Visualizable;
use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Code\__String\Escapeable\Text\Modifiable;
use Bootgly\ABI\Code\__String\Escapeable\Viewport\Scrollable;
use Bootgly\ABI\Code\__String\Theme;
use Bootgly\ABI\Resources;


class Escaped
{
   use Escapeable;
   use Positionable;
   use Shapeable;
   use Visualizable;
   use Formattable;
   use Modifiable;
   use Scrollable;
   use Resources;

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
            // # Semantic color — resolved through the active UI Theme (dark/light/mono).
            $key = match ($matches[1]) {
               ':s', ':success' => 'success',
               ':d', ':debug'   => 'debug',
               ':i', ':info'    => 'info',
               ':n', ':notice'  => 'notice',
               ':w', ':warning' => 'warning',
               ':e', ':error'   => 'error',

               default => null
            };

            // ?: Unknown semantic token — keep the default foreground
            if ($key === null) {
               return self::wrap(self::_DEFAULT_FOREGROUND);
            }

            return Theme::$Current->open($key);
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

      if (!is_string($message)) {
         return '';
      }

      return $message;
   }
}

Escaped::boot();
