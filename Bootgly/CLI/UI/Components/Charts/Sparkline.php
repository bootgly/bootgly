<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Charts;


use function intdiv;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\CLI\UI\Components\Chart;
use Bootgly\CLI\UI\Components\Chart\Symbols;


/**
 * Sparkline chart — the series as one `▁▂▃▄▅▆▇█` line, each glyph colored by its
 * level through the gradient. One-shot, cursor-free (identical on TTYs and pipes).
 */
class Sparkline extends Chart
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

      $top = 7;
      $flat = ($this->ceiling ?? $this->max) - $this->min <= 0;

      // @
      $line = '';
      foreach ($this->series as $value) {
         // ? Flat series render at the middle level
         $level = $flat ? intdiv($top, 2) : $this->scale($value, $top, floor: $this->min);

         $line .= $this->Gradient->sample(intdiv($level * 100, $top)) . Symbols::RAMP[$level];
      }

      // :
      return $this->flush($line . self::_RESET_FORMAT . "\n", $mode);
   }
}
