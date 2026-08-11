<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Atoms;


use const BOOTGLY_TTY;
use function implode;
use function intdiv;
use function max;
use function round;

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


/**
 * Scrollbar — the vertical bar strip: a thumb sliding over a track, derived
 * from the three view numbers every scrollable host owns (`total`, `height`,
 * `first`). Hosts either place it (the strip repaints in place at its column)
 * or compose it (RETURN hands the glyph rows back to splice per row) — the
 * Scrollarea band and the Listbox window both mount on this Atom.
 *
 * The Scrollbar never arms the mouse — the host does and routes the SGR
 * reports: movement drives `hover(hit(column, line) === 'thumb')`, a left
 * press on the strip aims (`aim(line)`) and drags.
 */
class Scrollbar extends Component implements Boxing
{
   use Formattable;


   private Output $Output;

   // * Config
   /** SGR decoration — null follows the TTY, false forces plain, true forces styled */
   public null|bool $decoration;
   /** The thumb glyph (the draggable handle) */
   public string $thumb;
   /** The track glyph (the rail behind the thumb) */
   public string $track;
   /** @var array<int,string> SGR codes painting the track and the resting thumb */
   public array $style;
   /** @var array<int,string> SGR codes painting the thumb while hovered */
   public array $accent;
   // # Geometry (the strip column, 1-based screen coordinates)
   /** Screen row of the strip top — 0 keeps the bar unplaced (composed by the host) */
   public int $row;
   /** Screen column of the strip — 0 keeps the bar unplaced */
   public int $column;
   /** Columns the strip occupies — always 1 (a vertical bar is one column wide) */
   public int $width;
   /** Rows the strip occupies — the host view's visible rows */
   public int $height;

   // * Data
   /** Total content rows/options behind the view */
   public int $total;
   /** First visible content index (0-based) */
   public int $first;

   // * Metadata
   /** Pointer over the thumb (accent paint)? */
   public private(set) bool $hovered;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->decoration = null;
      $this->thumb = '█';
      $this->track = '│';
      $this->style = [self::_BLACK_BRIGHT_FOREGROUND];
      $this->accent = [self::_WHITE_BRIGHT_FOREGROUND];
      $this->row = 0;
      $this->column = 0;
      $this->width = 1;
      $this->height = 0;

      // * Data
      $this->total = 0;
      $this->first = 0;

      // * Metadata
      $this->hovered = false;
   }


   /**
    * Checks whether the bar slides — the content overflows the view, so there
    * is a thumb to show and drag.
    *
    * @return bool
    */
   public function check (): bool
   {
      // ? A strip with no rows never slides
      if ($this->height < 1) {
         // :
         return false;
      }

      // :
      return $this->total > $this->height;
   }

   /**
    * Measures the thumb geometry from the view numbers.
    *
    * @return array{0: int, 1: int} The thumb [start, size], in strip rows —
    * [0, 0] when the bar does not slide.
    */
   public function measure (): array
   {
      // ? Nothing to slide
      if ($this->check() === false) {
         // :
         return [0, 0];
      }

      $size = max(1, (int) round($this->height * $this->height / $this->total));

      $bottom = max(1, $this->total - $this->height);
      $start = (int) round($this->first / $bottom * ($this->height - $size));

      // :
      return [$start, $size];
   }

   /**
    * Tests which strip part sits at a screen coordinate — a pure predicate:
    * no render, no state. An unplaced or non-sliding bar hits nothing.
    *
    * @param int $column The screen column (1-based).
    * @param int $line The screen line (1-based).
    *
    * @return null|string `'thumb'`, `'track'` — or null outside the strip.
    */
   public function hit (int $column, int $line): null|string
   {
      // ? Unplaced or not sliding
      if ($this->row < 1 || $this->column < 1 || $this->check() === false) {
         // :
         return null;
      }
      // ? Outside the strip
      if ($column !== $this->column) {
         // :
         return null;
      }
      if ($line < $this->row || $line >= $this->row + $this->height) {
         // :
         return null;
      }

      [$start, $size] = $this->measure();

      $offset = $line - $this->row;

      // :
      return ($offset >= $start && $offset < $start + $size) ? 'thumb' : 'track';
   }

   /**
    * Aims the view so the thumb centers on a screen line (drag or track
    * click) — updates `first` and hands it back for the host to apply.
    *
    * @param int $line The screen line (1-based).
    *
    * @return int The aimed first visible index.
    */
   public function aim (int $line): int
   {
      // ? Nothing to slide
      if ($this->check() === false) {
         // :
         return $this->first;
      }

      [, $size] = $this->measure();

      // ! Thumb start aimed by its center
      $span = $this->height - $size;
      $offset = $line - $this->row - intdiv($size, 2);
      if ($offset < 0) {
         $offset = 0;
      }
      if ($offset > $span) {
         $offset = $span;
      }

      // ! Content row from the thumb proportion
      $bottom = $this->total - $this->height;
      $aimed = $span > 0 ? (int) round($offset / $span * $bottom) : $bottom;

      // * Data
      $this->first = $aimed;

      // :
      return $aimed;
   }

   /**
    * Hovers (or leaves) the thumb — the accent paint repaints the strip in
    * place when the bar is placed; composed hosts re-render themselves. Plain
    * output never hovers: there is no pointer without an interactive terminal.
    *
    * @param bool $over Whether the pointer is over the thumb.
    *
    * @return void
    */
   public function hover (bool $over): void
   {
      // ? Plain output has no pointer
      if (($this->decoration ?? BOOTGLY_TTY) === false) {
         return;
      }
      // ? Unchanged
      if ($this->hovered === $over) {
         return;
      }

      // * Metadata
      $this->hovered = $over;

      // ? A placed strip repaints itself
      if ($this->row >= 1 && $this->column >= 1) {
         $this->render();
      }
   }

   /**
    * Resets the view state and the hover, silently — no repaint; the host's
    * own reset drives the next paint.
    *
    * @return void
    */
   public function reset (): void
   {
      // * Data
      $this->first = 0;

      // * Metadata
      $this->hovered = false;
   }

   /**
    * Invalidates the strip — a no-op: there is no blitted state, every render
    * repaints the whole column.
    *
    * @return void
    */
   public function invalidate (): void
   {
   }

   /**
    * Renders the strip: one glyph row per view row — the thumb over the
    * track, the hovered thumb accented. `RETURN_OUTPUT` returns the rows
    * joined by `\n` (no trailing newline) for the host to splice;
    * `WRITE_OUTPUT` paints in place at the strip column when placed, or
    * writes the rows in flow when not. A non-sliding bar renders nothing.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ? Nothing to slide
      if ($this->check() === false) {
         // ?:
         return $mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT
            ? ''
            : null;
      }

      // !
      $plain = ($this->decoration ?? BOOTGLY_TTY) === false;

      [$start, $size] = $this->measure();

      // ! Strip rows — the hovered thumb takes the accent paint
      $rows = [];
      for ($offset = 0; $offset < $this->height; $offset++) {
         $aimed = ($offset >= $start && $offset < $start + $size);
         $glyph = $aimed === true ? $this->thumb : $this->track;

         $codes = ($aimed === true && $this->hovered === true)
            ? $this->accent
            : $this->style;

         $rows[] = match (true) {
            $plain === true => $glyph,
            $codes === [] => $glyph,
            default => self::wrap(...$codes) . $glyph . self::_RESET_FORMAT
         };
      }

      // ?: Return — glyph rows, the host splices them
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return implode("\n", $rows);
      }

      // ? Placed — repaint the strip in place at its column
      if ($this->row >= 1 && $this->column >= 1) {
         foreach ($rows as $offset => $row) {
            $this->Output->Cursor->moveTo(line: $this->row + $offset, column: $this->column);
            $this->Output->write($row);
         }

         return null;
      }

      // @ Unplaced — write the rows in flow
      foreach ($rows as $row) {
         $this->Output->write("{$row}\n");
      }

      // :
      return null;
   }
}
