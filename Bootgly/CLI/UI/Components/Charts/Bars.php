<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Charts;


use function intdiv;
use function mb_strlen;
use function number_format;
use function str_repeat;
use function strlen;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\UI\Components\Chart;


/**
 * Bars chart — one labeled `█`-run per series entry, scaled to the widest value
 * (or the fixed ceiling), each bar colored by its share through the gradient.
 * One-shot, cursor-free (identical on TTYs and pipes).
 */
class Bars extends Chart
{
   use Formattable;


   public function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      // ?
      if ($this->series === []) {
         return null;
      }

      // !
      $this->measure();

      // # Column widths
      $label_width = 0;
      foreach ($this->series as $label => $value) {
         $length = mb_strlen((string) $label);

         if ($length > $label_width) {
            $label_width = $length;
         }
      }

      $top = $this->ceiling ?? $this->max;
      $value_width = strlen(number_format($top, $this->precision, '.', ''));

      $width = $this->width ?? (Terminal::$width - $label_width - $value_width - 6);
      if ($width < 8) {
         $width = 8;
      }

      // @
      $frame = '';
      foreach ($this->series as $label => $value) {
         $units = $this->scale($value, $width);

         $label = (string) $label;
         $padded = $label . str_repeat(' ', $label_width - mb_strlen($label));
         $color = $this->Gradient->sample(intdiv($units * 100, $width));
         $bar = str_repeat('█', $units);
         $reset = self::_RESET_FORMAT;
         $formatted = number_format($value, $this->precision, '.', '');

         $frame .= "{$padded} {$color}{$bar}{$reset} {$formatted}\n";
      }

      // :
      return $this->flush($frame, $mode);
   }
}
