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
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;
use function array_splice;
use function count;
use function explode;
use function intdiv;
use function max;
use function mb_str_split;
use function preg_match;
use function preg_split;
use function rewind;
use function round;
use function str_ends_with;
use function stream_get_contents;
use function substr;

use Bootgly\ABI\Code\__String;
use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


/**
 * Scrollable content band — an internally buffered window of rows rendered into a
 * fixed screen band, with its own scrollbar. Content fed while the area is stuck to
 * the bottom auto-follows; scrolling up holds the position while new rows arrive
 * (the scrollbar tracks it). Non-interactive output degrades to plain writes.
 */
class Scrollarea extends Component
{
   use Formattable;


   public Output $Output;

   // * Config
   /** Band top screen row (1-based) */
   public int $row;
   /** Visible rows (band height) */
   public int $rows;
   /** Band width, in columns */
   public int $width;
   /** Max buffered visual rows — older rows are dropped */
   public int $capacity;
   /** Render the scrollbar on the right edge column */
   public bool $scrollbar;

   // * Data
   /** @var array<int,string> Buffered visual rows (oldest first, painted bytes) */
   public private(set) array $buffer;

   // * Metadata
   /** First visible buffered row */
   public private(set) int $first;
   /** Following the newest rows (auto-scroll on feed)? */
   public private(set) bool $stuck;
   /** Pointer over the thumb (highlight)? */
   public private(set) bool $hovered;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->row = 1;
      $this->rows = 10;
      $this->width = (int) Terminal::$width;
      $this->capacity = 1000;
      $this->scrollbar = true;

      // * Data
      $this->buffer = [];

      // * Metadata
      $this->first = 0;
      $this->stuck = true;
      $this->hovered = false;
   }


   /**
    * Feeds content into the buffer (Template markup supported). Logical lines are
    * wrapped into visual rows at the band width; when stuck to the bottom the view
    * follows the newest rows, otherwise the position holds.
    *
    * @param string $content The content (Template markup supported).
    *
    * @return void
    */
   public function feed (string $content): void
   {
      // ? Non-interactive output writes plainly
      if (BOOTGLY_TTY === false) {
         $this->Output->render("{$content}\n");

         return;
      }

      // ! php://memory resolves the markup
      $Memory = new Output('php://memory');
      $Memory->render($content);
      rewind($Memory->stream);
      $painted = (string) stream_get_contents($Memory->stream);

      // @ Chunk each logical line into visual rows
      foreach (explode("\n", $painted) as $line) {
         foreach ($this->chunk($line) as $visual) {
            $this->buffer[] = $visual;
         }
      }

      // ? Drop the oldest rows over capacity
      $overflow = count($this->buffer) - $this->capacity;
      if ($overflow > 0) {
         array_splice($this->buffer, 0, $overflow);

         $this->first = max(0, $this->first - $overflow);
      }

      // ? Follow the newest rows
      if ($this->stuck === true) {
         $this->first = max(0, count($this->buffer) - $this->rows);
      }

      $this->render();
   }

   /**
    * Scrolls the view by a row delta (negative = up). Scrolling back to the last
    * row re-sticks the view to the bottom.
    *
    * @param int $delta Rows to scroll (negative scrolls up, positive scrolls down).
    *
    * @return void
    */
   public function scroll (int $delta): void
   {
      $bottom = max(0, count($this->buffer) - $this->rows);

      $aimed = $this->first + $delta;
      if ($aimed < 0) {
         $aimed = 0;
      }
      if ($aimed > $bottom) {
         $aimed = $bottom;
      }

      // * Metadata
      $this->first = $aimed;
      $this->stuck = ($aimed === $bottom);

      $this->render();
   }

   /**
    * Sticks the view back to the bottom (newest rows).
    *
    * @return void
    */
   public function stick (): void
   {
      // * Metadata
      $this->first = max(0, count($this->buffer) - $this->rows);
      $this->stuck = true;

      $this->render();
   }

   /**
    * Tests which band part sits at a screen coordinate.
    *
    * @param int $column The screen column (1-based).
    * @param int $line The screen line (1-based).
    *
    * @return null|string `'thumb'`, `'track'`, `'content'` — or null outside the band.
    */
   public function hit (int $column, int $line): null|string
   {
      // ? Outside the band
      if ($line < $this->row || $line >= $this->row + $this->rows) {
         return null;
      }
      if ($column < 1 || $column > $this->width) {
         return null;
      }

      // ? The scrollbar column (rendered when the buffer overflows the band)
      if (
         $this->scrollbar === true && $column === $this->width
         && count($this->buffer) > $this->rows
      ) {
         [$start, $size] = $this->measure();

         $offset = $line - $this->row;

         // :
         return ($offset >= $start && $offset < $start + $size) ? 'thumb' : 'track';
      }

      // :
      return 'content';
   }

   /**
    * Aims the view so the scrollbar thumb centers on a screen line (drag or
    * track click).
    *
    * @param int $line The screen line (1-based).
    *
    * @return void
    */
   public function aim (int $line): void
   {
      $total = count($this->buffer);

      // ? Nothing to scroll
      if ($total <= $this->rows) {
         return;
      }

      [, $size] = $this->measure();

      // ! Thumb start aimed by its center
      $span = $this->rows - $size;
      $offset = $line - $this->row - intdiv($size, 2);
      if ($offset < 0) {
         $offset = 0;
      }
      if ($offset > $span) {
         $offset = $span;
      }

      // ! Buffer row from the thumb proportion
      $bottom = $total - $this->rows;
      $aimed = $span > 0 ? (int) round($offset / $span * $bottom) : $bottom;

      // * Metadata
      $this->first = $aimed;
      $this->stuck = ($aimed === $bottom);

      $this->render();
   }

   /**
    * Updates the pointer-over-thumb state — the thumb highlights while hovered.
    *
    * @param bool $over Whether the pointer is over the thumb.
    *
    * @return void
    */
   public function hover (bool $over): void
   {
      // ? Unchanged
      if ($this->hovered === $over) {
         return;
      }

      // * Metadata
      $this->hovered = $over;

      $this->render();
   }

   /**
    * Resets the buffer and the view.
    *
    * @return void
    */
   public function reset (): void
   {
      // * Data
      $this->buffer = [];

      // * Metadata
      $this->first = 0;
      $this->stuck = true;
      $this->hovered = false;
   }

   /**
    * Renders the visible rows into the band (each row repainted in place) and the
    * scrollbar when the buffer overflows the band — the thumb highlights while hovered.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      $total = count($this->buffer);
      $sliding = ($this->scrollbar === true && $total > $this->rows);

      // ! Scrollbar thumb geometry
      $size = 0;
      $start = 0;
      if ($sliding === true) {
         [$start, $size] = $this->measure();
      }

      // ! Band rows
      $lines = [];
      for ($index = 0; $index < $this->rows; $index++) {
         $content = $this->buffer[$this->first + $index] ?? '';

         $bar = '';
         if ($sliding === true) {
            $aimed = ($index >= $start && $index < $start + $size);
            $glyph = $aimed ? '█' : '│';
            $color = ($aimed === true && $this->hovered === true)
               ? self::_WHITE_BRIGHT_FOREGROUND
               : self::_BLACK_BRIGHT_FOREGROUND;

            $bar = self::wrap($color) . $glyph . self::_RESET_FORMAT;
         }

         $lines[] = [$content, $bar];
      }

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         $frame = '';
         foreach ($lines as [$content, $bar]) {
            $frame .= "{$content}{$bar}\n";
         }

         // :
         return $frame;
      }

      // @ Repaint the band rows in place
      foreach ($lines as $index => [$content, $bar]) {
         $this->Output->Cursor->moveTo(line: $this->row + $index, column: 1);
         $this->Output->Text->trim(right: true);
         $this->Output->write($content);

         if ($bar !== '') {
            $this->Output->Cursor->moveTo(line: $this->row + $index, column: $this->width);
            $this->Output->write($bar);
         }
      }

      return null;
   }

   /**
    * Computes the scrollbar thumb geometry from the buffer and view state.
    *
    * @return array{0: int, 1: int} The thumb [start, size], in band rows.
    */
   private function measure (): array
   {
      $total = count($this->buffer);

      $size = max(1, (int) round($this->rows * $this->rows / $total));

      $bottom = max(1, $total - $this->rows);
      $start = (int) round($this->first / $bottom * ($this->rows - $size));

      // :
      return [$start, $size];
   }

   /**
    * Chunks a painted line into visual rows at the band inner width — escape
    * sequences are zero-width and the active SGR carries into the next row.
    * (Named apart from the SGR helper `Formattable::wrap()`.)
    *
    * @param string $painted The painted line (resolved escapes).
    *
    * @return array<int,string>
    */
   private function chunk (string $painted): array
   {
      // ! Inner width (the scrollbar reserves the right edge column)
      $inner = $this->width - ($this->scrollbar === true ? 1 : 0);
      if ($inner < 1) {
         $inner = 1;
      }

      // ! Tokens — escape sequences split apart from the text
      $tokens = (array) preg_split(
         '/(' . substr(__String::ANSI_ESCAPE_SEQUENCE_REGEX, 1, -1) . ')/',
         $painted,
         flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
      );

      $rows = [];
      $current = '';
      $columns = 0;
      $sgr = '';

      // @@ Chunk the visible characters; escapes pass through with zero width
      foreach ($tokens as $token) {
         // ? Escape sequence
         if (preg_match(__String::ANSI_ESCAPE_SEQUENCE_REGEX, (string) $token) === 1) {
            $current .= $token;

            // # The active SGR reopens on the next visual row
            if (str_ends_with((string) $token, 'm') === true) {
               $sgr = (string) $token;
            }

            continue;
         }

         foreach (mb_str_split((string) $token) as $character) {
            // ? Stray carriage returns never enter the buffer
            if ($character === "\r") {
               continue;
            }

            $current .= $character;
            $columns++;

            // ? Row full — close the SGR and carry it over
            if ($columns === $inner) {
               if ($sgr !== '') {
                  $current .= self::_RESET_FORMAT;
               }

               $rows[] = $current;
               $current = $sgr;
               $columns = 0;
            }
         }
      }

      // ? The last partial row (an empty line still occupies one row)
      if ($current !== '' || $rows === []) {
         $rows[] = $current;
      }

      // :
      return $rows;
   }
}
