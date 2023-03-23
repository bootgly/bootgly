<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Escaping\text;


use Bootgly\CLI;
use Bootgly\CLI\Escaping;


trait Formatting
{
   use Escaping;


   public const _END_FORMAT = 'm';
   public const _RESET_FORMAT = CLI::_START_ESCAPE . '0' . self::_END_FORMAT;

   // ! Coloring
   // @ default foregrounds
   public const _BLACK_FOREGROUND    = '30';
   public const _RED_FOREGROUND      = '31';
   public const _GREEN_FOREGROUND    = '32';
   public const _YELLOW_FOREGROUND   = '33';
   public const _BLUE_FOREGROUND     = '34';
   public const _MAGENTA_FOREGROUND  = '35';
   public const _CYAN_FOREGROUND     = '36';
   public const _WHITE_FOREGROUND    = '37';
   public const _EXTENDED_FOREGROUND = '38';
   public const _DEFAULT_FOREGROUND  = '39';
   // @ default backgrounds
   public const _BLACK_BACKGROUND    = '40';
   public const _RED_BACKGROUND      = '41';
   public const _GREEN_BACKGROUND    = '42';
   public const _YELLOW_BACKGROUND   = '43';
   public const _BLUE_BACKGROUND     = '44';
   public const _MAGENTA_BACKGROUND  = '45';
   public const _CYAN_BACKGROUND     = '46';
   public const _WHITE_BACKGROUND    = '47';
   public const _EXTENDED_BACKGROUND = '48';
   public const _DEFAULT_BACKGROUND  = '49';

   // @ brights foregrounds
   public const _BLACK_BRIGHT_FOREGROUND   = '90';
   public const _RED_BRIGHT_FOREGROUND     = '91';
   public const _GREEN_BRIGHT_FOREGROUND   = '92';
   public const _YELLOW_BRIGHT_FOREGROUND  = '93';
   public const _BLUE_BRIGHT_FOREGROUND    = '94';
   public const _MAGENTA_BRIGHT_FOREGROUND = '95';
   public const _CYAN_BRIGHT_FOREGROUND    = '96';
   public const _WHITE_BRIGHT_FOREGROUND   = '97';
   // @ brights backgrounds
   public const _BLACK_BRIGHT_BACKGROUND   = '100';
   public const _RED_BRIGHT_BACKGROUND     = '101';
   public const _GREEN_BRIGHT_BACKGROUND   = '102';
   public const _YELLOW_BRIGHT_BACKGROUND  = '103';
   public const _BLUE_BRIGHT_BACKGROUND    = '104';
   public const _MAGENTA_BRIGHT_BACKGROUND = '105';
   public const _CYAN_BRIGHT_BACKGROUND    = '106';
   public const _WHITE_BRIGHT_BACKGROUND   = '107';


   // ! Styling
   public const _DEFAULT_STYLE   = '';
   public const _BOLD_STYLE      = '1';
   public const _ITALIC_STYLE    = '3';
   public const _UNDERLINE_STYLE = '4';
   public const _BLINK_STYLE     = '5';
   public const _STRIKE_STYLE    = '9';

   // * Combined
   // @ bold style + default foregrounds
   public const _BLACK_BOLD    = '1;30';
   public const _RED_BOLD      = '1;31';
   public const _GREEN_BOLD    = '1;32';
   public const _YELLOW_BOLD   = '1;33';
   public const _BLUE_BOLD     = '1;34';
   public const _MAGENTA_BOLD  = '1;35';
   public const _CYAN_BOLD     = '1;36';
   public const _WHITE_BOLD    = '1;37';
   public const _EXTENDED_BOLD = '1;38';
   public const _DEFAULT_BOLD  = '1;39';
   // @ default foreground + default background
   public const _GREEN_BLACK = '32;40';
   // @ default background + default foreground
   public const _BLACK_WHITE = '47;30';


   protected static function wrap (string ...$codes)
   {
      return self::_START_ESCAPE . implode(';', $codes) . self::_END_FORMAT;
   }
}
