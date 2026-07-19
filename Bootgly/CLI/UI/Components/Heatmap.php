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


use const BOOTGLY_TTY;
use function array_chunk;
use function getmypid;
use function implode;
use function intdiv;
use function is_string;
use function max;
use function mb_strlen;
use function microtime;
use function min;
use function preg_replace;
use function str_repeat;
use function substr_count;

use Bootgly\ABI\Data\__String;
use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Chart\Gradient;
use Bootgly\CLI\UI\Components\Chart\Symbols;


/**
 * Heatmap — a dense wrapped grid of state-colored cells, one `■` per entry in
 * execution order. Optional corner labels frame the grid — heading/summary
 * above, caption/note below. Streams live on interactive terminals through
 * `start()` / `feed()` / `finish()`; renders one-shot frames otherwise.
 */
class Heatmap extends Component
{
   use Formattable;


   protected Output $Output;

   // * Config
   /** Grid columns — `null` follows the terminal, capped at 100 */
   public null|int $width;
   /** @var array<string,string> state ⇒ `#RRGGBB` cell color */
   public array $palette;
   /** Label above the grid, left-aligned (accepts markup) */
   public string $heading;
   /** Label above the grid, right-aligned (accepts markup) */
   public string $summary;
   /** Label below the grid, left-aligned (accepts markup) */
   public string $caption;
   /** Label below the grid, right-aligned (accepts markup) */
   public string $note;
   /** Live streaming — `null` follows the TTY, false forces plain, true forces live */
   public null|bool $decoration;
   /** Minimum seconds between live repaints */
   public float $throttle;

   // * Data
   /** @var array<int,string> Cells in execution order (palette state keys) */
   public array $cells;

   // * Metadata
   /** @var array<string,Gradient> Solid Gradients cached by `#RRGGBB` color */
   private array $Gradients;
   // # Live
   private float $started;
   private float $rendered;
   private bool $finished;
   /** Rows painted by the previous live frame (the grid grows as cells arrive) */
   private int $rows;
   /** PID that started the live grid — forked children inherit it and must stay silent */
   private int $owner;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->width = null;
      $this->palette = [
         'passed'  => '#98c379',
         'failed'  => '#e06c75',
         'skipped' => '#d8d0bb',
      ];
      $this->heading = '';
      $this->summary = '';
      $this->caption = '';
      $this->note = '';
      $this->decoration = null;
      $this->throttle = 0.1;

      // * Data
      $this->cells = [];

      // * Metadata
      $this->Gradients = [];
      // # Live
      $this->started = 0.0;
      $this->rendered = 0.0;
      $this->finished = false;
      $this->rows = 0;
      $this->owner = 0;
   }


   /**
    * Starts the live grid — paints the initial frame and hides the cursor.
    * Plain output starts silently (the final frame renders on `finish()`).
    */
   public function start (): void
   {
      // ?
      if ($this->started > 0.0) {
         return;
      }

      $this->started = microtime(true);
      $this->owner = getmypid() ?: 0;

      // ? Plain output renders the final frame only
      if (($this->decoration ?? BOOTGLY_TTY) === false) {
         return;
      }

      // !
      $this->Output->Cursor->hide();

      // @
      $this->repaint();
   }

   /**
    * Feeds cells into the grid and repaints live on interactive terminals.
    */
   public function feed (string ...$states): self
   {
      // ! Cells grow before any output guard — feeding never loses data
      foreach ($states as $state) {
         $this->cells[] = $state;
      }

      // ? Plain output renders the final frame only — and forked children
      //   inherit the live grid, but only the starting process may paint it
      if (
         ($this->decoration ?? BOOTGLY_TTY) === false
         || $this->started === 0.0
         || $this->finished === true
         || getmypid() !== $this->owner
      ) {
         return $this;
      }
      // ? Throttle
      if (microtime(true) - $this->rendered < $this->throttle) {
         return $this;
      }

      // @
      $this->repaint();

      // :
      return $this;
   }

   /**
    * Finishes the live grid — plain output renders its single frame here.
    */
   public function finish (): void
   {
      // ? Forked children inherit the live grid — only the owner finishes it
      if ($this->finished === true || $this->started === 0.0 || getmypid() !== $this->owner) {
         return;
      }

      $this->finished = true;

      // ? Plain output renders the final frame only
      if (($this->decoration ?? BOOTGLY_TTY) === false) {
         $this->render();

         return;
      }

      // @ Final frame
      $this->repaint();

      $this->Output->Cursor->show();
   }


   /**
    * Render the heatmap grid.
    *
    * @param int $mode `WRITE_OUTPUT` writes the grid to the Output;
    *                  `RETURN_OUTPUT` returns the raw frame instead.
    *
    * @return null|string The raw frame on `RETURN_OUTPUT`; `null` otherwise.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // !
      $this->rendered = microtime(true);

      $width = $this->width
         ?? min(isSet(Terminal::$width) === true ? Terminal::$width : 80, 100);
      $width = max($width, 2);

      // @ Frame
      $frame = $this->align($this->heading, $this->summary, $width);

      // # Grid — one `■` per cell (wrapped, each spanning 2 columns)
      if ($this->cells !== []) {
         $columns = max(1, intdiv($width + 1, 2));
         foreach (array_chunk($this->cells, $columns) as $chunk) {
            $cells = [];
            foreach ($chunk as $state) {
               $cells[] = $this->paint($state) . Symbols::METER;
            }

            $frame .= implode(' ', $cells) . self::_RESET_FORMAT . "\n";
         }
      }

      $frame .= $this->align($this->caption, $this->note, $width);

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
    * Repaints the grid over the previous live frame (the grid grows as cells arrive).
    */
   private function repaint (): void
   {
      // ? Clear the previous frame
      if ($this->rows > 0) {
         $this->Output->Cursor->up($this->rows, column: 1);
         $this->Output->Text->clear(lines: $this->rows);
      }

      $frame = $this->render(self::RETURN_OUTPUT);
      $frame = is_string($frame) ? $frame : '';

      $this->rows = substr_count($frame, "\n");

      $this->Output->write($frame);
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

      $gap = max(1, $width - $this->measure($left) - $this->measure($right));

      // :
      return $left . str_repeat(' ', $gap) . "{$right}\n";
   }

   /**
    * Measure the visible columns of a resolved label (escapes occupy none).
    */
   private function measure (string $label): int
   {
      // :
      return mb_strlen((string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $label));
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

   public function __destruct ()
   {
      $this->finish();
   }
}
