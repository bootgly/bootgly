<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components;


use function rewind;
use function round;
use function stream_get_contents;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Chart\Gradient;


/**
 * ANSI chart base — shared definitions for the concrete chart types in `Charts/`:
 * series storage, bounds measuring, level scaling, gradient coloring and the
 * write/return output pipeline. Types render cursor-free frames unless stated.
 */
abstract class Chart extends Component
{
   protected Output $Output;

   // * Config
   /** Frame columns — `null` derives from the terminal or the series */
   public null|int $width;
   /** Decimal places for formatted values */
   public int $precision;
   /** Fixed scale top — `null` scales to the measured series maximum */
   public null|float $ceiling;
   /** Color gradient sampled by the types — defaults to solid cyan */
   public Gradient $Gradient {
      get {
         if (isSet($this->Gradient) === false) {
            $this->Gradient = new Gradient(['#00ffff']);
         }

         return $this->Gradient;
      }
   }

   // * Data
   /** @var array<string,float> label ⇒ value */
   public array $series;

   // * Metadata
   public private(set) float $max;
   public private(set) float $min;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->width = null;
      $this->precision = 1;
      $this->ceiling = null;

      // * Data
      $this->series = [];

      // * Metadata
      $this->max = 0.0;
      $this->min = 0.0;
   }


   /**
    * Measures value bounds into the `max` / `min` metadata.
    *
    * @param null|array<int|string,float> $values The values to measure — `null` measures the series.
    */
   protected function measure (null|array $values = null): void
   {
      // !
      $max = null;
      $min = null;

      // @@
      foreach ($values ?? $this->series as $value) {
         if ($max === null || $value > $max) {
            $max = $value;
         }
         if ($min === null || $value < $min) {
            $min = $value;
         }
      }

      // * Metadata
      $this->max = (float) ($max ?? 0.0);
      $this->min = (float) ($min ?? 0.0);
   }

   /**
    * Scales a value to a discrete level.
    *
    * @param float $value The value to scale.
    * @param int $steps The top level (levels go 0..$steps).
    * @param float $floor The scale bottom (0.0 = absolute scale).
    *
    * @return int The level, clamped to 0..$steps — 0 when the range is empty.
    */
   protected function scale (float $value, int $steps, float $floor = 0.0): int
   {
      // ! Effective top (a fixed ceiling wins over the measured maximum)
      $top = $this->ceiling ?? $this->max;
      $range = $top - $floor;

      // ?
      if ($range <= 0) {
         return 0;
      }

      $level = (int) round(($value - $floor) / $range * $steps);

      // ? Clamp
      if ($level < 0) {
         $level = 0;
      }
      else if ($level > $steps) {
         $level = $steps;
      }

      // :
      return $level;
   }

   /**
    * Flushes a frame through the component output pipeline.
    *
    * @param string $frame The frame to flush.
    * @param int $mode WRITE_OUTPUT writes to the Output; RETURN_OUTPUT returns the
    *  resolved string (an in-memory Output resolves any markup).
    *
    * @return mixed The resolved string on RETURN_OUTPUT; `null` otherwise.
    */
   protected function flush (string $frame, int $mode): mixed
   {
      // ?: Resolved string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         $Memory = new Output('php://memory');
         $Memory->render($frame);

         rewind($Memory->stream);

         return stream_get_contents($Memory->stream);
      }

      // @
      $this->Output->render($frame);

      // :
      return null;
   }
}
