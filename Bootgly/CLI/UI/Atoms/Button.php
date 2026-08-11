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
use function mb_strimwidth;
use function mb_strwidth;
use function preg_replace;
use function str_repeat;
use Closure;

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


/**
 * Button — the pressable pill: an icon/emoji, a label, or both, painted as a
 * one-row pill (background `style`) or bare, with a `hover` paint that lights
 * the background up as the pointer passes. `press()` fires the `Action`
 * Closure — fully generic: open a Dialog, feed a Prompt, anything.
 *
 * The Button never arms the mouse — the host does (`Terminal\Reporting\Mouse`
 * or its own reporting loop) and routes the SGR reports: movement drives
 * `hover(hit(column, line))`, a left press on a `hit()` calls `press()`, and
 * Enter/Space map to `press()` for the keyboard. Implementing `Boxing`, a
 * placed Button repaints in place at its rectangle and any Boxing consumer
 * (e.g. `Dialog::cover()`) can manage it.
 */
class Button extends Component implements Boxing
{
   use Formattable;


   // ! ANSI escape sequence matcher (escape-aware measuring)
   private const string ANSI = '/\e\[[0-9;]*m/';


   private Output $Output;

   // * Config
   /** SGR decoration — null follows the TTY, false forces plain, true forces styled */
   public null|bool $decoration;
   /** Icon/emoji painted before the label ('' = none — labels stand alone) */
   public string $icon;
   /** The label ('' = none — icon-only buttons are first-class) */
   public string $label;
   /** @var array<int,string> SGR codes painting the button at rest — empty keeps it bare
    *  (no background, terminal colors) */
   public array $style;
   /** @var array<int,string> SGR codes painted while hovered — the default soft
    *  background is what lights a bare button up as the pointer passes */
   public array $hover;
   // # Geometry (outer rectangle, 1-based screen coordinates)
   /** Screen row — 0 keeps the button unplaced (WRITE renders in flow) */
   public int $row;
   /** Screen column — 0 keeps the button unplaced */
   public int $column;
   /** Columns the button occupies — 0 derives from the content on render
    *  (stored back); an explicit width pads or crops (ellipsis) */
   public int $width;
   /** Rows the button occupies — always 1 (a button is a one-row pill) */
   public int $height;

   // * Data
   /** The press trigger — `function (Button $Button): mixed`; its return value
    *  comes back through `press()` */
   public null|Closure $Action;

   // * Metadata
   /** Pointer over the button (hover paint)? */
   public private(set) bool $hovered;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->decoration = null;
      $this->icon = '';
      $this->label = '';
      $this->style = [];
      // ? Truecolor soft black + bright white — the same aimed-row shade the
      //   overlay components use, so hovers read consistent across the UI
      $this->hover = [
         self::_BLACK_SOFT_BACKGROUND,
         self::_WHITE_BRIGHT_FOREGROUND
      ];
      $this->row = 0;
      $this->column = 0;
      $this->width = 0;
      $this->height = 1;

      // * Data
      $this->Action = null;

      // * Metadata
      $this->hovered = false;
   }


   /**
    * Checks whether a screen coordinate lands on the button's rectangle — a
    * pure predicate: no render, no state. An unplaced button hits nothing.
    *
    * @param int $column The screen column (1-based).
    * @param int $line The screen line (1-based).
    *
    * @return bool
    */
   public function hit (int $column, int $line): bool
   {
      // ? Unplaced or unsized
      if ($this->row < 1 || $this->column < 1 || $this->width < 1) {
         // :
         return false;
      }

      // :
      return $line === $this->row
         && $column >= $this->column
         && $column < $this->column + $this->width;
   }

   /**
    * Hovers (or leaves) the button — the hover paint repaints in place. Plain
    * output never hovers: there is no pointer without an interactive terminal.
    *
    * @param bool $over Whether the pointer is over the button.
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

      $this->render();
   }

   /**
    * Presses the button — fires the `Action` with the Button itself and hands
    * its return value back. The host maps a left press on a `hit()` (mouse)
    * or Enter/Space (keyboard) here.
    *
    * @return mixed The Action's return value — null without an Action.
    */
   public function press (): mixed
   {
      // ? Nothing to trigger
      if ($this->Action === null) {
         // :
         return null;
      }

      // :
      return ($this->Action)($this);
   }

   /**
    * Invalidates the button — a no-op: there is no blitted state, every render
    * repaints the whole row.
    *
    * @return void
    */
   public function invalidate (): void
   {
   }

   /**
    * Renders the button row: the padded icon/label content painted with the
    * rest or hover codes. `RETURN_OUTPUT` returns the raw row for the host to
    * splice; `WRITE_OUTPUT` paints in place at the rectangle when placed, or
    * writes in flow when not. Empty content renders nothing.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // !
      $plain = ($this->decoration ?? BOOTGLY_TTY) === false;

      // ! Content — icon, label or both, one breathing space each side (the pill)
      $parts = [];
      if ($this->icon !== '') {
         $parts[] = $this->icon;
      }
      if ($this->label !== '') {
         $parts[] = $this->label;
      }

      // ? Nothing to press
      if ($parts === []) {
         // * Config
         $this->width = 0;

         // ?:
         return $mode === self::RETURN_OUTPUT ? '' : null;
      }

      $content = ' ' . implode(' ', $parts) . ' ';
      $columns = $this->measure($content);

      // ? An explicit width pads the content — or crops it with an ellipsis
      if ($this->width > 0 && $columns !== $this->width) {
         $content = $columns < $this->width
            ? $content . str_repeat(' ', $this->width - $columns)
            : mb_strimwidth($content, 0, $this->width, '… ');
      }
      else {
         // * Config
         $this->width = $columns;
      }

      // ! Paint — the hover codes take over while the pointer is on the button
      $codes = $this->hovered === true ? $this->hover : $this->style;

      $output = match (true) {
         $plain === true => (string) preg_replace(self::ANSI, '', $content),
         $codes === [] => $content,
         default => self::wrap(...$codes) . $content . self::_RESET_FORMAT
      };

      // ?: Return — raw row, the host positions it
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $output;
      }

      // ? Placed — repaint in place at the rectangle
      if ($this->row >= 1 && $this->column >= 1) {
         $this->Output->Cursor->moveTo(line: $this->row, column: $this->column);
         $this->Output->write($output);

         return null;
      }

      // @ Unplaced — write in flow
      $this->Output->write("{$output}\n");

      // :
      return null;
   }

   /**
    * Measures the visible columns of a string (escapes occupy none; emoji and
    * other wide glyphs count their real width).
    */
   private function measure (string $string): int
   {
      return mb_strwidth((string) preg_replace(self::ANSI, '', $string));
   }
}
