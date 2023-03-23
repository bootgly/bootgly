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


use Bootgly\CLI;
use Bootgly\Logger;


trait Logging
{
   use \Bootgly\Logging;
   use \Bootgly\CLI\Escaping\text\Formatting;


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
      $message = CLI\Template::render($message);

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

   // @ Formatting
   private function format ($message, $severity, $color) : string
   {
      // @ Display when
      $when = '';
      if (Logger::$display >= Logger::DISPLAY_MESSAGE_WHEN) {
         $DateTime = new \DateTime();

         $when .= self::wrap(self::_BLACK_BRIGHT_FOREGROUND);
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

         $id .= self::wrap($color);
         $id .= $severity . self::_RESET_FORMAT . ': ';
      }
      // @ Display message (always)
      $message = self::wrap($color) . $message . self::_RESET_FORMAT;
      if (Logger::$display > Logger::DISPLAY_MESSAGE) {
         $message .= PHP_EOL;
      }

      $message = <<<LOG
      {$when}{$id}{$message}
      LOG;

      return $message;
   }
}
