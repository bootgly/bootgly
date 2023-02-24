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
   use \Bootgly\CLI\Console\text\Formatting;


   // * Config
   // imported from \Bootgly\logging...
   // * Meta
   // imported from \Bootgly\CLI\Console\text\Formatting...

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
