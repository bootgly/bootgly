<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Formatters;


use const PHP_EOL;
use function date;
use function floor;
use function sprintf;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatter;


class Line implements Formatter
{
   use Formattable;


   /**
    * Render a record as a single human/terminal line with ANSI colors.
    *
    * Honors the global `Display::$mode` mode for timestamp and channel/severity id.
    *
    * @param Record $Record The record to format.
    * @return string The formatted line.
    */
   public function format (Record $Record): string
   {
      $display = Display::$mode;

      // @ Render templating
      $message = TemplateEscaped::render($Record->message);

      // @ Translate level
      $color = $this->color($Record->Level);
      $severity = $Record->Level->render();

      // @ Display when
      $when = '';
      if ($display >= Display::MESSAGE_WHEN) {
         $seconds = (int) $Record->timestamp;
         $micro = sprintf('%06d', (int) (($Record->timestamp - floor($Record->timestamp)) * 1000000));

         $when .= self::wrap(self::_BLACK_BRIGHT_FOREGROUND);
         $when .= '[';
         $when .= date('Y-m-d\TH:i:s', $seconds) . ".$micro" . date('P', $seconds);
         $when .= '] ';
         $when .= self::_RESET_FORMAT;
      }

      // @ Display id
      $id = '';
      if ($display >= Display::MESSAGE_WHEN_ID) {
         if ($Record->channel !== '') {
            $id .= "$Record->channel.";
         }

         $id .= self::wrap($color);
         $id .= $severity . self::_RESET_FORMAT . ': ';
      }

      // @ Display message (always)
      $message = self::wrap($color) . $message . self::_RESET_FORMAT;
      if ($display > Display::MESSAGE) {
         $message .= PHP_EOL;
      }

      // :
      return "$when$id$message";
   }

   // # Translating
   private function color (Levels $Level): string
   {
      return match ($Level) {
         Levels::Emergency => self::_RED_BOLD,
         Levels::Alert     => self::_MAGENTA_BOLD,
         Levels::Critical  => self::_MAGENTA_FOREGROUND,
         Levels::Error     => self::_RED_BRIGHT_FOREGROUND,
         Levels::Warning   => self::_YELLOW_BOLD,
         Levels::Notice    => self::_CYAN_FOREGROUND,
         Levels::Info      => self::_GREEN_BOLD,
         Levels::Debug     => self::_WHITE_FOREGROUND,
      };
   }
}
