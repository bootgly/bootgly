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


use function array_chunk;
use function count;
use function implode;
use function intdiv;
use function is_string;
use function max;
use function mb_strlen;
use function min;
use function preg_replace;
use function round;
use function rtrim;
use function str_repeat;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Base\Frame\Borders;
use Bootgly\CLI\UI\Components\Chart\Gradient;
use Bootgly\CLI\UI\Components\Chart\Symbols;
use Bootgly\CLI\UI\Components\Charts\Meter;


/**
 * Heatmap — a bordered dashboard card: bold title + score header, a Meter
 * gauge, a dense wrapped grid of state-colored cells and a dim counts footer.
 * One-shot, cursor-free — built for per-suite test dashboards.
 */
class Heatmap extends Component
{
   use Formattable;


   // ! ANSI escape sequence matcher (escape-aware measuring)
   private const string ANSI = '/\e\[[0-9;]*m/';

   protected Output $Output;

   // * Config
   /** Card title (top-left, bold) */
   public string $title;
   /** Card columns — `null` follows the terminal, capped at 100 */
   public null|int $width;
   /** @var array<string,string> state ⇒ `#RRGGBB` cell color */
   public array $palette;
   /** The state gauged by the Meter and the derived score */
   public string $positive;

   // * Data
   /** @var array<int,string> Cells in execution order (palette state keys) */
   public array $cells;
   /** Score percentage (header + Meter) — `null` derives from the positive cells */
   public null|float $score;

   // * Metadata
   /** @var array<string,Gradient> Solid Gradients cached by `#RRGGBB` color */
   private array $Gradients;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->title = '';
      $this->width = null;
      $this->palette = [
         'passed'  => '#e0679f',
         'failed'  => '#e06c75',
         'skipped' => '#d8d0bb',
      ];
      $this->positive = 'passed';

      // * Data
      $this->cells = [];
      $this->score = null;

      // * Metadata
      $this->Gradients = [];
   }


   /**
    * Render the heatmap card.
    *
    * @param int $mode `WRITE_OUTPUT` writes the card to the Output;
    *                  `RETURN_OUTPUT` returns the raw frame instead.
    *
    * @return null|string The raw frame on `RETURN_OUTPUT`; `null` otherwise.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // !
      $width = $this->width
         ?? min(isSet(Terminal::$width) === true ? Terminal::$width : 80, 100);
      $width = max($width, 20);
      $inner = $width - 4;

      $edge = self::wrap(self::_BLACK_BRIGHT_FOREGROUND);
      $reset = self::_RESET_FORMAT;
      $borders = Borders::Round->map();

      // # Score
      $total = count($this->cells);
      $positives = 0;
      foreach ($this->cells as $state) {
         if ($state === $this->positive) {
            $positives++;
         }
      }
      $score = $this->score ?? ($total > 0 ? $positives * 100 / $total : 0.0);

      // @ Frame
      // # Top border
      $frame = "{$edge}{$borders['top-left']}" . str_repeat($borders['top'], $width - 2) . "{$borders['top-right']}{$reset}\n";

      // # Title row — bold title left, bold score right
      $bold = self::wrap(self::_BOLD_STYLE, self::_WHITE_BRIGHT_FOREGROUND);
      $title = "{$bold}{$this->title}{$reset}";
      $percentage = $bold . (int) round($score) . "%{$reset}";
      $gap = max(1, $inner - $this->measure($title) - $this->measure($percentage));
      $frame .= $this->box($title . str_repeat(' ', $gap) . $percentage, $inner, $edge);

      // # Meter row
      $frame .= $this->box('', $inner, $edge);
      $Meter = new Meter($this->Output);
      $Meter->width = $inner;
      $Meter->value = $score;
      $color = $this->palette[$this->positive] ?? null;
      if ($color !== null) {
         $this->Gradients[$color] ??= new Gradient([$color]);
         $Meter->Gradient = $this->Gradients[$color];
      }
      $gauge = $Meter->render(Meter::RETURN_OUTPUT);
      $gauge = rtrim(is_string($gauge) ? $gauge : '', "\n");
      $frame .= $this->box($gauge, $inner, $edge);

      // # Grid rows — each cell spans 2 columns (`■` + gap), last one spans 1
      if ($total > 0) {
         $frame .= $this->box('', $inner, $edge);

         $columns = max(1, intdiv($inner + 1, 2));
         foreach (array_chunk($this->cells, $columns) as $chunk) {
            $cells = [];
            foreach ($chunk as $state) {
               $cells[] = $this->paint($state) . Symbols::METER;
            }

            $frame .= $this->box(implode(' ', $cells) . $reset, $inner, $edge);
         }
      }

      // # Counts row
      $frame .= $this->box('', $inner, $edge);
      $frame .= $this->box(self::wrap(self::_DIM_STYLE) . "{$positives} / {$total}{$reset}", $inner, $edge);

      // # Bottom border
      $frame .= "{$edge}{$borders['bottom-left']}" . str_repeat($borders['bottom'], $width - 2) . "{$borders['bottom-right']}{$reset}\n";

      // ?: Return — raw frame, the host positions it
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $frame;
      }

      // @ Write
      $this->Output->write($frame);

      // :
      return null;
   }

   /**
    * Box a content row between the card edges, padded to the inner width.
    */
   private function box (string $content, int $inner, string $edge): string
   {
      $reset = self::_RESET_FORMAT;
      $padding = str_repeat(' ', max(0, $inner - $this->measure($content)));

      // :
      return "{$edge}│{$reset} {$content}{$padding} {$edge}│{$reset}\n";
   }

   /**
    * Measure the visible columns of a segment string (escapes occupy none).
    */
   private function measure (string $string): int
   {
      // :
      return mb_strlen((string) preg_replace(self::ANSI, '', $string));
   }

   /**
    * Paint a cell state with its palette color — unknown states render dim.
    */
   private function paint (string $state): string
   {
      $color = $this->palette[$state] ?? null;

      // ?: Unknown state — dim
      if ($color === null) {
         return self::wrap(self::_BLACK_BRIGHT_FOREGROUND);
      }

      $this->Gradients[$color] ??= new Gradient([$color]);

      // :
      return $this->Gradients[$color]->sample(0);
   }
}
