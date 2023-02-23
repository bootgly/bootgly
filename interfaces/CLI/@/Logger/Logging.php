<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\_\Logger;


use Bootgly\Logger;


trait Logging
{
   use \Bootgly\Logging; // TODO remove; use interface instead.


   // * Config
   // imported from \Bootgly\logging...
   // * Meta
   // TODO move to Console namespace
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


   public function log ($message, int $level = self::LOG_DEBUG_LEVEL) : true
   {
      if (Logger::$display === Logger::DISPLAY_NONE) {
         return true;
      }

      // @ Translate level
      [$severity, $color] = $this->translate($level);

      // @ Render templating
      $message = $this->render($message);

      // @ Output log
      echo $this->format($message, $severity, $color);

      return true;
   }

   // @ Translating
   // int level => string level
   // int level => ANSI color code
   private function translate (int $level) : array
   {
      switch ($level) {
         case self::LOG_DEBUG_LEVEL:
            $severity = 'DEBUG';
            $color = self::LOG_WHITE_FOREGROUND;
            break;
         case self::LOG_INFO_LEVEL:
            $severity = 'INFO';
            $color = self::LOG_GREEN_BOLD;
            break;
         case self::LOG_NOTICE_LEVEL:
            $severity = 'NOTICE';
            $color = self::LOG_CYAN_FOREGROUND;
            break;
         case self::LOG_WARNING_LEVEL:
            $severity = 'WARNING';
            $color = self::LOG_YELLOW_BOLD;
            break;
         case self::LOG_ERROR_LEVEL:
            $severity = 'ERROR';
            $color = self::LOG_RED_BRIGHT_FOREGROUND;
            break;
         case self::LOG_CRITICAL_LEVEL:
            $severity = 'CRITICAL';
            $color = self::LOG_MAGENTA_FOREGROUND;
            break;
         case self::LOG_ALERT_LEVEL:
            $severity = 'ALERT';
            $color = self::LOG_MAGENTA_BOLD;
            break;
         case self::LOG_EMERGENCY_LEVEL:
            $severity = 'EMERGENCY';
            $color = self::LOG_RED_BOLD;
            break;

         default:
            $severity = 'LOG';
            $color = self::LOG_DEFAULT_FOREGROUND;
      }

      return [
         $severity, $color
      ];
   }
   // @ Templating
   private function render ($message) : string
   {
      #$line = "\033[1A\n\033[K";

      // @ Levels => Decorators (@:[a-b]+:)
      $message = preg_replace_callback('/@(:[a-z]+):/m', function ($matches) {
         $color = self::LOG_START;
         $color .= match ($matches[1]) {
            ':d', ':s', ':debug', ':success' => self::LOG_GREEN_BRIGHT_FOREGROUND,

            ':i', ':info' => self::LOG_CYAN_BRIGHT_FOREGROUND,
            ':n', ':notice' => self::LOG_YELLOW_BRIGHT_FOREGROUND,
            ':w', ':warning' => self::LOG_MAGENTA_BRIGHT_FOREGROUND,
            ':e', ':error' => self::LOG_RED_BRIGHT_FOREGROUND,

            default => self::LOG_DEFAULT_FOREGROUND
         };
         $color .= self::LOG_END;

         return $color;
      }, $message);

      // @ Style
      $message = preg_replace_callback('/@([*~_-])/m', function ($matches) {
         $style = self::LOG_START;
         $style .= match ($matches[1]) {
            '*' => self::LOG_BOLD_STYLE,
            '~' => self::LOG_ITALIC_STYLE,
            '_' => self::LOG_UNDERLINE_STYLE,
            '-' => self::LOG_STRIKE_STYLE,
            default => ''
         };
         $style .= self::LOG_END;

         return $style;
      }, $message);

      // @ Reset (End of)
      $message = preg_replace_callback('/\s@([;])|([*~_-])@/m', function ($matches) {
         return self::LOG_RESET;
      }, $message);

      // @ Break lines / End of line (EOL)
      $message = preg_replace_callback('/@(\\\\+);/m', function ($matches) {
         if ($matches[0]) {
            return str_repeat(PHP_EOL, strlen($matches[1]));
         }
      }, $message);

      return $message;
   }
   // @ Formatting
   private function format ($message, $severity, $color) : string
   {
      // @ Display when
      $when = '';
      if (Logger::$display >= Logger::DISPLAY_MESSAGE_WHEN) {
         $DateTime = new \DateTime();

         $when .= self::LOG_START . self::LOG_BLACK_BRIGHT_FOREGROUND . self::LOG_END;
         $when .= '[';
         $when .= $DateTime->format('Y-m-d\TH:i:s.uP');
         $when .= '] ';
         $when .= self::LOG_RESET;
      }
      // @ Display id
      $id = '';
      if (Logger::$display >= Logger::DISPLAY_MESSAGE_WHEN_ID) {
         if ( isSet($this->Logger) ) {
            $id .= $this->Logger->channel . '.';
         }

         $id .= self::LOG_START . $color . self::LOG_END;
         $id .= $severity . self::LOG_RESET . ': ';
      }
      // @ Display message (always)
      $message = self::LOG_START . $color . self::LOG_END . $message . self::LOG_RESET;
      if (Logger::$display > Logger::DISPLAY_MESSAGE) {
         $message .= PHP_EOL;
      }

      $message = <<<LOG
      {$when}{$id}{$message}
      LOG;

      return $message;
   }
}
