<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components;


use function count;
use function max;
use function mb_strlen;
use function min;
use function number_format;
use function rewind;
use function round;
use function str_pad;
use function str_repeat;
use function stream_get_contents;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Chart\Plots;


/**
 * ANSI chart — one-shot, cursor-free string render (identical on TTYs and pipes).
 * Sparkline plots the series as one `▁▂▃▄▅▆▇█` line; Bars plots one labeled
 * `█`-run per entry, scaled to the widest value.
 */
class Chart extends Component
{
   /** Sparkline glyph ramp (8 levels) */
   protected const array LEVELS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];


   private Output $Output;

   // * Config
   public Plots $Plots;
   /** Plot width in columns (Bars) — null derives from the terminal width */
   public null|int $width;
   public string $color;
   public int $precision;

   // * Data
   /** @var array<string,float> label ⇒ value */
   public array $series;

   // * Metadata
   public private(set) float $max;
   public private(set) float $min;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;

      // * Config
      $this->Plots = Plots::Sparkline;
      $this->width = null;
      $this->color = '@#Cyan:';
      $this->precision = 1;

      // * Data
      $this->series = [];

      // * Metadata
      $this->max = 0.0;
      $this->min = 0.0;
   }


   /**
    * Renders the chart.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return mixed
    */
   public function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      // ?
      if ($this->series === []) {
         return null;
      }

      // ! Series bounds
      $this->max = max($this->series);
      $this->min = min($this->series);

      $frame = match ($this->Plots) {
         Plots::Sparkline => $this->sparkle(),
         Plots::Bars => $this->plot()
      };

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         // ! php://memory resolves the markup — the returned string is final output
         $Memory = new Output('php://memory');
         $Memory->render($frame);
         rewind($Memory->stream);

         return (string) stream_get_contents($Memory->stream);
      }

      $this->Output->render($frame);

      return null;
   }

   /**
    * Plots the series as a one-line sparkline.
    *
    * @return string
    */
   private function sparkle (): string
   {
      $range = $this->max - $this->min;
      $top = count(self::LEVELS) - 1;

      $line = '';
      foreach ($this->series as $value) {
         // ? Flat series render mid-level
         $level = $range > 0.0
            ? (int) round((($value - $this->min) / $range) * $top)
            : (int) ($top / 2);

         $line .= self::LEVELS[$level];
      }

      // :
      return "{$this->color}{$line}@;\n";
   }

   /**
    * Plots the series as labeled horizontal bars.
    *
    * @return string
    */
   private function plot (): string
   {
      // ! Layout — label column, bar area, formatted values
      $label_width = 0;
      foreach ($this->series as $label => $value) {
         $length = mb_strlen((string) $label);

         if ($length > $label_width) {
            $label_width = $length;
         }
      }

      $value_width = mb_strlen(number_format($this->max, $this->precision, '.', ''));
      $width = $this->width ?? ((int) Terminal::$width - $label_width - $value_width - 6);
      if ($width < 8) {
         $width = 8;
      }

      // @ One row per entry — `█`-run scaled to the widest value
      $frame = '';
      foreach ($this->series as $label => $value) {
         $units = $this->max > 0.0
            ? (int) round(($value / $this->max) * $width)
            : 0;

         $bar = str_repeat('█', $units);
         $padded = str_pad((string) $label, $label_width);
         $formatted = number_format($value, $this->precision, '.', '');

         $frame .= "{$padded} {$this->color}{$bar}@; {$formatted}\n";
      }

      // :
      return $frame;
   }
}
