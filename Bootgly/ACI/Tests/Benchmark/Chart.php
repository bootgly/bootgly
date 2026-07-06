<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use function array_keys;
use function ceil;
use function count;
use function explode;
use function floor;
use function htmlspecialchars;
use function implode;
use function intdiv;
use function log10;
use function max;
use function min;
use function number_format;
use function pow;
use function round;
use function sprintf;
use function strlen;


/**
 * Dependency-free multi-panel SVG line chart.
 *
 * Renders one panel per data group (e.g. one per benchmark load), each with
 * its own Y scale, sharing the X axis values (e.g. `server-workers`) and a
 * single legend. Built for `--results=charts` — no external charting
 * dependency, and the SVG renders on GitHub Markdown as-is.
 */
class Chart
{
   /** @var array<string> Series color palette (baseline first). */
   public const array COLORS = [
      '#4E79A7', '#F28E2B', '#59A14F', '#E15759',
      '#B07AA1', '#76B7B2', '#EDC948', '#9C755F',
   ];
   /** Panels per row. */
   private const int COLUMNS = 3;
   /** Panel margins (px): top, right, bottom, left. */
   private const array MARGINS = [44, 16, 40, 72];

   // * Config
   /** Chart title. */
   public protected(set) string $title;
   /** X axis label (e.g. `server-workers`). */
   public protected(set) string $xLabel;
   /** Y axis label (e.g. `req/s`). */
   public protected(set) string $yLabel;
   /** Y axis scale: 'linear' | 'log'. */
   public protected(set) string $yscale;
   /** Panel width (px). */
   public protected(set) int $width;
   /** Panel height (px). */
   public protected(set) int $height;


   public function __construct (
      string $title,
      string $xLabel,
      string $yLabel,
      string $yscale = 'linear',
      int $width = 520,
      int $height = 320
   )
   {
      // * Config
      $this->title = $title;
      $this->xLabel = $xLabel;
      $this->yLabel = $yLabel;
      $this->yscale = $yscale;
      $this->width = $width;
      $this->height = $height;
   }

   /**
    * Render the chart as a complete SVG document.
    *
    * @param array<int,int|float> $x Shared X axis values.
    * @param array<string,array<string,array<int,null|float>>> $panels
    *        Panel title => series label => Y value per X point (null = gap).
    */
   public function render (array $x, array $panels): string
   {
      // ! Layout
      $count = count($panels);
      $columns = min(self::COLUMNS, max(1, $count));
      $rows = (int) ceil($count / $columns);
      $headerHeight = 64;
      $totalWidth = $columns * $this->width;
      $totalHeight = $headerHeight + ($rows * $this->height);

      // ! Legend labels — union of series labels across panels, palette order
      $labels = [];
      foreach ($panels as $series) {
         foreach (array_keys($series) as $label) {
            if (isset($labels[$label]) === false) {
               $labels[$label] = self::COLORS[count($labels) % count(self::COLORS)];
            }
         }
      }

      $svg = [];
      $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $totalWidth . ' ' . $totalHeight . '" '
         . 'font-family="ui-sans-serif, system-ui, sans-serif" font-size="12">';
      $svg[] = '<rect width="100%" height="100%" fill="#ffffff"/>';

      // @ Title + legend
      $title = htmlspecialchars($this->title);
      $svg[] = '<text x="' . ($totalWidth / 2) . '" y="24" text-anchor="middle" '
         . 'font-size="16" font-weight="bold" fill="#1a1a1a">' . $title . '</text>';

      $legendX = 24;
      foreach ($labels as $label => $color) {
         $name = htmlspecialchars((string) $label);
         $svg[] = '<line x1="' . $legendX . '" y1="44" x2="' . ($legendX + 22) . '" y2="44" '
            . 'stroke="' . $color . '" stroke-width="3"/>';
         $svg[] = '<text x="' . ($legendX + 28) . '" y="48" fill="#333333">' . $name . '</text>';
         $legendX += 40 + (strlen((string) $label) * 7);
      }

      // @@ Panels
      $index = 0;
      foreach ($panels as $panelTitle => $series) {
         $column = $index % $columns;
         $row = intdiv($index, $columns);
         $offsetX = $column * $this->width;
         $offsetY = $headerHeight + ($row * $this->height);

         $svg[] = '<g transform="translate(' . $offsetX . ',' . $offsetY . ')">';
         $svg[] = $this->plot((string) $panelTitle, $x, $series, $labels);
         $svg[] = '</g>';

         $index++;
      }

      $svg[] = '</svg>';

      // : Complete SVG document
      return implode("\n", $svg) . "\n";
   }

   /**
    * Plot one panel (axes, grid, series) at origin 0,0.
    *
    * @param string $title
    * @param array<int,int|float> $x
    * @param array<string,array<int,null|float>> $series
    * @param array<string,string> $labels Series label => color.
    */
   private function plot (string $title, array $x, array $series, array $labels): string
   {
      // ? Nothing to plot without X points
      if ($x === []) {
         return '';
      }

      // ! Plot area
      [$top, $right, $bottom, $left] = self::MARGINS;
      $plotWidth = $this->width - $left - $right;
      $plotHeight = $this->height - $top - $bottom;

      // ! Value ranges
      $xMin = (float) min($x);
      $xMax = (float) max($x);
      $xSpan = $xMax - $xMin ?: 1.0;

      $yMax = 0.0;
      $yMin = null;
      foreach ($series as $values) {
         foreach ($values as $value) {
            if ($value === null) {
               continue;
            }
            $yMax = max($yMax, $value);
            $yMin = $yMin === null ? $value : min($yMin, $value);
         }
      }
      $yMin ??= 0.0;

      // # Y scale bounds
      if ($this->yscale === 'log') {
         $floor = $yMin > 0.0 ? pow(10, floor(log10($yMin))) : 0.1;
         $ceiling = $yMax > 0.0 ? pow(10, ceil(log10($yMax))) : 1.0;
      }
      else {
         $floor = 0.0;
         $ceiling = self::snap($yMax > 0.0 ? $yMax : 1.0);
      }

      // ! Coordinate mappers
      $mapX = fn (float $value): float
         => $left + (($value - $xMin) / $xSpan) * $plotWidth;
      $mapY = $this->yscale === 'log'
         ? fn (float $value): float
            => $top + $plotHeight - ((log10(max($value, $floor)) - log10($floor)) / (log10($ceiling) - log10($floor))) * $plotHeight
         : fn (float $value): float
            => $top + $plotHeight - (($value - $floor) / ($ceiling - $floor ?: 1.0)) * $plotHeight;

      $svg = [];

      // @ Panel title
      $svg[] = '<text x="' . ($left + $plotWidth / 2) . '" y="' . ($top - 16) . '" text-anchor="middle" '
         . 'font-weight="bold" fill="#333333">' . htmlspecialchars($title) . '</text>';

      // @ Y grid + ticks
      $ticks = $this->grade($floor, $ceiling);
      foreach ($ticks as $tick) {
         $y = $mapY($tick);
         $svg[] = '<line x1="' . $left . '" y1="' . $y . '" x2="' . ($left + $plotWidth) . '" y2="' . $y . '" '
            . 'stroke="#e5e5e5" stroke-width="1"/>';
         $svg[] = '<text x="' . ($left - 8) . '" y="' . ($y + 4) . '" text-anchor="end" fill="#666666" font-size="10">'
            . self::format($tick) . '</text>';
      }

      // @ X ticks (subset when dense)
      $step = max(1, (int) ceil(count($x) / 8));
      foreach ($x as $i => $value) {
         if ($i % $step !== 0 && $i !== count($x) - 1) {
            continue;
         }
         $px = $mapX((float) $value);
         $svg[] = '<text x="' . $px . '" y="' . ($top + $plotHeight + 16) . '" text-anchor="middle" '
            . 'fill="#666666" font-size="10">' . self::format((float) $value) . '</text>';
      }

      // @ Axes
      $svg[] = '<line x1="' . $left . '" y1="' . $top . '" x2="' . $left . '" y2="' . ($top + $plotHeight) . '" '
         . 'stroke="#999999" stroke-width="1"/>';
      $svg[] = '<line x1="' . $left . '" y1="' . ($top + $plotHeight) . '" x2="' . ($left + $plotWidth) . '" y2="' . ($top + $plotHeight) . '" '
         . 'stroke="#999999" stroke-width="1"/>';

      // @ Axis labels
      $svg[] = '<text x="' . ($left + $plotWidth / 2) . '" y="' . ($this->height - 6) . '" text-anchor="middle" '
         . 'fill="#666666" font-size="11">' . htmlspecialchars($this->xLabel) . '</text>';
      $svg[] = '<text x="14" y="' . ($top + $plotHeight / 2) . '" text-anchor="middle" fill="#666666" font-size="11" '
         . 'transform="rotate(-90 14 ' . ($top + $plotHeight / 2) . ')">' . htmlspecialchars($this->yLabel) . '</text>';

      // @@ Series — polyline segments broken at null gaps
      foreach ($series as $label => $values) {
         $color = $labels[$label];

         $segments = [];
         $points = [];
         foreach ($x as $i => $xValue) {
            $value = $values[$i] ?? null;

            if ($value === null) {
               if ($points !== []) {
                  $segments[] = $points;
                  $points = [];
               }
               continue;
            }

            $points[] = sprintf('%.1f,%.1f', $mapX((float) $xValue), $mapY($value));
         }
         if ($points !== []) {
            $segments[] = $points;
         }

         foreach ($segments as $segment) {
            if (count($segment) === 1) {
               [$cx, $cy] = explode(',', $segment[0]);
               $svg[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="3" fill="' . $color . '"/>';
               continue;
            }

            $svg[] = '<polyline points="' . implode(' ', $segment) . '" fill="none" '
               . 'stroke="' . $color . '" stroke-width="2"/>';
         }
      }

      // : Panel markup
      return implode("\n", $svg);
   }

   /**
    * Snap a maximum up to a "nice" axis ceiling (1/2/5 × 10^k).
    */
   private static function snap (float $value): float
   {
      $magnitude = pow(10, floor(log10($value)));
      $normalized = $value / $magnitude;

      $factor = match (true) {
         $normalized <= 1.0 => 1.0,
         $normalized <= 2.0 => 2.0,
         $normalized <= 5.0 => 5.0,
         default => 10.0,
      };

      // : Snapped ceiling
      return $factor * $magnitude;
   }

   /**
    * Grade the Y axis into tick values — 5 linear steps, or powers of 10
    * when the scale is logarithmic.
    *
    * @return array<int,float>
    */
   private function grade (float $floor, float $ceiling): array
   {
      $ticks = [];

      // # log — powers of 10 from floor to ceiling
      if ($this->yscale === 'log') {
         for ($power = log10($floor); $power <= log10($ceiling) + 0.001; $power++) {
            $ticks[] = pow(10, $power);
         }
      }
      // # linear — 5 even steps
      else {
         $step = ($ceiling - $floor) / 5;

         for ($i = 0; $i <= 5; $i++) {
            $ticks[] = $floor + ($step * $i);
         }
      }

      // : Tick values
      return $ticks;
   }

   /**
    * Format an axis tick value compactly (1.2M, 45k, 512).
    */
   private static function format (float $value): string
   {
      // ?: Millions
      if ($value >= 1_000_000) {
         return number_format($value / 1_000_000, 1) . 'M';
      }
      // ?: Thousands
      if ($value >= 10_000) {
         return number_format($value / 1_000, 0) . 'k';
      }
      // ?: Small values
      if ($value >= 100) {
         return number_format($value, 0);
      }

      // : Fractional values
      return (string) round($value, 2);
   }
}
