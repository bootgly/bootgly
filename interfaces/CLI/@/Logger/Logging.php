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
   // @ color (foreground;background?)
   // transparent
   public const LOG_BLACK_TRANSPARENT_COLOR = "\033[30m";
   public const LOG_RED_TRANSPARENT_COLOR = "\033[31m";
   public const LOG_GREEN_TRANSPARENT_COLOR = "\033[32m";
   public const LOG_YELLOW_TRANSPARENT_COLOR = "\033[33m";
   public const LOG_BLUE_TRANSPARENT_COLOR = "\033[34m";
   public const LOG_MAGENTA_TRANSPARENT_COLOR = "\033[35m";
   public const LOG_CYAN_TRANSPARENT_COLOR = "\033[36m";
   public const LOG_WHITE_TRANSPARENT_COLOR = "\033[37m";
   public const LOG_EXTENDED_TRANSPARENT_COLOR = "\033[38m";
   public const LOG_DEFAULT_TRANSPARENT_COLOR = "\033[39m";
   // bold
   public const LOG_BLACK_BOLD_COLOR = "\033[90m";
   public const LOG_RED_BOLD_COLOR = "\033[91m";
   public const LOG_GREEN_BOLD_COLOR = "\033[92m";
   public const LOG_YELLOW_BOLD_COLOR = "\033[93m";
   public const LOG_BLUE_BOLD_COLOR = "\033[94m";
   public const LOG_MAGENTA_BOLD_COLOR = "\033[95m";
   public const LOG_CYAN_BOLD_COLOR = "\033[96m";
   public const LOG_WHITE_BOLD_COLOR = "\033[97m";
   // with background
   public const LOG_BLACK_WHITE_COLOR = "\033[47;30m";
   public const LOG_GREEN_BLACK_COLOR = "\033[32;40m";
   // @ Style
   public const LOG_BOLD_STYLE = "\033[1m";
   public const LOG_ITALIC_STYLE = "\033[3m";
   public const LOG_UNDERLINE_STYLE = "\033[4m";
   public const LOG_STRIKE_STYLE = "\033[9m";
   // @
   public const LOG_START_OF = "\033[";
   public const LOG_END_OF = "\033[0m";


   public function log ($message, int $level = self::LOG_DEFAULT_LEVEL) : true
   {
      if (Logger::$display === Logger::DISPLAY_NONE) {
         return true;
      }

      switch ($level) {
         case self::LOG_SUCCESS_LEVEL:
            $level = 'SUCCESS';
            $color = self::LOG_GREEN_BOLD_COLOR;
            break;

         case self::LOG_NOTICE_LEVEL:
            $level = 'NOTICE';
            $color = self::LOG_YELLOW_BOLD_COLOR;
            break;
         case self::LOG_INFO_LEVEL:
            $level = 'INFO';
            $color = self::LOG_CYAN_BOLD_COLOR;
            break;

         case self::LOG_WARNING_LEVEL:
            $level = 'WARNING';
            $color = self::LOG_MAGENTA_BOLD_COLOR;
            break;
         case self::LOG_ERROR_LEVEL:
            $level = 'ERROR';
            $color = self::LOG_RED_BOLD_COLOR;
            break;

         default:
            $level = 'LOG';
            $color = self::LOG_DEFAULT_TRANSPARENT_COLOR;
      }

      // @ Set level in string
      if (Logger::$display >= Logger::DISPLAY_MESSAGE_DATETIME_LEVEL) {
         $level = '[' . $level . '] ';
      } else {
         $level = '';
      }

      // @ Set datetime
      $datetime = '';
      if (Logger::$display >= Logger::DISPLAY_MESSAGE_DATETIME) {
         $DateTime = new \DateTime();
         $datetime = "\033[90m" . $DateTime->format('Y-m-d\TH:i:s.uP') . ': ' . "\033[0m";
      }

      // @ Format and set message
      $message = $this->format($message);

      // @ Output log
      echo <<<LOG
      {$color}{$level}\033[0m{$datetime}{$color}{$message}\033[0m
      LOG;

      return true;
   }

   private function format ($message) : string
   {
      #$line = "\033[1A\n\033[K";

      // @ Levels => Decorators (@:[a-b]+:)
      $message = preg_replace_callback('/@(:[a-z]+):/m', function ($matches) {
         return match ($matches[1]) {
            ':s', ':success' => self::LOG_GREEN_BOLD_COLOR,
            ':n', ':notice' => self::LOG_YELLOW_BOLD_COLOR,
            ':i', ':info' => self::LOG_CYAN_BOLD_COLOR,
            ':w', ':warning' => self::LOG_MAGENTA_BOLD_COLOR,
            ':e', ':error' => self::LOG_RED_BOLD_COLOR,
            default => self::LOG_DEFAULT_TRANSPARENT_COLOR
         };
      }, $message);

      // @ Style
      $message = preg_replace_callback('/@([*~_-])/m', function ($matches) {
         return match ($matches[1]) {
            '*' => self::LOG_BOLD_STYLE,
            '~' => self::LOG_ITALIC_STYLE,
            '_' => self::LOG_UNDERLINE_STYLE,
            '-' => self::LOG_STRIKE_STYLE,
            default => ''
         };
      }, $message);

      // @ End of
      $message = preg_replace_callback('/\s@([;])|([*~_-])@/m', function ($matches) {
         return self::LOG_END_OF;
      }, $message);

      // @ Break lines / End of line (EOL)
      $message = preg_replace_callback('/@(\\\\+);/m', function ($matches) {
         if ($matches[0]) {
            return str_repeat(PHP_EOL, strlen($matches[1]));
         }
      }, $message);

      return $message;
   }
}
