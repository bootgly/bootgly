<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Formatters;


use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;
use function date;
use function floor;
use function json_encode;
use function sprintf;

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
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
    * Each part is gated by its `Display` segment flag — the message is always the content,
    * timestamp / channel / severity / context wrap around it when their flag is enabled.
    *
    * @param Record $Record The record to format.
    * @return string The formatted line.
    */
   public function format (Record $Record): string
   {
      $segments = Display::$segments;

      $color = $this->color($Record->Level);

      // @ [timestamp]
      $when = '';
      if (($segments & Display::TIMESTAMP) !== 0) {
         $seconds = (int) $Record->timestamp;
         $micro = sprintf('%06d', (int) (($Record->timestamp - floor($Record->timestamp)) * 1000000));

         $when = self::wrap(self::_BLACK_BRIGHT_FOREGROUND)
               . '[' . date('Y-m-d\TH:i:s', $seconds) . ".$micro" . date('P', $seconds) . '] '
               . self::_RESET_FORMAT;
      }

      // @ Origin: channel and/or severity, each toggled on its own
      $origin = '';
      if (($segments & Display::CHANNEL) !== 0 && $Record->channel !== '') {
         $origin = $Record->channel;
      }
      if (($segments & Display::SEVERITY) !== 0) {
         if ($origin !== '') {
            $origin .= '.';
         }
         $origin .= self::wrap($color) . $Record->Level->render() . self::_RESET_FORMAT;
      }
      if ($origin !== '') {
         $origin .= ': ';
      }

      // @ Message
      $message = '';
      if (($segments & Display::MESSAGE) !== 0) {
         $message = self::wrap($color)
                  . TemplateEscaped::render($Record->message)
                  . self::_RESET_FORMAT;
      }

      // @ Inline context dump
      $context = '';
      if (($segments & Display::CONTEXT) !== 0 && $Record->context !== []) {
         $encoded = json_encode($Record->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
         if ($encoded !== false) {
            $context = ' '
                     . self::wrap(self::_BLACK_BRIGHT_FOREGROUND) . $encoded . self::_RESET_FORMAT;
         }
      }

      // @ Newline for every selection except the compact inline message
      $eol = $segments === Display::MESSAGE ? '' : PHP_EOL;

      // :
      return "$when$origin$message$context$eol";
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
