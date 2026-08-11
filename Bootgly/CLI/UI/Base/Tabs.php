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


use const BOOTGLY_TTY;
use function array_keys;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_int;
use function mb_strwidth;
use function microtime;
use function preg_replace;
use function strncmp;
use function substr;
use function usleep;
use Generator;

use Bootgly\ABI\Code\__String;
use Bootgly\ABI\Code\__String\Escapeable\Mouse\Reportable;
use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Mousestrokes;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Atoms\Boxing;
use Bootgly\CLI\UI\Base\Frame;


/**
 * Tabs — a Frame multiplexer: N labeled tab Frames share one screen rectangle
 * and only the active one renders; the tab bar rides the active frame's top
 * border (the labels strip becomes its title, the active label highlighted).
 * Inactive tabs keep buffering their isolated Outputs, drained and bounded on
 * every render. Switch with `switch()`/`cycle()` or drive the interactive
 * `switching()` lifecycle — arrows, Tab/Shift+Tab and 1-9 by keyboard; by
 * pointer, movement hovers the bar labels, a left press switches and the
 * wheel cycles over the bar row.
 */
class Tabs extends Component implements Boxing
{
   use Formattable;
   use Reportable;


   public Input $Input;
   public Output $Output;

   // * Config
   // # Geometry (the shared rectangle, 1-based screen coordinates)
   /** Top screen row (1-based) */
   public int $row;
   /** Left screen column (1-based) */
   public int $column;
   /** Outer width, in columns */
   public int $width;
   /** Outer height, in rows */
   public int $height;
   // # Style
   /** Inactive labels and divisors color (Template markup) */
   public string $color;
   /** Active label paint (raw SGR or Template markup) */
   public string $highlight;
   /** Hovered inactive label paint (raw SGR or Template markup) */
   public string $hover;
   // # Timing
   /** Seconds per interactive tick — held keys never accelerate the clock */
   public float $throttle;

   // * Data
   /** @var array<string,Frame> The tab Frames, label ⇒ Frame, in add order */
   public private(set) array $Frames;
   /** Active tab ordinal (1-based; 0 while empty) */
   public private(set) int $tab;

   // * Metadata
   /** The active tab's content Frame */
   public null|Frame $Active {
      get => $this->tab > 0
         ? array_values($this->Frames)[$this->tab - 1]
         : null;
   }
   /** Hovered tab ordinal (1-based; 0 = none) */
   public private(set) int $hovered;
   /** @var array<int,array{int,int}> Bar label spans — ordinal ⇒ [first, last] composed-strip columns (0-based) */
   private array $spans;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      // # Geometry
      $this->row = 1;
      $this->column = 1;
      $this->width = 40;
      $this->height = 10;
      // # Style
      $this->color = '@#Black:';
      $this->highlight = self::wrap(self::_INVERSE_STYLE, self::_BOLD_STYLE);
      $this->hover = self::wrap(self::_UNDERLINE_STYLE);
      // # Timing
      $this->throttle = 0.05;

      // * Data
      $this->Frames = [];
      $this->tab = 0;

      // * Metadata
      $this->hovered = 0;
      $this->spans = [];
   }


   /**
    * Creates a labeled tab: a Frame bound to the host Output, its geometry
    * assigned immediately (inner metrics readable right after, to size hosted
    * components). The first added tab activates; a duplicate label replaces
    * its Frame in place.
    *
    * @param string $label The tab label (Template markup supported).
    *
    * @return Frame The created tab content Frame.
    */
   public function add (string $label): Frame
   {
      $Frame = new Frame($this->Output);

      // * Data
      $this->Frames[$label] = $Frame;
      // ? The first tab activates
      if ($this->tab === 0) {
         $this->tab = 1;
      }

      // @
      $this->arrange();
      $this->compose();

      // :
      return $Frame;
   }

   /**
    * Arranges the tab Frames — every one receives the shared rectangle
    * (identical values, so rectangle snapshots stay stable). Pure geometry.
    *
    * @return void
    */
   public function arrange (): void
   {
      foreach ($this->Frames as $Frame) {
         $Frame->row = $this->row;
         $Frame->column = $this->column;
         $Frame->width = $this->width;
         $Frame->height = $this->height;
      }
   }

   /**
    * Activates a tab by 1-based ordinal or label — pure state, no painting.
    * Unknown labels, out-of-range ordinals and the already-active tab are
    * silent no-ops; a real switch recomposes the bar and invalidates the new
    * active Frame (its rectangle was overdrawn by the previous tab).
    *
    * @param int|string $tab The tab ordinal (1-based) or label.
    *
    * @return void
    */
   public function switch (int|string $tab): void
   {
      // ! Resolve the ordinal — labels compare as strings (PHP casts numeric
      //   array keys to int, so a strict key search would never match '8080')
      if (is_int($tab) === true) {
         $ordinal = $tab;
      }
      else {
         $ordinal = 0;
         $position = 0;
         foreach (array_keys($this->Frames) as $label) {
            $position++;

            if ((string) $label === $tab) {
               $ordinal = $position;

               break;
            }
         }
      }

      // ? Unknown, out-of-range or already active
      if ($ordinal < 1 || $ordinal > count($this->Frames) || $ordinal === $this->tab) {
         return;
      }

      // * Data
      $this->tab = $ordinal;

      // @ The bar moves to the new active frame; its rectangle must repaint
      $this->compose();
      $this->Active?->invalidate();
   }

   /**
    * Cycles the active tab relatively, wrapping around both ends
    * (Tab / Shift+Tab semantics).
    *
    * @param int $delta The tabs to advance (negative cycles backwards).
    *
    * @return void
    */
   public function cycle (int $delta = 1): void
   {
      $count = count($this->Frames);

      // ?
      if ($count === 0) {
         return;
      }

      // @
      $this->switch(((($this->tab - 1 + $delta) % $count) + $count) % $count + 1);
   }

   /**
    * Hovers a tab by 1-based ordinal (`0` leaves) — the bar recomposes with
    * the hovered label accented by the `hover` paint. The active tab keeps
    * its highlight; out-of-range ordinals clear. Pure state: the next render
    * paints the new bar.
    *
    * @param int $tab The tab ordinal (1-based; 0 clears the hover).
    *
    * @return void
    */
   public function hover (int $tab): void
   {
      // ? Out-of-range hovers clear
      if ($tab < 0 || $tab > count($this->Frames)) {
         $tab = 0;
      }
      // ? Already there
      if ($tab === $this->hovered) {
         return;
      }

      // * Metadata
      $this->hovered = $tab;

      // @ The bar repaints on the next render
      $this->compose();
   }

   /**
    * Hit-tests the bar — resolves an absolute screen position to the 1-based
    * ordinal of the label under it, or `0` off the labels. Only the bar row
    * (the active frame's top border) hits; the divisors and the trailing
    * border fill miss.
    *
    * @param int $column The pointer column (1-based screen coordinate).
    * @param int $line The pointer line (1-based screen coordinate).
    *
    * @return int The label ordinal (1-based), or 0 on a miss.
    */
   public function hit (int $column, int $line): int
   {
      // ? Off the bar row
      if ($line !== $this->row || $this->Frames === []) {
         return 0;
      }

      // ! Strip-relative offset — the composed strip starts after the corner
      //   glyph + the title pad space; the border crop bounds the far edge
      $offset = $column - ($this->column + 2);

      // ? Outside the strip
      if ($offset < 0 || $offset > $this->width - 4) {
         return 0;
      }

      // @@
      foreach ($this->spans as $ordinal => [$first, $last]) {
         if ($offset >= $first && $offset <= $last) {
            // :
            return $ordinal;
         }
      }

      // :
      return 0;
   }

   /**
    * Controls one input sequence — keyboard navigation (←/→, Tab/Shift+Tab
    * cycle, `1`-`9` jump) and SGR mouse reports (movement hovers the bar
    * labels, a left press switches, the wheel cycles over the bar row).
    *
    * @param string $sequence The assembled input sequence (key or report).
    *
    * @return bool false when the sequence ends the session (`q`/Ctrl+C).
    */
   public function control (string $sequence): bool
   {
      // ? Mouse reports route to the pointer handler
      if (strncmp($sequence, "\e[<", 3) === 0) {
         $this->point($sequence);

         // :
         return true;
      }

      // @ Navigation
      match ($sequence) {
         Keystrokes::RIGHT->value,
         Keystrokes::TAB->value => $this->cycle(+1),
         Keystrokes::LEFT->value,
         Keystrokes::SHIFT_TAB->value => $this->cycle(-1),
         '1', '2', '3', '4', '5', '6', '7', '8', '9' => $this->switch((int) $sequence),
         default => null
      };

      // ?: `q` (or Ctrl+C via the restore net) ends the session
      return $sequence !== 'q' && $sequence !== Keystrokes::CTRL_C->value;
   }

   /**
    * Routes one SGR mouse report — the wheel cycles the tabs over the bar
    * row, a left press on a label switches to its tab and plain movement
    * hovers the label under the pointer (leaving clears).
    *
    * @param string $report The raw SGR report (`\e[<Cb;Cx;Cy` + `M`/`m`).
    *
    * @return void
    */
   private function point (string $report): void
   {
      // ! Payload: button code, column and line — plus the press/release state
      $state = substr($report, -1);
      $parts = explode(';', substr($report, 3, -1));
      // ?
      if (count($parts) !== 3) {
         return;
      }

      [$button, $column, $line] = $parts;
      $column = (int) $column;
      $line = (int) $line;

      $Action = Mousestrokes::tryFrom($button);

      // @ The wheel cycles the tabs over the bar row
      if ($Action === Mousestrokes::SCROLL_UP || $Action === Mousestrokes::SCROLL_DOWN) {
         if ($line === $this->row
            && $column >= $this->column && $column < $this->column + $this->width
         ) {
            $this->cycle($Action === Mousestrokes::SCROLL_UP ? -1 : +1);
         }

         return;
      }

      // @ A left press on a label switches to its tab (a miss is a no-op)
      if ($Action === Mousestrokes::LEFT_CLICK && $state === Mousestrokes::CLICKED->value) {
         $this->switch($this->hit($column, $line));

         return;
      }

      // @ Plain movement hovers the label under the pointer (0 leaves)
      if ($Action === Mousestrokes::NONE_CLICK_WITH_MOVEMENT) {
         $this->hover($this->hit($column, $line));
      }
   }

   /**
    * Toggles SGR pointer tracking — all events (movement, click, wheel), so
    * the bar labels can hover. A leaked tracking floods the shell with
    * escapes, so `switching()` always pairs the enable with the disable.
    *
    * @param bool $enabled Whether to track the pointer.
    *
    * @return void
    */
   private function track (bool $enabled): void
   {
      if ($enabled === true) {
         $this->Output->escape(self::_MOUSE_SET_SGR_EXT_MODE);
         $this->Output->escape(self::_MOUSE_ENABLE_ALL_EVENT_REPORTING);

         return;
      }

      $this->Output->escape(self::_MOUSE_DISABLE_ALL_EVENT_REPORTING);
      $this->Output->escape(self::_MOUSE_UNSET_SGR_EXT_MODE);
   }

   /**
    * Invalidates the active Frame — the next render repaints the full
    * rectangle (screen cleared externally, overlapped, ...).
    *
    * @return void
    */
   public function invalidate (): void
   {
      $this->Active?->invalidate();
   }

   /**
    * Resizes the shared rectangle — the screen clears (wiping artifacts),
    * every tab Frame invalidates (content preserved) and the active one
    * repaints. The signature matches the `Screen::watch` resize handler.
    *
    * @param int $columns The new width, in columns.
    * @param int $lines The new height, in rows.
    *
    * @return void
    */
   public function resize (int $columns, int $lines): void
   {
      // * Config
      $this->width = $columns;
      $this->height = $lines;

      // @ Wipe the screen and force full repaints
      $this->Output->clear();
      foreach ($this->Frames as $Frame) {
         $Frame->invalidate();
      }

      $this->render();
   }

   /**
    * Renders the active tab Frame — every INACTIVE frame drains first, so
    * their isolated streams and buffers stay bounded while hidden.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ! Resolved mode — the inherited $render property pins RETURN_OUTPUT
      if ($this->render === self::RETURN_OUTPUT) {
         $mode = self::RETURN_OUTPUT;
      }

      // ? Nothing to render while empty
      if ($this->Frames === []) {
         // :
         return ($mode === self::RETURN_OUTPUT) ? '' : null;
      }

      $this->arrange();

      // @ Inactive frames absorb their pending writes without painting
      $Active = $this->Active;
      foreach ($this->Frames as $Frame) {
         if ($Frame !== $Active) {
            $Frame->drain();
         }
      }

      // :
      return $Active?->render($mode);
   }

   /**
    * Interactive lifecycle — renders, yields the active ordinal every tick
    * (feed the tab Outputs in the loop body) and reads one key attempt per
    * tick: ←/→ and Tab/Shift+Tab cycle, `1`-`9` jump, `q`/Ctrl+C ends.
    * Non-interactive output renders once and returns.
    *
    * @return Generator<int,int|null|string>
    */
   public function switching (): Generator
   {
      // ? Non-interactive output renders once
      if (BOOTGLY_TTY === false) {
         yield $this->render();

         return;
      }

      // ! Raw input + hidden cursor + pointer tracking for the session
      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();
      $this->track(true);

      $ended = false;

      // @@ Render → yield (the caller feeds contents) → drain the pending keys →
      //    pace the tick (held keys never accelerate the clock)
      while ($ended === false) {
         $started = microtime(true);

         $this->render();

         yield $this->tab;

         // @@ Drain every pending key this tick — no key-repeat backlog
         while (true) {
            // ! listen() assembles whole sequences (arrows, Shift+Tab = `\e[Z`)
            $key = $this->Input->listen();

            // ? Channel closed
            if ($key === false) {
               $ended = true;

               break;
            }

            // ? Drained
            if ($key === '') {
               break;
            }

            // @ Keyboard navigation + pointer reports
            if ($this->control($key) === false) {
               $ended = true;

               break;
            }
         }

         // ? Pace the tick — the clock is fixed whatever the keyboard does
         $remaining = $this->throttle - (microtime(true) - $started);
         if ($ended === false && $remaining > 0) {
            usleep((int) ($remaining * 1000000));
         }
      }

      // @ Restore the terminal
      $this->track(false);
      $this->Input->configure(blocking: true, canonical: true, echo: true);
      $this->Output->Cursor->show();
   }

   /**
    * Composes the labels strip into the active frame's title — the bar rides
    * the top border. Recomposed on add/switch only (the strip changes on
    * those events, never per tick).
    *
    * @return void
    */
   private function compose (): void
   {
      $Active = $this->Active;

      // ?
      if ($Active === null) {
         return;
      }

      // ! Bar paints — the divisor derives from the active frame's border set
      $map = $Active->Borders->map();
      $divisor = $map['left'] ?? '';
      $paint = TemplateEscaped::render($this->color);
      $highlight = TemplateEscaped::render($this->highlight);
      $accent = TemplateEscaped::render($this->hover);
      $reset = self::_RESET_FORMAT;

      // @@ Label segments — the active one highlighted, the hovered accented —
      //    and their strip spans (escape-aware widths, for pointer hit-testing)
      $segments = [];
      $spans = [];
      $ordinal = 0;
      $offset = 0;
      foreach (array_keys($this->Frames) as $label) {
         $ordinal++;

         $segments[] = match (true) {
            $ordinal === $this->tab => "{$highlight} {$label} {$reset}",
            $ordinal === $this->hovered => "{$accent} {$label} {$reset}",
            default => "{$paint} {$label} {$reset}"
         };

         // ! Span — the visible label width + the segment pad spaces (escapes
         //   and control characters measure zero, mirroring the title strip)
         $width = mb_strwidth((string) preg_replace(
            [__String::ANSI_ESCAPE_SEQUENCE_REGEX, '/[\x00-\x1A\x1C-\x1F\x7F]/'],
            '',
            TemplateEscaped::render((string) $label)
         )) + 2;
         $spans[$ordinal] = [$offset, $offset + $width - 1];
         $offset += $width + 1; // + the divisor / space glue
      }

      $glue = ($divisor !== '') ? "{$paint}{$divisor}{$reset}" : ' ';

      // * Metadata
      $this->spans = $spans;

      $Active->title = implode($glue, $segments);
   }
}
