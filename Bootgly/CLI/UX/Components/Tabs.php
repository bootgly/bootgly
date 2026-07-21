<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function array_keys;
use function array_values;
use function count;
use function feof;
use function function_exists;
use function implode;
use function is_int;
use function microtime;
use function ord;
use function pcntl_signal_dispatch;
use function usleep;
use Generator;

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Atoms\Boxing;
use Bootgly\CLI\UI\Base\Frame;


/**
 * Tabs — a Frame multiplexer: N labeled tab Frames share one screen rectangle
 * and only the active one renders; the tab bar rides the active frame's top
 * border (the labels strip becomes its title, the active label highlighted).
 * Inactive tabs keep buffering their isolated Outputs, drained and bounded on
 * every render. Switch with `switch()`/`cycle()` or drive the interactive
 * `switching()` lifecycle (arrows, Tab/Shift+Tab, 1-9).
 */
class Tabs extends Component implements Boxing
{
   use Formattable;


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
      // # Timing
      $this->throttle = 0.05;

      // * Data
      $this->Frames = [];
      $this->tab = 0;
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

      // ! Raw input + hidden cursor for the session
      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();

      $ended = false;

      // @@ Render → yield (the caller feeds contents) → drain the pending keys →
      //    pace the tick (held keys never accelerate the clock)
      while ($ended === false) {
         $started = microtime(true);

         $this->render();

         yield $this->tab;

         // @@ Drain every pending key this tick — no key-repeat backlog
         while (true) {
            $key = $this->Input->read(1);

            // ? Channel closed
            if ($key === false || feof($this->Input->stream) === true) {
               $ended = true;

               break;
            }

            // ? Drained
            if ($key === '') {
               if (function_exists('pcntl_signal_dispatch') === true) {
                  pcntl_signal_dispatch();
               }

               break;
            }

            // ? Escape sequences: CSI reads until its final byte (arrows, Shift+Tab
            //   = `\e[Z`) — the tail bytes may lag the ESC on non-blocking channels,
            //   so empty reads retry briefly before giving the sequence up
            if ($key === "\e") {
               $next = '';
               for ($retry = 0; $retry < 5; $retry++) {
                  $next = (string) $this->Input->read(1);
                  if ($next !== '') {
                     break;
                  }

                  usleep(1000);
               }

               $key .= $next;

               if ($next === '[') {
                  while (true) {
                     $byte = '';
                     for ($retry = 0; $retry < 5; $retry++) {
                        $byte = (string) $this->Input->read(1);
                        if ($byte !== '') {
                           break;
                        }

                        usleep(1000);
                     }

                     if ($byte === '') {
                        break;
                     }

                     $key .= $byte;

                     $final = ord($byte);
                     if ($final >= 0x40 && $final <= 0x7E) {
                        break;
                     }
                  }
               }
            }

            // @ Navigation
            match ($key) {
               Keystrokes::RIGHT->value,
               Keystrokes::TAB->value => $this->cycle(+1),
               Keystrokes::LEFT->value,
               Keystrokes::SHIFT_TAB->value => $this->cycle(-1),
               '1', '2', '3', '4', '5', '6', '7', '8', '9' => $this->switch((int) $key),
               default => null
            };

            // ? `q` (or Ctrl+C via the restore net) ends the session
            if ($key === 'q' || $key === Keystrokes::CTRL_C->value) {
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
      $reset = self::_RESET_FORMAT;

      // @@ Label segments — the active one highlighted
      $segments = [];
      $ordinal = 0;
      foreach (array_keys($this->Frames) as $label) {
         $ordinal++;

         $segments[] = ($ordinal === $this->tab)
            ? "{$highlight} {$label} {$reset}"
            : "{$paint} {$label} {$reset}";
      }

      $glue = ($divisor !== '') ? "{$paint}{$divisor}{$reset}" : ' ';

      $Active->title = implode($glue, $segments);
   }
}
