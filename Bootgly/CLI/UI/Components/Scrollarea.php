<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
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
use function max;
use function mb_str_split;
use function preg_match;
use function preg_split;
use function rewind;
use function str_ends_with;
use function stream_get_contents;
use function substr;

use Bootgly\ABI\Code\__String;
use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Atoms\Scrollbar;


/**
 * Scrollable content band — an internally buffered window of rows rendered into a
 * fixed screen band, with a right-edge `Scrollbar` Atom strip. Content fed while the
 * area is stuck to the bottom auto-follows; scrolling up holds the position while new
 * rows arrive (the bar tracks it). Non-interactive output degrades to plain writes.
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
   public bool $hovered {
      get => $this->Scrollbar->hovered;
   }
   /** The right-edge bar (restyle its glyphs and paints through it) */
   public private(set) Scrollbar $Scrollbar;


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
      $this->Scrollbar = new Scrollbar($Output);
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
    * Clears the content buffer and repaints the empty band — the view sticks
    * back to the bottom, with nothing left to scroll into.
    *
    * @return void
    */
   public function clear (): void
   {
      // * Data
      $this->buffer = [];

      // * Metadata
      $this->first = 0;
      $this->stuck = true;

      // ? Non-interactive output has no band to repaint
      if (BOOTGLY_TTY === false) {
         return;
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
      if ($this->scrollbar === true && $column === $this->width) {
         $this->sync();

         $part = $this->Scrollbar->hit($column, $line);
         if ($part !== null) {
            // :
            return $part;
         }
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

      $this->sync();

      // ! Buffer row from the thumb proportion
      $aimed = $this->Scrollbar->aim($line);

      // * Metadata
      $this->first = $aimed;
      $this->stuck = ($aimed === $total - $this->rows);

      $this->render();
   }

   /**
    * Updates the pointer-over-thumb state — the thumb highlights while hovered,
    * repainting the bar strip only.
    *
    * @param bool $over Whether the pointer is over the thumb.
    *
    * @return void
    */
   public function hover (bool $over): void
   {
      $this->sync();

      $this->Scrollbar->hover($over);
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

      $this->Scrollbar->reset();
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
      $this->sync();

      $sliding = ($this->scrollbar === true && $this->Scrollbar->check() === true);

      // ! Bar strip rows (the Scrollbar Atom derives the thumb)
      $bars = [];
      if ($sliding === true) {
         $bars = explode("\n", (string) $this->Scrollbar->render(self::RETURN_OUTPUT));
      }

      // ! Band rows
      $lines = [];
      for ($index = 0; $index < $this->rows; $index++) {
         $lines[] = $this->buffer[$this->first + $index] ?? '';
      }

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         $frame = '';
         foreach ($lines as $index => $content) {
            $bar = $bars[$index] ?? '';
            $frame .= "{$content}{$bar}\n";
         }

         // :
         return $frame;
      }

      // @ Repaint the band rows in place, then the bar strip over the right edge
      foreach ($lines as $index => $content) {
         $this->Output->Cursor->moveTo(line: $this->row + $index, column: 1);
         $this->Output->Text->trim(right: true);
         $this->Output->write($content);
      }

      if ($sliding === true) {
         $this->Scrollbar->render();
      }

      return null;
   }

   /**
    * Syncs the view numbers and the strip geometry into the Scrollbar Atom.
    *
    * @return void
    */
   private function sync (): void
   {
      $this->Scrollbar->row = $this->row;
      $this->Scrollbar->column = $this->width;
      $this->Scrollbar->height = $this->rows;
      $this->Scrollbar->total = count($this->buffer);
      $this->Scrollbar->first = $this->first;
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
