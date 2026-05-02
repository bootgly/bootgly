<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String\Escapeable\Text;


use function implode;

use Bootgly\ABI\Data\__String\Escapeable;
use Bootgly\ABI\Data\__String\Escapeable\Text;


trait Formattable
{
   use Escapeable;
   use Text;


   public const string _END_FORMAT = 'm';
   public const string _RESET_FORMAT = self::_START_ESCAPE . '0' . self::_END_FORMAT;

   // ! Coloring
   // @ default foregrounds
   public const string _BLACK_FOREGROUND    = '30';
   public const string _RED_FOREGROUND      = '31';
   public const string _GREEN_FOREGROUND    = '32';
   public const string _YELLOW_FOREGROUND   = '33';
   public const string _BLUE_FOREGROUND     = '34';
   public const string _MAGENTA_FOREGROUND  = '35';
   public const string _CYAN_FOREGROUND     = '36';
   public const string _WHITE_FOREGROUND    = '37';
   public const string _EXTENDED_FOREGROUND = '38';
   public const string _DEFAULT_FOREGROUND  = '39';
   // @ default backgrounds
   public const string _BLACK_BACKGROUND    = '40';
   public const string _RED_BACKGROUND      = '41';
   public const string _GREEN_BACKGROUND    = '42';
   public const string _YELLOW_BACKGROUND   = '43';
   public const string _BLUE_BACKGROUND     = '44';
   public const string _MAGENTA_BACKGROUND  = '45';
   public const string _CYAN_BACKGROUND     = '46';
   public const string _WHITE_BACKGROUND    = '47';
   public const string _EXTENDED_BACKGROUND = '48';
   public const string _DEFAULT_BACKGROUND  = '49';

   // @ brights foregrounds
   public const string _BLACK_BRIGHT_FOREGROUND   = '90';
   public const string _RED_BRIGHT_FOREGROUND     = '91';
   public const string _GREEN_BRIGHT_FOREGROUND   = '92';
   public const string _YELLOW_BRIGHT_FOREGROUND  = '93';
   public const string _BLUE_BRIGHT_FOREGROUND    = '94';
   public const string _MAGENTA_BRIGHT_FOREGROUND = '95';
   public const string _CYAN_BRIGHT_FOREGROUND    = '96';
   public const string _WHITE_BRIGHT_FOREGROUND   = '97';
   // @ brights backgrounds
   public const string _BLACK_BRIGHT_BACKGROUND   = '100';
   public const string _RED_BRIGHT_BACKGROUND     = '101';
   public const string _GREEN_BRIGHT_BACKGROUND   = '102';
   public const string _YELLOW_BRIGHT_BACKGROUND  = '103';
   public const string _BLUE_BRIGHT_BACKGROUND    = '104';
   public const string _MAGENTA_BRIGHT_BACKGROUND = '105';
   public const string _CYAN_BRIGHT_BACKGROUND    = '106';
   public const string _WHITE_BRIGHT_BACKGROUND   = '107';
   // @ soft foregrounds
   public const string _BLACK_SOFT_FOREGROUND  = '38;2;102;102;102';
   public const string _RED_DIM_FOREGROUND     = '38;2;177;88;88';
   public const string _RED_SOFT_FOREGROUND    = '38;2;204;102;102';
   public const string _RED_PALE_FOREGROUND    = '38;2;255;204;204';
   public const string _GREEN_DIM_FOREGROUND   = '38;2;88;177;88';
   public const string _GREEN_SOFT_FOREGROUND  = '38;2;102;204;102';
   public const string _GREEN_PALE_FOREGROUND  = '38;2;204;255;204';
   public const string _YELLOW_SOFT_FOREGROUND = '38;2;255;221;153';
   public const string _YELLOW_PALE_FOREGROUND = '38;2;255;242;214';
   // @ soft backgrounds
   public const string _RED_DIM_BACKGROUND    = '48;2;50;42;42';
   public const string _RED_SOFT_BACKGROUND   = '48;2;58;48;48';
   public const string _RED_PALE_BACKGROUND   = '48;2;97;38;38';
   public const string _GREEN_DIM_BACKGROUND  = '48;2;42;50;42';
   public const string _GREEN_SOFT_BACKGROUND = '48;2;48;58;48';
   public const string _GREEN_PALE_BACKGROUND = '48;2;38;97;38';


   // ! Styling
   public const string _DEFAULT_STYLE   = '';
   public const string _BOLD_STYLE      = '1';
   public const string _DIM_STYLE       = '2';
   public const string _ITALIC_STYLE    = '3';
   public const string _UNDERLINE_STYLE = '4';
   public const string _BLINK_STYLE     = '5';
   public const string _STRIKE_STYLE    = '9';

   // * Combined
   // @ bold style + default foregrounds
   public const string _BLACK_BOLD    = '1;30';
   public const string _RED_BOLD      = '1;31';
   public const string _GREEN_BOLD    = '1;32';
   public const string _YELLOW_BOLD   = '1;33';
   public const string _BLUE_BOLD     = '1;34';
   public const string _MAGENTA_BOLD  = '1;35';
   public const string _CYAN_BOLD     = '1;36';
   public const string _WHITE_BOLD    = '1;37';
   public const string _EXTENDED_BOLD = '1;38';
   public const string _DEFAULT_BOLD  = '1;39';
   // @ default foreground + default background
   public const string _GREEN_BLACK = '32;40';
   // @ default background + default foreground
   public const string _BLACK_WHITE = '47;30';
   // @ soft foreground + soft background
   public const string _RED_DIM    = '38;2;177;88;88;48;2;50;42;42';
   public const string _RED_SOFT   = '38;2;204;102;102;48;2;58;48;48';
   public const string _RED_PALE   = '38;2;255;204;204;48;2;97;38;38';
   public const string _GREEN_DIM  = '38;2;88;177;88;48;2;42;50;42';
   public const string _GREEN_SOFT = '38;2;102;204;102;48;2;48;58;48';
   public const string _GREEN_PALE = '38;2;204;255;204;48;2;38;97;38';
   // @ dim style + soft foreground
   public const string _YELLOW_PALE_DIM = '2;38;2;255;242;214';


   protected static function wrap (string ...$codes): string
   {
      return self::_START_ESCAPE . implode(';', $codes) . self::_END_FORMAT;
   }
}
