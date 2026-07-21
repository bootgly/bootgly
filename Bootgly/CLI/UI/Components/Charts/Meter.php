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


use function max;
use function mb_strlen;
use function preg_replace;
use function round;
use function str_repeat;

use Bootgly\ABI\Code\__String;
use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Chart;
use Bootgly\CLI\UI\Components\Chart\Symbols;


/**
 * Meter chart — a percentage gauge as a `■` run: filled cells sample the gradient
 * at their own position, empty cells render dim. Optional corner labels frame the
 * bar — heading/summary above, caption/note below. One-shot, cursor-free.
 */
class Meter extends Chart
{
   use Formattable;


   // * Config
   /** Samples the gradient from the high end down (cool → hot reversed) */
   public bool $inverted;
   /** Label above the bar, left-aligned (accepts markup) */
   public string $heading;
   /** Label above the bar, right-aligned (accepts markup) */
   public string $summary;
   /** Label below the bar, left-aligned (accepts markup) */
   public string $caption;
   /** Label below the bar, right-aligned (accepts markup) */
   public string $note;

   // * Data
   /** The gauged percentage (0-100) */
   public float $value;


   public function __construct (Output $Output)
   {
      parent::__construct($Output);

      // * Config
      $this->inverted = false;
      $this->heading = '';
      $this->summary = '';
      $this->caption = '';
      $this->note = '';

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

      // @ Bar
      $bar = '';
      for ($cell = 1; $cell <= $width; $cell++) {
         $position = (int) round($cell * 100 / $width);

         // ? Empty cells render dim from here on
         if ($value < $position) {
            $bar .= self::wrap(self::_BLACK_BRIGHT_FOREGROUND)
               . str_repeat(Symbols::METER, $width - $cell + 1);

            break;
         }

         $sampled = $this->inverted ? 100 - $position : $position;
         $bar .= $this->Gradient->sample($sampled) . Symbols::METER;
      }

      // @ Frame — corner labels line up against the bar width
      $frame = $this->align($this->heading, $this->summary, $width)
         . $bar . self::_RESET_FORMAT . "\n"
         . $this->align($this->caption, $this->note, $width);

      // :
      return $this->flush($frame, $mode);
   }

   /**
    * Align a left/right label pair into a row spanning the given columns.
    */
   private function align (string $left, string $right, int $width): string
   {
      // ?
      if ($left === '' && $right === '') {
         return '';
      }

      // ! Resolve markup upfront — the row gap is measured on visible columns
      $left = TemplateEscaped::render($left);
      $right = TemplateEscaped::render($right);

      // ?: Left label only — no gap to fill
      if ($right === '') {
         return "{$left}\n";
      }

      $gap = max(1, $width - $this->gauge($left) - $this->gauge($right));

      // :
      return $left . str_repeat(' ', $gap) . "{$right}\n";
   }

   /**
    * Gauge the visible columns of a resolved label (escapes occupy none).
    */
   private function gauge (string $label): int
   {
      // :
      return mb_strlen((string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $label));
   }
}
