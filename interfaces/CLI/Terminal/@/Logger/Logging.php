<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\_\Logger;


use Bootgly\Logger;


trait Logging
{
   use \Bootgly\Logging;
   use \Bootgly\CLI\Terminal\text\Formatting {
      wrap as private;
   }


   // * Config
   // imported from \Bootgly\logging...
   // * Meta
   // imported from \Bootgly\CLI\Terminal\text\Formatting...

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
            $color = self::_WHITE_FOREGROUND;
            break;
         case self::LOG_INFO_LEVEL:
            $severity = 'INFO';
            $color = self::_GREEN_BOLD;
            break;
         case self::LOG_NOTICE_LEVEL:
            $severity = 'NOTICE';
            $color = self::_CYAN_FOREGROUND;
            break;
         case self::LOG_WARNING_LEVEL:
            $severity = 'WARNING';
            $color = self::_YELLOW_BOLD;
            break;
         case self::LOG_ERROR_LEVEL:
            $severity = 'ERROR';
            $color = self::_RED_BRIGHT_FOREGROUND;
            break;
         case self::LOG_CRITICAL_LEVEL:
            $severity = 'CRITICAL';
            $color = self::_MAGENTA_FOREGROUND;
            break;
         case self::LOG_ALERT_LEVEL:
            $severity = 'ALERT';
            $color = self::_MAGENTA_BOLD;
            break;
         case self::LOG_EMERGENCY_LEVEL:
            $severity = 'EMERGENCY';
            $color = self::_RED_BOLD;
            break;

         default:
            $severity = 'LOG';
            $color = self::_DEFAULT_FOREGROUND;
      }

      return [
         $severity, $color
      ];
   }
   // @ Templating
   private function render ($message) : string
   {
      #$line = "\033[1A\n\033[K";

      // @ Levels => Colors (@:[a-b]+:)
      $message = preg_replace_callback('/@(:[a-z]+):/m', function ($matches) {
         $color = match ($matches[1]) {
            ':d', ':s', ':debug', ':success' => self::_GREEN_BRIGHT_FOREGROUND,

            ':i', ':info' => self::_CYAN_BRIGHT_FOREGROUND,
            ':n', ':notice' => self::_YELLOW_BRIGHT_FOREGROUND,
            ':w', ':warning' => self::_MAGENTA_BRIGHT_FOREGROUND,
            ':e', ':error' => self::_RED_BRIGHT_FOREGROUND,

            default => self::_DEFAULT_FOREGROUND
         };

         return $this->wrap($color);
      }, $message);

      // @ Colors => Colors (@#[a-bzA-Z]+:)
      $message = preg_replace_callback('/@(#[a-zA-Z]+):/m', function ($matches) {
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

         return $this->wrap($color);
      }, $message);

      // @ Style
      $message = preg_replace_callback('/@([*~_-])/m', function ($matches) {
         $style = match ($matches[1]) {
            '*' => self::_BOLD_STYLE,
            '~' => self::_ITALIC_STYLE,
            '_' => self::_UNDERLINE_STYLE,
            '-' => self::_STRIKE_STYLE,
            default => ''
         };

         return $this->wrap($style);
      }, $message);

      // @ Reset (End of)
      $message = preg_replace_callback('/\s@([;])|([*~_-])@/m', function ($matches) {
         return self::_RESET_FORMAT;
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

         $when .= $this->wrap(self::_BLACK_BRIGHT_FOREGROUND);
         $when .= '[';
         $when .= $DateTime->format('Y-m-d\TH:i:s.uP');
         $when .= '] ';
         $when .= self::_RESET_FORMAT;
      }
      // @ Display id
      $id = '';
      if (Logger::$display >= Logger::DISPLAY_MESSAGE_WHEN_ID) {
         if ( isSet($this->Logger) ) {
            $id .= $this->Logger->channel . '.';
         }

         $id .= $this->wrap($color);
         $id .= $severity . self::_RESET_FORMAT . ': ';
      }
      // @ Display message (always)
      $message = $this->wrap($color) . $message . self::_RESET_FORMAT;
      if (Logger::$display > Logger::DISPLAY_MESSAGE) {
         $message .= PHP_EOL;
      }

      $message = <<<LOG
      {$when}{$id}{$message}
      LOG;

      return $message;
   }
}
