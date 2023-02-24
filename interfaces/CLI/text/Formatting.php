<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\text;


trait Formatting
{
   // * Meta
   // ! ANSI Formatting
   public const LOG_START = "\033[";
   public const LOG_END = 'm';
   public const LOG_RESET = self::LOG_START . '0' . self::LOG_END;
   // ? Single
   // @ styles
   public const LOG_BOLD_STYLE      = '1';
   public const LOG_ITALIC_STYLE    = '3';
   public const LOG_UNDERLINE_STYLE = '4';
   public const LOG_STRIKE_STYLE    = '9';

   // @ default foregrounds
   public const LOG_BLACK_FOREGROUND    = '30';
   public const LOG_RED_FOREGROUND      = '31';
   public const LOG_GREEN_FOREGROUND    = '32';
   public const LOG_YELLOW_FOREGROUND   = '33';
   public const LOG_BLUE_FOREGROUND     = '34';
   public const LOG_MAGENTA_FOREGROUND  = '35';
   public const LOG_CYAN_FOREGROUND     = '36';
   public const LOG_WHITE_FOREGROUND    = '37';
   public const LOG_EXTENDED_FOREGROUND = '38';
   public const LOG_DEFAULT_FOREGROUND  = '39';
   // @ default backgrounds
   public const LOG_BLACK_BACKGROUND    = '40';
   public const LOG_RED_BACKGROUND      = '41';
   public const LOG_GREEN_BACKGROUND    = '42';
   public const LOG_YELLOW_BACKGROUND   = '43';
   public const LOG_BLUE_BACKGROUND     = '44';
   public const LOG_MAGENTA_BACKGROUND  = '45';
   public const LOG_CYAN_BACKGROUND     = '46';
   public const LOG_WHITE_BACKGROUND    = '47';
   public const LOG_EXTENDED_BACKGROUND = '48';
   public const LOG_DEFAULT_BACKGROUND  = '49';

   // @ brights foregrounds
   public const LOG_BLACK_BRIGHT_FOREGROUND   = '90';
   public const LOG_RED_BRIGHT_FOREGROUND     = '91';
   public const LOG_GREEN_BRIGHT_FOREGROUND   = '92';
   public const LOG_YELLOW_BRIGHT_FOREGROUND  = '93';
   public const LOG_BLUE_BRIGHT_FOREGROUND    = '94';
   public const LOG_MAGENTA_BRIGHT_FOREGROUND = '95';
   public const LOG_CYAN_BRIGHT_FOREGROUND    = '96';
   public const LOG_WHITE_BRIGHT_FOREGROUND   = '97';
   // @ brights backgrounds
   public const LOG_BLACK_BRIGHT_BACKGROUND   = '100';
   public const LOG_RED_BRIGHT_BACKGROUND     = '101';
   public const LOG_GREEN_BRIGHT_BACKGROUND   = '102';
   public const LOG_YELLOW_BRIGHT_BACKGROUND  = '103';
   public const LOG_BLUE_BRIGHT_BACKGROUND    = '104';
   public const LOG_MAGENTA_BRIGHT_BACKGROUND = '105';
   public const LOG_CYAN_BRIGHT_BACKGROUND    = '106';
   public const LOG_WHITE_BRIGHT_BACKGROUND   = '107';
   // ? Combined
   // @ bold style + default foregrounds
   public const LOG_BLACK_BOLD    = '1;30';
   public const LOG_RED_BOLD      = '1;31';
   public const LOG_GREEN_BOLD    = '1;32';
   public const LOG_YELLOW_BOLD   = '1;33';
   public const LOG_BLUE_BOLD     = '1;34';
   public const LOG_MAGENTA_BOLD  = '1;35';
   public const LOG_CYAN_BOLD     = '1;36';
   public const LOG_WHITE_BOLD    = '1;37';
   public const LOG_EXTENDED_BOLD = '1;38';
   public const LOG_DEFAULT_BOLD  = '1;39';
   // @ default foreground + default background
   public const LOG_GREEN_BLACK = '32;40';
   // @ default background + default foreground
   public const LOG_BLACK_WHITE = '47;30';
}
