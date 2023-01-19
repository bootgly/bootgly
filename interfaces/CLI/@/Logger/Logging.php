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
   // @ line
   public const LOG_END_OF_DECORATOR = "\033[0m";


   protected function log ($message, int $level = self::LOG_DEFAULT_LEVEL) : true
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
         $datetime = date(DATE_ATOM) . ': ';
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
            ':i', ':info' => self::LOG_CYAN_BOLD_COLOR,
            ':n', ':notice' => self::LOG_YELLOW_BOLD_COLOR,
            ':e', ':error' => self::LOG_RED_BOLD_COLOR,
            ':s', ':success' => self::LOG_GREEN_BOLD_COLOR
         };
      }, $message);

      // @ End of decorator
      $message = str_replace([' @;'], self::LOG_END_OF_DECORATOR, $message);

      // @ End of line
      $message = preg_replace_callback('/@(\\\\+);/m', function ($matches) {
         if ($matches[0]) {
            return str_repeat(PHP_EOL, strlen($matches[1]));
         }
      }, $message);

      return $message;
   }
}
