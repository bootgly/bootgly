<?php
return [
   '/@(#[a-zA-Z]+):[\s]?/m' => function ($matches) {
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
   }
];
