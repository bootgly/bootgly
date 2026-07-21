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


use const BOOTGLY_TTY;
use function array_slice;
use function array_splice;
use function array_values;
use function ceil;
use function count;
use function microtime;
use function round;
use function str_repeat;

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Chart;
use Bootgly\CLI\UI\Components\Chart\Symbols;


/**
 * Graph chart — a multi-row streaming area graph: each cell encodes two consecutive
 * values as a (previous, current) level pair through a `Symbols` map, and each row
 * samples the gradient at its own height. `feed()` slides a value history live on
 * interactive terminals; non-interactive output renders the final frame only.
 */
class Graph extends Chart
{
   use Formattable;


   // * Config
   /** Frame rows */
   public int $height;
   /** Cell symbol set (braille packs 2×4 dot levels per cell) */
   public Symbols $Symbols;
   /** Fills top-down (down-graph) */
   public bool $inverted;
   /** Value history capacity — `null` keeps two values per frame column */
   public null|int $capacity;
   /** Minimum seconds between live repaints */
   public float $throttle;

   // * Data
   /** @var array<int,float> The fed value history */
   public private(set) array $values;

   // * Metadata
   private float $started;
   private float $rendered;
   private bool $finished;


   public function __construct (Output $Output)
   {
      parent::__construct($Output);

      // * Config
      $this->height = 4;
      $this->Symbols = Symbols::Braille;
      $this->inverted = false;
      $this->capacity = null;
      $this->throttle = 0.1;

      // * Data
      $this->values = [];

      // * Metadata
      $this->started = 0.0;
      $this->rendered = 0.0;
      $this->finished = false;
   }


   /**
    * Starts the live graph — reserves the frame rows and hides the cursor.
    * Non-interactive output starts silently (the final frame renders on `finish()`).
    */
   public function start (): void
   {
      // ?
      if ($this->started > 0.0) {
         return;
      }

      $this->started = microtime(true);

      // ? Non-interactive output renders the final frame only
      if (BOOTGLY_TTY === false) {
         return;
      }

      // !
      $this->Output->expand($this->height);
      $this->Output->Cursor->hide();

      // @
      $this->render();
   }

   /**
    * Feeds a value into the history and repaints live on interactive terminals.
    *
    * @param float $value The value to feed.
    *
    * @return self
    */
   public function feed (float $value): self
   {
      // ! History slides before any output guard — feeding never loses data
      $this->values[] = $value;

      $capacity = $this->capacity
         ?? 2 * ($this->width ?? Terminal::$width);
      $count = count($this->values);
      if ($count > $capacity) {
         array_splice($this->values, 0, $count - $capacity);
      }

      // ? Non-interactive output renders the final frame only
      if (BOOTGLY_TTY === false || $this->started === 0.0 || $this->finished === true) {
         return $this;
      }
      // ? Throttle
      if (microtime(true) - $this->rendered < $this->throttle) {
         return $this;
      }

      // @ Repaint relatively over the previous frame
      $this->Output->Cursor->up($this->height, column: 1);
      $this->Output->Text->clear(lines: $this->height);
      $this->render();

      // :
      return $this;
   }

   /**
    * Renders the current frame — `height` rows, cursor-free.
    */
   public function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      // ! Fed history, or the static series
      $values = $this->values !== [] ? $this->values : array_values($this->series);

      $this->rendered = microtime(true);

      // :
      return $this->flush($this->plot($values), $mode);
   }

   /**
    * Finishes the live graph — non-interactive output renders its single frame here.
    */
   public function finish (): void
   {
      // ?
      if ($this->finished === true || $this->started === 0.0) {
         return;
      }

      $this->finished = true;

      // ? Non-interactive output renders the final frame only
      if (BOOTGLY_TTY === false) {
         $this->render();

         return;
      }

      // @ Final frame
      $this->Output->Cursor->up($this->height, column: 1);
      $this->Output->Text->clear(lines: $this->height);
      $this->render();

      $this->Output->Cursor->show();
   }

   /**
    * Plots values as `height` rows of (previous, current) level cells.
    *
    * @param array<int,float> $values The values to plot.
    *
    * @return string
    */
   private function plot (array $values): string
   {
      // !
      $width = $this->width ?? Terminal::$width;
      $map = $this->Symbols->map($this->inverted);

      // # Scale — percentages against the effective top (absolute floor)
      $this->measure($values);

      $percents = [];
      foreach ($values as $value) {
         $percents[] = $this->scale($value, 100);
      }

      // # Cells — right-aligned pairs of consecutive values (2 values per cell)
      $count = count($percents);
      $cells = (int) ceil($count / 2);

      // ? Clip the history to the frame width (keep the most recent values)
      if ($cells > $width) {
         $percents = array_slice($percents, ($cells - $width) * 2);
         $count = count($percents);
         $cells = $width;
      }

      $mod = $this->height === 1 ? 0.3 : 0.1;

      // @@ Rows (0 = top)
      $rows = [];
      for ($row = 0; $row < $this->height; $row++) {
         $high = 100 * ($this->height - $row) / $this->height;
         $low = 100 * ($this->height - $row - 1) / $this->height;

         $line = str_repeat(' ', $width - $cells);

         // @@ Cells — pair (previous, current); odd histories pad the first cell
         for ($cell = 0; $cell < $cells; $cell++) {
            $first = $cell * 2 - ($count % 2);
            $pair = [];

            foreach ([$first, $first + 1] as $index) {
               $percent = $index >= 0 ? $percents[$index] : 0;

               // ? Band the percentage into a 0-4 level for this row
               if ($percent >= $high) {
                  $pair[] = 4;
               }
               else if ($percent <= $low) {
                  $pair[] = 0;
               }
               else {
                  $level = (int) round(($percent - $low) * 4 / ($high - $low) + $mod);
                  $pair[] = $level > 4 ? 4 : ($level < 0 ? 0 : $level);
               }
            }

            $line .= $map[$pair[0] * 5 + $pair[1]];
         }

         $rows[] = $line;
      }

      // @ Compose — top row samples the gradient high end (inverted flips both)
      $frame = '';
      for ($row = 0; $row < $this->height; $row++) {
         $sampled = $this->inverted
            ? (int) (($row + 1) * 100 / $this->height)
            : (int) (100 - $row * 100 / $this->height);

         $line = $this->inverted ? $rows[$this->height - $row - 1] : $rows[$row];

         $frame .= $this->Gradient->sample($sampled) . $line . self::_RESET_FORMAT . "\n";
      }

      // :
      return $frame;
   }

   public function __destruct ()
   {
      $this->finish();
   }
}
