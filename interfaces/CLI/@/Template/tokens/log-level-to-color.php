<?php
return [
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
   }
];
