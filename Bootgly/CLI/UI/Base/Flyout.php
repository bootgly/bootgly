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


use function array_pop;
use function count;
use function explode;
use function max;
use function rewind;
use function stream_get_contents;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Base\Fieldset;
use Bootgly\CLI\UI\Base\Flyout\Placements;


/**
 * Flyout — the generic anchored overlay: a delimited region a host opens
 * against its own position (a context menu over an input, a completion list, a
 * bottom sheet). The content is arbitrary markup set by composition — a Listbox
 * renders choices into it, but anything fits.
 *
 * Two ways to place it. `RETURN_OUTPUT` composes the block as a string for
 * hosts that splice it into their own frame (the placement is the splice
 * point). `WRITE_OUTPUT` paints the block **relative to the anchor row** — the
 * cursor row at call time — per `placement`, using relative movement only, and
 * returns the cursor to the anchor row at column 1; `close()` erases it. Below
 * scrolls the screen when the anchor sits at the bottom; Above overwrites the
 * rows over the anchor, so the host must have made room (a managed region).
 */
class Flyout extends Component
{
   // * Config
   /** Where the block opens, relative to the anchor row (WRITE mode) */
   public Placements $placement;
   /** Compose the block inside a bordered box */
   public bool $bordered;
   /** Box title, when bordered (markup) */
   public null|string $title;
   /** Inner columns, when bordered — null derives from the widest content line
    *  (autowide), 0 spans the full terminal width, N fixes the columns */
   public null|int $width;
   /** Inner background (markup token, e.g. `@!Black:`), when bordered — forwarded to the Fieldset */
   public null|string $background;

   // * Data
   /** The block content (markup) — rows separated by `\n`; empty closes the flyout */
   public string $content;

   // * Metadata
   /** Rows the last render composed/painted — 0 while closed */
   public private(set) int $height;

   private Output $Output;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->placement = Placements::Below;
      $this->bordered = false;
      $this->title = null;
      $this->width = null;
      $this->background = null;

      // * Data
      $this->content = '';

      // * Metadata
      $this->height = 0;
   }


   /**
    * Renders the block. Empty content renders nothing and reports a `height`
    * of 0 — the closed state. `RETURN_OUTPUT` returns the block markup for the
    * host to splice; `WRITE_OUTPUT` paints it anchored to the current cursor
    * row per `placement` and returns the cursor to the anchor row, column 1.
    * Repaints that change the block height must `close()` first — the paint
    * covers exactly its own rows.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string The block markup when returning — null when writing.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ? Closed
      if ($this->content === '') {
         // * Metadata
         $this->height = 0;

         // ?:
         return $mode === self::RETURN_OUTPUT ? '' : null;
      }

      // ! Block composition
      $rows = count(explode("\n", $this->content));

      // ? Bordered composition delegates the box to the Fieldset
      if ($this->bordered === true) {
         // ? Full width fills the terminal columns (borders + paddings take 4)
         $columns = isSet(Terminal::$width) === true ? (int) Terminal::$width : 80;

         $Fieldset = new Fieldset($this->Output);
         $Fieldset->width = $this->width === 0
            ? max(0, $columns - 4)
            : $this->width;
         $Fieldset->title = $this->title;
         $Fieldset->background = $this->background;
         $Fieldset->content = $this->content;

         $block = (string) $Fieldset->render(self::RETURN_OUTPUT);
         $rows += 2;
      }
      else {
         $block = "{$this->content}\n";
      }

      // * Metadata
      $this->height = $rows;

      // ?: Block as markup — the host splices it into its own frame
      if ($mode === self::RETURN_OUTPUT) {
         return $block;
      }

      // ! php://memory resolves the markup before the row-by-row paint
      $Memory = new Output('php://memory');
      $Memory->render($block);
      rewind($Memory->stream);

      $painted = explode("\n", (string) stream_get_contents($Memory->stream));
      // ? The block's trailing break leaves an empty last slice
      array_pop($painted);

      // @ Anchored paint — relative movement only (absolute positions drift
      //   when writing at the screen bottom scrolls the terminal)
      if ($this->placement === Placements::Above) {
         // ? Above overwrites the rows over the anchor — the host made room
         $this->Output->Cursor->up($rows, column: 1);

         foreach ($painted as $row) {
            $this->Output->Text->trim(right: true);
            $this->Output->write($row);
            $this->Output->Cursor->down(1, column: 1);
         }

         return null;
      }

      // @@ Below — `\n` (not CNL) steps down, so the bottom row still scrolls
      foreach ($painted as $row) {
         $this->Output->write("\n");
         $this->Output->Cursor->moveTo(column: 1);
         $this->Output->Text->trim(right: true);
         $this->Output->write($row);
      }

      // @ Back onto the anchor row
      $this->Output->Cursor->up($rows, column: 1);

      return null;
   }

   /**
    * Erases the block painted by the last WRITE render — the counterpart of the
    * anchored paint; frame-splicing hosts never need it. Expects the cursor on
    * the anchor row and returns it there, at column 1. No-op while closed.
    *
    * @return self
    */
   public function close (): self
   {
      // ? Nothing painted
      if ($this->height === 0) {
         // :
         return $this;
      }

      $height = $this->height;

      if ($this->placement === Placements::Above) {
         // @ Erase the block rows over the anchor
         $this->Output->Cursor->up($height, column: 1);
         $this->Output->Text->clear(lines: $height);
         $this->Output->Cursor->down($height, column: 1);
      }
      else {
         // @ Erase the block rows under the anchor
         $this->Output->Cursor->down(1, column: 1);
         $this->Output->Text->clear(lines: $height);
         $this->Output->Cursor->up(1, column: 1);
      }

      // * Metadata
      $this->height = 0;

      // :
      return $this;
   }
}
