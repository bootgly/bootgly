<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Base;


use function count;
use function implode;
use function mb_strimwidth;
use function mb_strwidth;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Output\Window;
use Bootgly\CLI\UI\Base\Fieldset;


/**
 * Flyout — the anchored option list: the block a host paints against an input
 * when it offers choices (completions, slash-commands, matches). It composes
 * rows and reports their `height`; **where** they land is the host's business,
 * so the same Flyout serves a list dropping below an input or rising above one.
 *
 * Long lists window around the aimed option, with `↑ N more` / `↓ N more`
 * markers on the clipped edges. Options are plain text — markup is not resolved
 * in them, so the measured widths stay honest.
 */
class Flyout extends Component
{
   // * Config
   /** Max visible options (0 renders all) */
   public int $viewport;
   /** Compose the rows inside a bordered box */
   public bool $bordered;
   /** Columns available to a row (null renders options whole) */
   public null|int $width;
   /** Box title, when bordered (markup) */
   public null|string $title;
   /** Painted before the aimed option */
   public string $marker;
   /** Painted before the other options — align it with the marker */
   public string $filler;
   /** Aimed row paint (markup token) */
   public string $color;

   // * Data
   /** @var array<int,string> The options, top to bottom */
   public array $options;

   // * Metadata
   /** Aimed option index */
   public private(set) int $aimed;
   /** The visible slice calculator */
   public private(set) Window $Window;
   /** Rows the last render produced — hosts place the block by it */
   public private(set) int $height;

   private Output $Output;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->viewport = 0;
      $this->bordered = false;
      $this->width = null;
      $this->title = null;
      $this->marker = '=> ';
      $this->filler = '   ';
      $this->color = '@#Cyan:';

      // * Data
      $this->options = [];

      // * Metadata
      $this->aimed = 0;
      $this->Window = new Window;
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
    * Aims the next option (clamped — the list never wraps).
    *
    * @return self
    */
   public function advance (): self
   {
      // :
      return $this->aim($this->aimed + 1);
   }

   /**
    * Aims the previous option (clamped — the list never wraps).
    *
    * @return self
    */
   public function regress (): self
   {
      // :
      return $this->aim($this->aimed - 1);
   }

   /**
    * Composes the option rows. An empty list renders nothing and reports a
    * `height` of 0, which is how a host closes the flyout.
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

      // ! Rows — the clipped edges announce what they hide
      $rows = [];

      if ($this->Window->first > 0) {
         $rows[] = "@#Black:↑ {$this->Window->first} more@;";
      }

      for ($index = $this->Window->first; $index <= $this->Window->last; $index++) {
         $option = $this->options[$index];

         // ? Crop to the available columns (a wrapped row breaks the host's height bookkeeping)
         if ($this->width !== null && mb_strwidth($option) > $this->width) {
            $option = mb_strimwidth($option, 0, $this->width, '…');
         }

         $rows[] = $index === $this->aimed
            ? "{$this->color}{$this->marker}{$option}@;"
            : "{$this->filler}{$option}";
      }

      if ($this->Window->last < $total - 1) {
         $hidden = $total - 1 - $this->Window->last;
         $rows[] = "@#Black:↓ {$hidden} more@;";
      }

      // ---

      $frame = implode("\n", $rows);

      // ? Bordered composition delegates the box to the Fieldset
      if ($this->bordered === true) {
         $Fieldset = new Fieldset($this->Output);
         $Fieldset->title = $this->title;
         $Fieldset->content = $frame;

         $frame = (string) $Fieldset->render(self::RETURN_OUTPUT);
      }
      else {
         $frame .= "\n";
      }

      // * Metadata
      $this->height = count($rows) + ($this->bordered === true ? 2 : 0);

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT) {
         return $frame;
      }

      $this->Output->render($frame);

      return null;
   }
}
