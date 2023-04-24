<?php
return [
   '/@([@*~_-])[\s:]?/m' => function ($matches) {
      $style = match ($matches[1]) {
         '@' => self::_BLINK_STYLE,

         '*' => self::_BOLD_STYLE,
         '~' => self::_ITALIC_STYLE,
         '_' => self::_UNDERLINE_STYLE,
         '-' => self::_STRIKE_STYLE,

         default => ''
      };

      return self::wrap($style);
   }
];
