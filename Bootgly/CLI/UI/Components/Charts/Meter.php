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


use function round;
use function str_repeat;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Chart;
use Bootgly\CLI\UI\Components\Chart\Symbols;


/**
 * Meter chart — a percentage gauge as a `■` run: filled cells sample the gradient
 * at their own position, empty cells render dim. One-shot, cursor-free.
 */
class Meter extends Chart
{
   use Formattable;


   // * Config
   /** Samples the gradient from the high end down (cool → hot reversed) */
   public bool $inverted;

   // * Data
   /** The gauged percentage (0-100) */
   public float $value;


   public function __construct (Output &$Output)
   {
      parent::__construct($Output);

      // * Config
      $this->inverted = false;

      // * Data
      $this->value = 0.0;
   }


   public function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      // !
      $width = $this->width ?? 20;

      // ? Clamp
      $value = $this->value;
      if ($value < 0.0) {
         $value = 0.0;
      }
      else if ($value > 100.0) {
         $value = 100.0;
      }

      // @
      $frame = '';
      for ($cell = 1; $cell <= $width; $cell++) {
         $position = (int) round($cell * 100 / $width);

         // ? Empty cells render dim from here on
         if ($value < $position) {
            $frame .= self::wrap(self::_BLACK_BRIGHT_FOREGROUND)
               . str_repeat(Symbols::METER, $width - $cell + 1);

            break;
         }

         $sampled = $this->inverted ? 100 - $position : $position;
         $frame .= $this->Gradient->sample($sampled) . Symbols::METER;
      }

      // :
      return $this->flush($frame . self::_RESET_FORMAT . "\n", $mode);
   }
}
