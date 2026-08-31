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
use const PHP_EOL;
use function array_diff;
use function array_search;
use function array_values;
use function count;
use function feof;
use function implode;
use function in_array;
use function mb_stripos;
use function mb_substr;
use function ord;
use function strlen;
use function substr_count;
use function usleep;
use Generator;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Base\Listbox;


/**
 * Select — the "choose and confirm" option list: single selection by default
 * (radio marks) or multiple (`multiple` — checkbox marks), windowed by the
 * `Base\Listbox` engine (viewport, `N more` markers or its Scrollbar, query
 * accent), with incremental typeahead filtering, locked display-only rows and
 * an EOF-safe interaction loop. State is per-instance — no globals.
 *
 * `selecting()` runs the loop until Enter confirms: `↑`/`↓` (or `←`/`→`) aim
 * circularly, Space toggles, printable keys filter, Backspace pops the filter,
 * bare Esc clears it. An empty selection confirms the aimed option. On
 * non-interactive input (pipes) it renders once and finishes.
 */
class Select extends Component
{
   public Input $Input;

   private Output $Output;

   // * Config
   /** The title line above the list ('' = none; markup supported) */
   public string $title;
   /** Multiple selection (Space accumulates) — false keeps single selection:
    *  the confirmed set holds at most one index */
   public bool $multiple;
   /** Max visible options (0 renders all) — clipped edges say `↑/↓ N more`;
    *  `$Select->Listbox->scrollbar = true` swaps them for the bar */
   public int $viewport;
   /** @var null|array{0: string, 1: string} The mark glyph pair [selected,
    *  unselected] — null derives from the mode (`●`/`○` single, `◼`/`◻` multiple) */
   public null|array $marks;
   /** @var array<int,int> Display-only option indices — always marked, always
    *  visible, never aimed nor selected (pinned rows) */
   public array $locked;

   // * Data
   /** @var array<int,string> The options, top to bottom (plain text) */
   public array $options;
   /** @var array<int,string> Dim detail column per option index */
   public array $details;

   // * Metadata
   /** The windowed option list engine — restyle surface (its Scrollbar rides along) */
   public private(set) Listbox $Listbox;
   /** @var array<int,int> The selected option indices */
   public private(set) array $selected;
   /** The incremental typeahead filter */
   public private(set) string $filter;
   /** The aimed option index */
   public private(set) int $aimed;
   /** @var array<int,int> Visible row → option index (the filtered view) */
   private array $map;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->title = '';
      $this->multiple = false;
      $this->viewport = 0;
      $this->marks = null;
      $this->locked = [];

      // * Data
      $this->options = [];
      $this->details = [];

      // * Metadata
      $this->Listbox = new Listbox($Output);
      $this->Listbox->circular = true;
      $this->selected = [];
      $this->filter = '';
      $this->aimed = 0;
      $this->map = [];
   }


   /**
    * Aims an option by index — locked options never hold the aim (the aim
    * nudges forward to the next unlocked one).
    *
    * @param int $index The option index.
    *
    * @return self
    */
   public function aim (int $index): self
   {
      $total = count($this->options);

      // ? Out of range keeps the current aim
      if ($index < 0 || $index >= $total) {
         // :
         return $this;
      }

      // @@ A locked landing nudges forward (full-cycle guard)
      for ($moves = 0; $moves < $total; $moves++) {
         if (in_array($index, $this->locked) === false) {
            // * Metadata
            $this->aimed = $index;

            break;
         }

         $index = ($index + 1) % $total;
      }

      // :
      return $this;
   }

   /**
    * Controls the selection with one key: arrows aim, Space toggles, printable
    * keys filter, Backspace pops the filter, bare Esc clears it — Enter
    * confirms and finishes.
    *
    * @param string $char The key (full sequence).
    *
    * @return bool Whether the selection continues — false on Enter.
    */
   public function control (string $char): bool
   {
      // ! A lawful view and aim — control may run before any render
      if ($this->map === [] && $this->options !== []) {
         $this->sift();
      }
      $this->aim($this->aimed);

      switch ($char) {
         // @ Aiming
         case Keystrokes::UP->value:
         case Keystrokes::LEFT->value:
            $this->regress();

            break;
         case Keystrokes::DOWN->value:
         case Keystrokes::RIGHT->value:
            $this->advance();

            break;

         // @ Selecting
         case ' ':
            $this->toggle();

            break;

         case Keystrokes::CTRL_M->value: // Enter (raw terminals without icrnl)
         case PHP_EOL:
            // ? Enter with an empty selection confirms the aimed option — only
            //   while the filter shows it: over "(no matches)" the aim points at
            //   an option the frame does not draw, and confirming it would hand
            //   the caller something the user never saw
            if (
               $this->selected === [] && $this->options !== []
               && in_array($this->aimed, $this->locked) === false
               && in_array($this->aimed, $this->map, true) === true
            ) {
               // * Metadata
               $this->selected = [$this->aimed];
            }

            // :
            return false;

         // @ Filtering (incremental typeahead)
         case Keystrokes::BACKSPACE->value:
         case Keystrokes::CTRL_H->value:
            // ? Backspace pops the last filter character
            if ($this->filter !== '') {
               $this->filter = mb_substr($this->filter, 0, -1);

               $this->sift();
               $this->seek();
            }

            break;
         case Keystrokes::ESCAPE->value: // Escape (bare — no trailing sequence bytes)
            $this->filter = '';

            $this->sift();

            break;

         default:
            // ? Printable bytes accumulate in the filter (Space stays selection)
            if (strlen($char) === 1 && ord($char) >= 33 && ord($char) !== 127) {
               $this->filter .= $char;

               $this->sift();
               $this->seek();
            }
      }

      // :
      return true;
   }

   /**
    * Runs the selection until Enter confirms (or the input ends): renders a
    * frame, waits for a key, controls the state — yielding the live selection
    * after each key. With the `render` pin at `RETURN_OUTPUT` the loop never
    * paints: it yields each composed frame string for the host to place.
    * On non-interactive input it renders once and finishes.
    *
    * @return Generator
    */
   public function selecting (): Generator
   {
      // ? Render once and finish when no interactive terminal is attached
      if (BOOTGLY_TTY === false) {
         yield $this->render();

         // :
         return;
      }

      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();

      // ! Streaming: the host paints the frames (e.g. inside a Fieldset)
      $streaming = ($this->render === self::RETURN_OUTPUT);
      // ! Height (lines) of the last painted frame
      $height = 0;

      while (true) {
         // @ One frame
         if ($streaming === true) {
            yield (string) $this->render(self::RETURN_OUTPUT);
         }
         else {
            // ? Reposition to the first line of the previous frame and erase it —
            //   relative movement: absolute save/restore drifts when rendering
            //   scrolls the screen
            if ($height > 0) {
               $this->Output->Cursor->up($height, column: 1);
               $this->Output->Text->clear(lines: $height);
            }

            $frame = (string) $this->render(self::RETURN_OUTPUT);
            $height = substr_count($frame, "\n");

            $this->Output->render($frame);
         }

         // @@ Wait for a key (listen() assembles full sequences)
         while (true) {
            $char = $this->Input->listen();

            // ? EOF: interactive input will never arrive — finish with the current selection
            if ($char === false || feof($this->Input->stream) === true) {
               break 2;
            }
            // ? Key available — one key at a time (bursts and pipes never desync)
            if ($char !== '') {
               break;
            }

            usleep(50000);
         }

         // @ Control
         if ($this->control($char) === false) {
            break;
         }

         // ? The live selection, for hosts tracking it in real time
         if ($streaming === false) {
            yield $this->selected;
         }
      }

      $this->Input->configure(blocking: true, canonical: true, echo: true);
      $this->Output->Cursor->show();
   }

   /**
    * Renders one frame: the title, the typeahead hint and the Listbox rows —
    * each option prefixed by its selection mark; locked rows always marked.
    * A filter matching nothing says so instead of leaving a silent empty block.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ! The visible view and a lawful aim
      $this->sift();
      $this->aim($this->aimed);

      $frame = '';

      // ? Title
      if ($this->title !== '') {
         $frame .= "{$this->title}\n";
      }
      // ? Typeahead hint
      if ($this->filter !== '') {
         $frame .= "@#Black:/{$this->filter}@;\n";
      }

      // ? A filter matching nothing would leave a silent empty block — say so,
      //   and say how to get out of it
      if ($this->map === []) {
         $frame .= "@#Black:(no matches — Backspace erases, Esc clears)@;\n";
      }
      else {
         // ! Marks — the pair derives from the mode unless configured
         [$marked, $unmarked] = $this->marks
            ?? ($this->multiple === true ? ['◼', '◻'] : ['●', '○']);

         // @ Compose the display rows — the mark rides inside each option
         $options = [];
         $details = [];
         $aimed = 0;

         foreach ($this->map as $row => $index) {
            $mark = (
               in_array($index, $this->selected) === true
               || in_array($index, $this->locked) === true
            ) ? $marked : $unmarked;

            $options[] = "{$mark} {$this->options[$index]}";
            $details[] = $this->details[$index] ?? '';

            if ($index === $this->aimed) {
               $aimed = $row;
            }
         }

         // ! The Listbox windows, accents and paints the rows
         $Listbox = $this->Listbox;
         $Listbox->viewport = $this->viewport;
         // ? Cropped rows never wrap — wrapped physical rows break the
         //   relative-repaint height bookkeeping
         $Listbox->width = isSet(Terminal::$width) === true
            ? (int) Terminal::$width - 1
            : 80;
         $Listbox->query = $this->filter;
         $Listbox->options = $options;
         $Listbox->details = implode('', $details) === '' ? [] : $details;
         $Listbox->aim($aimed);

         $frame .= (string) $Listbox->render(self::RETURN_OUTPUT);
      }

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $frame;
      }

      $this->Output->render($frame);

      return null;
   }

   /**
    * Rebuilds the visible view from the filter — locked rows always show;
    * unlocked rows must match (case-insensitive).
    *
    * @return void
    */
   private function sift (): void
   {
      // * Metadata
      $this->map = [];

      foreach ($this->options as $index => $option) {
         $visible = $this->filter === ''
            || in_array($index, $this->locked) === true
            || mb_stripos($option, $this->filter) !== false;

         if ($visible === true) {
            $this->map[] = $index;
         }
      }
   }

   /**
    * Seeks the aim to the first visible unlocked option — after the filter
    * changes ('' keeps the aim).
    *
    * @return void
    */
   private function seek (): void
   {
      // ? An empty filter keeps the aim
      if ($this->filter === '') {
         return;
      }

      foreach ($this->map as $index) {
         if (in_array($index, $this->locked) === false) {
            // * Metadata
            $this->aimed = $index;

            return;
         }
      }
   }

   /**
    * Aims the next visible unlocked option, wrapping past the last.
    *
    * @return void
    */
   private function advance (): void
   {
      $count = count($this->map);
      // ?
      if ($count === 0) {
         return;
      }

      $position = array_search($this->aimed, $this->map, true);
      $position = $position === false ? -1 : (int) $position;

      // @@ Skip locked options (guard: all-locked lists stop after a full cycle)
      for ($moves = 0; $moves < $count; $moves++) {
         $position = ($position + 1) % $count;

         if (in_array($this->map[$position], $this->locked) === false) {
            // * Metadata
            $this->aimed = $this->map[$position];

            return;
         }
      }
   }

   /**
    * Aims the previous visible unlocked option, wrapping past the first.
    *
    * @return void
    */
   private function regress (): void
   {
      $count = count($this->map);
      // ?
      if ($count === 0) {
         return;
      }

      $position = array_search($this->aimed, $this->map, true);
      $position = $position === false ? 1 : (int) $position;

      // @@ Skip locked options (guard: all-locked lists stop after a full cycle)
      for ($moves = 0; $moves < $count; $moves++) {
         $position = ($position - 1 + $count) % $count;

         if (in_array($this->map[$position], $this->locked) === false) {
            // * Metadata
            $this->aimed = $this->map[$position];

            return;
         }
      }
   }

   /**
    * Toggles the aimed option in the selection — single selection swaps,
    * multiple accumulates; locked options never enter it.
    *
    * @return void
    */
   private function toggle (): void
   {
      // ? An aim the filter hides is not actionable — Space over "(no matches)"
      //   must not select what the frame does not draw (this also refuses the
      //   empty-list toggle, whose aim points at no option at all)
      if (in_array($this->aimed, $this->map, true) === false) {
         return;
      }

      // ? Locked options are display-only
      if (in_array($this->aimed, $this->locked) === true) {
         return;
      }

      // ? Toggling a selected option deselects it
      if (in_array($this->aimed, $this->selected) === true) {
         // * Metadata
         $this->selected = array_values(array_diff($this->selected, [$this->aimed]));

         return;
      }

      // * Metadata
      $this->selected = $this->multiple === true
         ? [...$this->selected, $this->aimed]
         : [$this->aimed];
   }
}
