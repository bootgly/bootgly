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


use function array_fill;
use function array_keys;
use function array_slice;
use function array_sum;
use function array_values;
use function arsort;
use function count;
use function max;
use function min;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Grid\Cell;


/**
 * Grid — a weighted track layout placing Frames on the screen (btop-style
 * dashboards): rows and columns are arrays of track weights, and each placed
 * Frame anchors at a track spanning one or more of them. Track sizes always
 * sum exactly to the grid rectangle (largest-remainder distribution).
 */
class Grid extends Component
{
   private Output $Output;

   // * Config
   // # Geometry (outer rectangle; null width/height tracks the terminal size)
   /** Top screen row (1-based) */
   public int $row;
   /** Left screen column (1-based) */
   public int $column;
   /** Outer width, in columns — `null` tracks the terminal columns */
   public null|int $width;
   /** Outer height, in rows — `null` tracks the terminal lines */
   public null|int $height;
   // # Tracks
   /** @var array<int,int|float> Row track weights */
   public array $rows;
   /** @var array<int,int|float> Column track weights */
   public array $columns;
   /** Blank columns/lines between cells */
   public int $gap;

   // * Data
   /** @var array<int,Cell> The placed frames, in paint order */
   public private(set) array $Cells;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      // # Geometry
      $this->row = 1;
      $this->column = 1;
      $this->width = null;
      $this->height = null;
      // # Tracks
      $this->rows = [1];
      $this->columns = [1];
      $this->gap = 0;

      // * Data
      $this->Cells = [];
   }


   /**
    * Places a Frame over the grid tracks — the Frame geometry is computed and
    * assigned immediately, so its inner metrics can be read right after (to
    * size hosted components). Overlaps are allowed and paint in place order.
    *
    * @param Frame $Frame The Frame to place.
    * @param int $row The anchor row track (1-based).
    * @param int $column The anchor column track (1-based).
    * @param int $rowspan The row tracks to span.
    * @param int $colspan The column tracks to span.
    *
    * @return self
    */
   public function place (
      Frame $Frame,
      int $row,
      int $column,
      int $rowspan = 1,
      int $colspan = 1
   ): self
   {
      // * Data
      $this->Cells[] = new Cell($Frame, $row, $column, $rowspan, $colspan);

      // @
      $this->arrange();

      // :
      return $this;
   }

   /**
    * Arranges the placed Frames — distributes the track sizes over the grid
    * rectangle and assigns each Frame its screen geometry (pure geometry, no
    * rendering). Placements are clamped into the track grid.
    *
    * @return void
    */
   public function arrange (): void
   {
      // ! Grid rectangle
      $width = $this->width ?? Terminal::$columns;
      $height = $this->height ?? Terminal::$lines;

      // ! Track sizes — always sum exactly to the rectangle
      $widths = $this->distribute($this->columns, $width);
      $heights = $this->distribute($this->rows, $height);

      // @@ Assign each placed Frame's geometry from its tracks
      foreach ($this->Cells as $Cell) {
         // ? Clamp the placement into the track grid
         $row = min(max(1, $Cell->row), count($heights));
         $column = min(max(1, $Cell->column), count($widths));
         $rowspan = min(max(1, $Cell->rowspan), count($heights) - $row + 1);
         $colspan = min(max(1, $Cell->colspan), count($widths) - $column + 1);

         $Frame = $Cell->Frame;

         $Frame->row = $this->row + (int) array_sum(array_slice($heights, 0, $row - 1));
         $Frame->column = $this->column + (int) array_sum(array_slice($widths, 0, $column - 1));
         $Frame->height = (int) array_sum(array_slice($heights, $row - 1, $rowspan));
         $Frame->width = (int) array_sum(array_slice($widths, $column - 1, $colspan));

         // ? Gap — trimmed off the sides that face another cell
         if ($this->gap > 0) {
            if ($column - 1 + $colspan < count($widths)) {
               $Frame->width = max(0, $Frame->width - $this->gap);
            }
            if ($row - 1 + $rowspan < count($heights)) {
               $Frame->height = max(0, $Frame->height - $this->gap);
            }
         }
      }
   }

   /**
    * Resizes the grid rectangle — the screen clears (wiping shrink artifacts),
    * every placed Frame invalidates (content buffers preserved) and the layout
    * repaints. The signature matches the `Screen::watch` resize handler.
    *
    * @param int $columns The new width, in columns.
    * @param int $lines The new height, in lines.
    *
    * @return void
    */
   public function resize (int $columns, int $lines): void
   {
      // * Config
      $this->width = $columns;
      $this->height = $lines;

      // @ Wipe the screen and force full repaints
      $this->Output->clear();
      foreach ($this->Cells as $Cell) {
         $Cell->Frame->invalidate();
      }

      $this->render();
   }

   /**
    * Renders the layout — arranges the track geometry and renders every placed
    * Frame, in placement order (painter's order).
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      $this->arrange();

      // ?: Rectangles as string, in paint order
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         $output = '';
         foreach ($this->Cells as $Cell) {
            $output .= $Cell->Frame->render(self::RETURN_OUTPUT);
         }

         // :
         return $output;
      }

      // @@ Paint every placed Frame
      foreach ($this->Cells as $Cell) {
         $Cell->Frame->render($mode);
      }

      return null;
   }

   /**
    * Distributes a space over weighted tracks (largest remainder) — the sizes
    * always sum exactly to the space, and remainders spread evenly instead of
    * accumulating on one track.
    *
    * @param array<int,int|float> $weights The track weights.
    * @param int $space The space to distribute.
    *
    * @return array<int,int> The track sizes.
    */
   private function distribute (array $weights, int $space): array
   {
      // ? No tracks behave as a single full track
      if ($weights === []) {
         $weights = [1];
      }
      // ? Negative weights behave as collapsed tracks
      foreach ($weights as $index => $weight) {
         if ($weight < 0) {
            $weights[$index] = 0;
         }
      }
      // ? Weightless tracks distribute equally
      $total = (float) array_sum($weights);
      if ($total <= 0.0) {
         $weights = array_fill(0, count($weights), 1);
         $total = (float) count($weights);
      }

      // ! Floored sizes + remainders
      $sizes = [];
      $remainders = [];
      $allocated = 0;

      foreach (array_values($weights) as $index => $weight) {
         $exact = $space * $weight / $total;
         $size = (int) $exact;

         $sizes[$index] = $size;
         $remainders[$index] = $exact - $size;
         $allocated += $size;
      }

      // @ The largest remainders absorb the leftover, one column/line each
      $leftover = $space - $allocated;
      arsort($remainders);

      foreach (array_keys($remainders) as $index) {
         // ?
         if ($leftover <= 0) {
            break;
         }

         $sizes[$index]++;
         $leftover--;
      }

      // :
      return $sizes;
   }
}
