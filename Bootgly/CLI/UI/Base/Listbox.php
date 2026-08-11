<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Base;


use function count;
use function explode;
use function implode;
use function max;
use function mb_strimwidth;
use function mb_stripos;
use function mb_strlen;
use function mb_strwidth;
use function mb_substr;
use function min;
use function str_repeat;

use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Output\Window;
use Bootgly\CLI\UI\Atoms\Scrollbar;


/**
 * Listbox — the windowed, aimed option list: the rows a host offers when it has
 * choices (completions, slash-commands, matches). It composes the rows and
 * reports their `height`; **where** they land is the host's business — spliced
 * into a frame, handed to a Flyout as overlay content, or written directly.
 *
 * Long lists window around the aimed option: by default the clipped edges say
 * `↑ N more` / `↓ N more`; with `scrollbar` on, a right-edge bar replaces the
 * markers (`width` gives it a column to align on). A `query` highlights its
 * first occurrence inside each option. Options are plain text — markup is not
 * resolved in them, so the measured widths stay honest.
 */
class Listbox extends Component
{
   // * Config
   /** Max visible options (0 renders all) */
   public int $viewport;
   /** Columns available to a row (null renders options whole) */
   public null|int $width;
   /** Painted before the aimed option */
   public string $marker;
   /** Painted before the other options — align it with the marker */
   public string $filler;
   /** Aimed row paint (markup token) */
   public string $color;
   /** Label column paint (markup token) for every row — contrasts the commands
    *  against the dim details; empty keeps the terminal's foreground */
   public string $tint;
   /** Aimed row background (markup token, e.g. `@!black:`) — pads the row to
    *  `width`, so the highlight spans the block; empty keeps the marker-only aim */
   public string $background;
   /** Blink the aimed row */
   public bool $blink;
   /** Right-edge scrollbar on clipped lists — replaces the `N more` markers */
   public bool $scrollbar;
   /** Circular navigation — advancing past the last option wraps to the first
    *  and regressing past the first wraps to the last */
   public bool $circular;
   /** The query highlighted inside each option (first match, case-insensitive) */
   public string $query;
   /** Query-match paint (markup tokens) */
   public string $accent;

   // * Data
   /** @var array<int,string> The options, top to bottom */
   public array $options;
   /** @var array<int,string> Dim detail column per option index (description, usage) */
   public array $details;

   // * Metadata
   /** Aimed option index */
   public private(set) int $aimed;
   /** The visible slice calculator */
   public private(set) Window $Window;
   /** The right-edge bar (restyle its glyphs and paints through it) */
   public private(set) Scrollbar $Scrollbar;
   /** Rows the last render produced — hosts place the block by it */
   public private(set) int $height;

   private Output $Output;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->viewport = 0;
      $this->width = null;
      $this->marker = '❯ ';
      $this->filler = '  ';
      $this->color = '@#Cyan:';
      $this->tint = '';
      $this->background = '';
      $this->blink = false;
      $this->scrollbar = false;
      $this->circular = false;
      $this->query = '';
      $this->accent = '@*:@#White:';

      // * Data
      $this->options = [];
      $this->details = [];

      // * Metadata
      $this->aimed = 0;
      $this->Window = new Window;
      $this->Scrollbar = new Scrollbar($Output);
      $this->height = 0;
   }


   /**
    * Aims an option by index, clamped to the list bounds.
    *
    * @param int $index The option index.
    *
    * @return self
    */
   public function aim (int $index): self
   {
      $last = count($this->options) - 1;

      // * Metadata
      $this->aimed = match (true) {
         $last < 0 => 0,
         $index < 0 => 0,
         $index > $last => $last,
         default => $index
      };

      // :
      return $this;
   }

   /**
    * Aims the next option — clamped at the last, or wrapped to the first when
    * `circular` is on.
    *
    * @return self
    */
   public function advance (): self
   {
      // ? Circular navigation wraps past the last option to the first
      if ($this->circular === true && $this->aimed >= count($this->options) - 1) {
         // :
         return $this->aim(0);
      }

      // :
      return $this->aim($this->aimed + 1);
   }

   /**
    * Aims the previous option — clamped at the first, or wrapped to the last
    * when `circular` is on.
    *
    * @return self
    */
   public function regress (): self
   {
      // ? Circular navigation wraps past the first option to the last
      if ($this->circular === true && $this->aimed === 0) {
         // :
         return $this->aim(count($this->options) - 1);
      }

      // :
      return $this->aim($this->aimed - 1);
   }

   /**
    * Highlights the first query occurrence inside an option (case-insensitive).
    * The row paint reopens after the accent, so an aimed row keeps its
    * background across the match.
    *
    * @param string $option The plain option text (already cropped).
    * @param string $reopen The row paint to restore after the accent.
    *
    * @return string
    */
   private function mark (string $option, string $reopen): string
   {
      $position = mb_stripos($option, $this->query);

      // ? No match
      if ($position === false) {
         // :
         return $option;
      }

      $length = mb_strlen($this->query);

      $head = mb_substr($option, 0, $position);
      $slice = mb_substr($option, $position, $length);
      $tail = mb_substr($option, $position + $length);

      // :
      return "{$head}{$this->accent}{$slice}\e[0m{$reopen}{$tail}";
   }

   /**
    * Composes the option rows. An empty list renders nothing and reports a
    * `height` of 0, which is how a host closes the list.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string The markup rows when returning — null when writing.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      $total = count($this->options);

      // ? Closed
      if ($total === 0) {
         // * Metadata
         $this->height = 0;

         // ?:
         return $mode === self::RETURN_OUTPUT ? '' : null;
      }

      // ! Visible slice around the aim
      $this->aim($this->aimed);

      // ? An unlimited viewport is a window as tall as the list (size 0 empties it)
      $this->Window->size = $this->viewport > 0 ? $this->viewport : $total;
      $this->Window->total = $total;
      $this->Window->slide($this->aimed);

      $visible = $this->Window->last - $this->Window->first + 1;

      // ! Scrollbar — the Atom derives the thumb from the view numbers
      $this->Scrollbar->total = $total;
      $this->Scrollbar->height = $visible;
      $this->Scrollbar->first = $this->Window->first;

      $sliding = $this->scrollbar === true && $this->Scrollbar->check() === true;

      $bars = $sliding === true
         ? explode("\n", (string) $this->Scrollbar->render(self::RETURN_OUTPUT))
         : [];

      // ! Rows — without a scrollbar, the clipped edges announce what they hide
      $rows = [];

      if ($sliding === false && $this->Window->first > 0) {
         $rows[] = "@#Black:↑ {$this->Window->first} more\e[0m";
      }

      // ! Aimed row paint — an optional blink and a full-width background
      $style = $this->blink === true ? '@@:' : '';

      // ! Detail column — labels align on the widest one, details ride dimmed
      $detailed = $this->details !== [];
      $column = 0;
      if ($detailed === true) {
         foreach ($this->options as $option) {
            $column = max($column, mb_strwidth($option));
         }

         if ($this->width !== null) {
            $column = min($column, $this->width);
         }
      }

      for ($index = $this->Window->first; $index <= $this->Window->last; $index++) {
         $option = $this->options[$index];
         $aimed = $index === $this->aimed;

         // ? Crop to the available columns (a wrapped row breaks the host's height bookkeeping)
         $limit = $detailed === true ? $column : $this->width;
         if ($limit !== null && mb_strwidth($option) > $limit) {
            $option = mb_strimwidth($option, 0, $limit, '…');
         }

         // ? The detail crops to the columns the label column leaves over
         $detail = $detailed === true ? ($this->details[$index] ?? '') : '';
         if ($detail !== '' && $this->width !== null) {
            $budget = max(0, $this->width - $column - 2);

            $detail = mb_strwidth($detail) > $budget
               ? mb_strimwidth($detail, 0, $budget, '…')
               : $detail;
         }

         // ! Visible row extent (label column + detail) — the paddings align on it
         $extent = $detailed === true
            ? $column + ($detail !== '' ? 2 + mb_strwidth($detail) : 0)
            : mb_strwidth($option);

         // ? Pad to the width — a background highlight spans the row and the
         //   scrollbar column stays aligned
         $padding = '';
         if (
            $this->width !== null
            && ($sliding === true || ($aimed === true && $this->background !== ''))
         ) {
            $padding = str_repeat(' ', max(0, $this->width - $extent));
         }

         // ? The typed query lights up inside the option — the row paint
         //   resolves to raw SGR here: a markup token would eat the space that
         //   may follow it (e.g. a skeleton right after the accented command)
         $reopen = TemplateEscaped::render(
            $aimed === true
               ? "{$style}{$this->background}{$this->color}"
               : $this->tint
         );
         $text = $this->query !== '' ? $this->mark($option, $reopen) : $option;

         // ? The dim detail column, aligned after the widest label
         if ($detail !== '') {
            $align = str_repeat(' ', max(0, $column - mb_strwidth($option)));

            $text .= "{$align}  @#Black:{$detail}";
         }

         // ? Scrollbar column at the right edge
         $bar = '';
         if ($sliding === true) {
            $bar = $bars[$index - $this->Window->first] ?? '';
         }

         $rows[] = $aimed === true
            ? "{$reopen}{$this->marker}{$text}{$padding}\e[0m{$bar}"
            : "{$this->filler}{$reopen}{$text}{$padding}\e[0m{$bar}";
      }

      if ($sliding === false && $this->Window->last < $total - 1) {
         $hidden = $total - 1 - $this->Window->last;
         $rows[] = "@#Black:↓ {$hidden} more\e[0m";
      }

      // ---

      $frame = implode("\n", $rows) . "\n";

      // * Metadata
      $this->height = count($rows);

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT) {
         return $frame;
      }

      $this->Output->render($frame);

      return null;
   }
}
