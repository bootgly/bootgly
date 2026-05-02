<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ\Outputs;


use function count;
use function explode;
use function implode;
use function str_starts_with;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Differ\Output;


/**
 * Decorator that wraps any `Output` builder and applies ANSI escape
 * sequences to the rendered diff string.
 */
final class Escaped implements Output
{
   use Formattable;


   // * Config
   public Output $Output;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;
   }

   public function render (array $diff): string
   {
      $plain = $this->Output->render($diff);

      if ($plain === '') {
         return '';
      }

      return $this->colorize($plain);
   }

   private function colorize (string $plain): string
   {
      $lines  = explode("\n", $plain);
      $last   = count($lines) - 1;
      $result = [];

      foreach ($lines as $i => $line) {
         if ($i === $last && $line === '') {
            $result[] = '';
            continue;
         }

         $result[] = $this->paint($line);
      }

      return implode("\n", $result);
   }

   private function paint (string $line): string
   {
      if (str_starts_with($line, '--- ') || str_starts_with($line, '+++ ')) {
         return self::wrap(self::_BOLD_STYLE) . $line . self::_RESET_FORMAT;
      }

      if (str_starts_with($line, '@@')) {
         return self::wrap(self::_CYAN_FOREGROUND) . $line . self::_RESET_FORMAT;
      }

      if (str_starts_with($line, '+')) {
         return self::wrap(self::_GREEN_FOREGROUND) . $line . self::_RESET_FORMAT;
      }

      if (str_starts_with($line, '-')) {
         return self::wrap(self::_RED_FOREGROUND) . $line . self::_RESET_FORMAT;
      }

      if (str_starts_with($line, '\\')) {
         return self::wrap(self::_YELLOW_FOREGROUND, self::_DIM_STYLE) . $line . self::_RESET_FORMAT;
      }

      return $line;
   }
}
