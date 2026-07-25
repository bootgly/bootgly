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
use function array_shift;
use function ceil;
use function count;
use function explode;
use function feof;
use function implode;
use function max;
use function mb_strlen;
use function microtime;
use function ord;
use function preg_replace;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function strlen;
use function strncmp;
use function substr;
use function usleep;
use Generator;

use Bootgly\ABI\Code\__String;
use Bootgly\ABI\Code\__String\Escapeable\Mouse\Reportable;
use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Lines;
use Bootgly\CLI\Terminal\Input\Mousestrokes;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Scrollarea;


/**
 * Bottom-fixed input with content scrolling above (like Claude Code, Codex, ...).
 * The content area is a Scrollarea band (internally buffered — `PgUp`/`PgDn` scroll
 * it while the input stays fixed); a DECSTBM scroll region protects the frame.
 * `feed()` writes app content into the band; `prompting()` yields submitted lines
 * with `↑`/`↓` history recall and `Alt+Enter` multiline input. Non-interactive
 * input degrades to a plain stdin line loop — identical consumer code.
 */
class Prompt extends Component
{
   use Formattable;
   use Reportable;


   /** Interruption notice lifetime, in seconds */
   protected const float TIMEOUT = 2.0;


   public Input $Input;
   public Output $Output;

   // * Config
   /** The input line prefix */
   public string $prompt;
   /** Max history entries */
   public int $history;
   /** The border line character (above and below the input row) */
   public string $border;
   /** @var array{left: string, right: string} Fixed texts above the top border */
   public array $top;
   /** @var array{left: string, right: string} Fixed texts below the bottom border */
   public array $bottom;
   /** The notice shown on the first Ctrl+C (a second within the timeout ends) */
   public string $interruption;
   /** Buffered content band (default): internal scrollbar + mouse reporting —
    *  `Ctrl+T` toggles the selection mode (releases the mouse for native selection).
    *  `false` = native flow: content joins the terminal scrollback — wheel scrolling
    *  and text selection stay fully native (no internal scrollbar). */
   public bool $buffered;
   /** Mouse support (band mode) — wheel scrolls the band; the scrollbar accepts hover,
    *  click and drag. Native text selection pauses while the reporting is on
    *  (`Ctrl+T` toggles it; `Shift` bypasses it). */
   public bool $mouse;
   /** The notice shown on the bottom border while the selection mode is on (Ctrl+T) */
   public string $selection;

   // * Data
   /** The multiline input buffer — one Line per row */
   public private(set) Lines $Lines;
   /** The buffered content band (scrollable above the input frame) */
   public private(set) Scrollarea $Scrollarea;
   /** @var array<string> History entries (oldest first) */
   public private(set) array $entries;

   // * Metadata
   /** Content region bottom row (the frame rows come next) */
   private int $region;
   /** Frame rows: optional top texts + border + input + border + optional bottom texts */
   public int $rows {
      get => 3
         + count($this->Lines->Lines) - 1
         + ($this->top['left'] !== '' || $this->top['right'] !== '' ? 1 : 0)
         + ($this->bottom['left'] !== '' || $this->bottom['right'] !== '' ? 1 : 0);
   }
   /** History recall index (count(entries) = the draft) */
   private int $recalled;
   /** The draft saved while recalling history */
   private string $draft;
   /** Next content line in the native flow (1-based screen row) */
   private int $flowed;
   /** Dragging the scrollbar thumb? */
   private bool $dragging;
   /** Mouse reporting currently on? */
   private bool $tracking;
   /** Selection mode on (Ctrl+T released the mouse for native selection)? */
   private bool $selecting;
   /** First Ctrl+C timestamp (0.0 = none) */
   private float $interrupted;
   /** Whether the interruption notice is active */
   private bool $interrupting {
      get => $this->interrupted > 0.0
         && microtime(true) - $this->interrupted <= self::TIMEOUT;
   }
   public private(set) bool $started;
   public private(set) bool $finished;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->prompt = '> ';
      $this->history = 100;
      $this->border = '─';
      $this->top = ['left' => '', 'right' => ''];
      $this->bottom = ['left' => '', 'right' => ''];
      $this->interruption = 'Press Ctrl+C again to exit';
      $this->buffered = true;
      $this->mouse = true;
      $this->selection = 'Selection mode · Ctrl+T resumes the mouse';

      // * Data
      $this->Lines = new Lines;
      $this->Scrollarea = new Scrollarea($Output);
      $this->entries = [];

      // * Metadata
      $this->region = 0;
      $this->recalled = 0;
      $this->draft = '';
      $this->flowed = 0;
      $this->dragging = false;
      $this->tracking = false;
      $this->selecting = false;
      $this->interrupted = 0.0;
      $this->started = false;
      $this->finished = false;
   }


   /**
    * Renders the input frame: optional top texts, the top border, the input row
    * (prompt + line editor + multiline hint), the bottom border and optional
    * bottom texts.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      $width = (int) Terminal::$width;

      // ! Frame lines
      $lines = [];

      // ? Fixed texts above the top border
      if ($this->top['left'] !== '' || $this->top['right'] !== '') {
         $lines[] = $this->align($this->top['left'], $this->top['right'], $width);
      }

      // # Borders
      $border = "@#Black:" . str_repeat($this->border, $width) . "@;";
      $lines[] = $border;

      // # Input rows — the prompt marks the first row, broken lines stack above
      //   the active one and continuation rows align under the marker
      //   (raw SGR prefix — Template resets swallow adjacent spaces/underscores)
      $prefix = self::wrap(self::_CYAN_BRIGHT_FOREGROUND) . $this->prompt . self::_RESET_FORMAT;
      $indent = str_repeat(' ', mb_strlen($this->prompt));

      foreach ($this->Lines->Lines as $index => $Line) {
         $lines[] = ($index === 0 ? $prefix : $indent)
            . ($index === $this->Lines->row ? $Line->render() : $Line->value);
      }

      // ? Notices replace part of the bottom border while active
      if ($this->interrupting === true) {
         $lines[] = $this->stamp($this->interruption, '@#Yellow:');
      }
      else if ($this->selecting === true) {
         $lines[] = $this->stamp($this->selection, '@#Cyan:');
      }
      else {
         $lines[] = $border;
      }

      // ? Fixed texts below the bottom border
      if ($this->bottom['left'] !== '' || $this->bottom['right'] !== '') {
         $lines[] = $this->align($this->bottom['left'], $this->bottom['right'], $width);
      }

      $frame = implode("\n", $lines);

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $frame;
      }

      // @ Repaint the frame rows in place (the last screen rows — bottom-fixed)
      foreach ($lines as $index => $line) {
         // ! php://memory resolves the markup per line
         $Memory = new Output('php://memory');
         $Memory->render($line);
         rewind($Memory->stream);
         $painted = (string) stream_get_contents($Memory->stream);

         $this->Output->Cursor->moveTo(line: $this->region + 1 + $index, column: 1);
         $this->Output->Text->trim(right: true);
         $this->Output->write($painted);
      }

      return null;
   }

   /**
    * Stamps a notice into a border line: two border characters, the colored text
    * and the border remainder.
    *
    * @param string $text The notice text.
    * @param string $color The Template color marker (e.g. `@#Yellow:`).
    *
    * @return string
    */
   private function stamp (string $text, string $color): string
   {
      $width = (int) Terminal::$width;

      $remaining = $width - 4 - mb_strlen($text);
      if ($remaining < 0) {
         $remaining = 0;
      }

      // :
      return "@#Black:" . str_repeat($this->border, 2) . "@; "
         . "{$color}{$text}@;"
         . " @#Black:" . str_repeat($this->border, $remaining) . "@;";
   }

   /**
    * Aligns two fixed texts on one line: left text, padding, right text.
    *
    * @param string $left The left-aligned text (Template markup supported).
    * @param string $right The right-aligned text (Template markup supported).
    * @param int $width The line width, in columns.
    *
    * @return string
    */
   private function align (string $left, string $right, int $width): string
   {
      // ! Visible lengths (markup resolved, escapes stripped)
      $Memory = new Output('php://memory');
      $Memory->render("{$left}\u{1}{$right}");
      rewind($Memory->stream);
      $painted = (string) stream_get_contents($Memory->stream);
      $stripped = (string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $painted);

      [$visibleLeft, $visibleRight] = explode("\u{1}", $stripped, 2);

      $padding = $width - mb_strlen($visibleLeft) - mb_strlen($visibleRight);
      if ($padding < 1) {
         $padding = 1;
      }

      // :
      return $left . str_repeat(' ', $padding) . $right;
   }

   /**
    * Fits the content region to the current frame height — the frame grows one
    * row per broken line, so the band above it shrinks by the same amount.
    * Re-clips the scroll region only when the height actually changed.
    *
    * @return void
    */
   private function fit (): void
   {
      $region = (int) Terminal::$height - $this->rows;

      // ? Never let the frame eat the whole screen
      if ($region < 1) {
         $region = 1;
      }

      // ?
      if ($region === $this->region && $this->Scrollarea->rows === $region) {
         return;
      }

      $previous = $this->region;
      $this->region = $region;

      // @ Rows the frame gives back still carry its paint — erase them before
      //   they belong to the content again (the frame only repaints its own)
      if ($previous > 0 && $region > $previous) {
         for ($row = $previous + 1; $row <= $region; $row++) {
            $this->Output->Cursor->moveTo(line: $row, column: 1);
            $this->Output->Text->clear(lines: 1);
         }
      }

      // ? Native flow keeps no scroll region — only the flow position clamps
      if ($this->buffered === false) {
         if ($this->flowed > $region) {
            $this->flowed = $region;
         }

         return;
      }

      $this->Scrollarea->rows = $region;

      // @ Clip the scroll region (DECSTBM homes the cursor — reposition after)
      $this->Output->Viewport->clip(1, $region);
   }

   /**
    * Starts the prompt: clips the content scroll region and draws the input row.
    *
    * @return void
    */
   public function start (): void
   {
      // ?
      if ($this->started === true) {
         return;
      }

      $this->started = true;

      // ? Non-interactive input has no region — plain line loop
      if (BOOTGLY_TTY === false) {
         return;
      }

      // ! Content region: every row above the bottom-fixed input frame
      $this->region = (int) Terminal::$height - $this->rows;

      // ? Buffered band: a DECSTBM region + the Scrollarea + the mouse reporting
      if ($this->buffered === true) {
         // ! Content band over the region rows
         $this->Scrollarea->row = 1;
         $this->Scrollarea->width = (int) Terminal::$width;

         $this->fit();
      }
      else {
         // ! Flow position: below the existing screen content when the cursor is
         //   queryable (real TTYs); otherwise content grows from the frame up
         $row = $this->Output->Cursor->position['row'];

         $this->flowed = ($row > 0 && $row < $this->region) ? $row : $this->region;
      }

      // @ Raw input mode — signals off so Ctrl+C arrives as a byte (two-stage exit).
      //   The extended keyboard protocol is what makes Shift+Enter reportable at
      //   all; terminals without it simply ignore the negotiation and Enter stays
      //   the only way to submit.
      $this->Input->extended = true;
      $this->Input->configure(blocking: false, canonical: false, echo: false, signals: false);
      $this->Output->Cursor->hide();

      // ? Mouse reporting (band mode: SGR + all events — wheel, click, drag and hover)
      if ($this->buffered === true && $this->mouse === true) {
         $this->track(true);
      }

      $this->render();
   }

   /**
    * Feeds app content above the bottom-fixed input frame.
    * Native flow (default): the frame clears, the content writes above it and the
    * screen scrolls through its last row — content (and only content) enters the
    * terminal scrollback, so wheel scrolling and text selection stay native.
    * Band mode: the content buffers into the Scrollarea; while scrolled up, new
    * content holds the position (the scrollbar tracks it).
    *
    * @param string $content The content (Template markup supported).
    *
    * @return void
    */
   public function feed (string $content): void
   {
      // ? Non-interactive output writes plainly
      if (BOOTGLY_TTY === false || $this->started === false) {
         $this->Output->render("{$content}\n");

         return;
      }

      // ? Native flow: content scrolls into the terminal scrollback, frame stays fixed
      if ($this->buffered === false) {
         // ! Painted content and its visual line count (wraps included)
         $Memory = new Output('php://memory');
         $Memory->render($content);
         rewind($Memory->stream);
         $painted = (string) stream_get_contents($Memory->stream);

         $width = (int) Terminal::$width;
         $lines = 0;
         foreach (explode("\n", $painted) as $line) {
            $stripped = (string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $line);
            $visible = mb_strlen($stripped);

            $lines += $width > 0 ? max(1, (int) ceil($visible / $width)) : 1;
         }

         // @ Clear the frame — its rows never enter the scrollback
         $this->Output->Cursor->moveTo(line: $this->region + 1, column: 1);
         $this->Output->Text->clear(down: true);

         // ? Scroll the screen up to fit the content above the frame — line feeds
         //   at the last screen row are the only path into the scrollback
         $overflow = ($this->flowed + $lines - 1) - $this->region;
         if ($overflow > 0) {
            $this->Output->Cursor->moveTo(line: (int) Terminal::$height, column: 1);
            $this->Output->write(str_repeat("\n", $overflow));

            $this->flowed -= $overflow;
            if ($this->flowed < 1) {
               $this->flowed = 1;
            }
         }

         // @ Write the content at the flow position
         $this->Output->Cursor->moveTo(line: $this->flowed, column: 1);
         $this->Output->write($painted);

         $this->flowed += $lines;
         // ? `region + 1` = full content area — the next feed scrolls before writing
         if ($this->flowed > $this->region + 1) {
            $this->flowed = $this->region + 1;
         }

         // @ Repaint the frame at the bottom
         $this->render();

         return;
      }

      // @ Buffer + repaint the band
      $this->Scrollarea->feed($content);

      // @ Redraw the input frame
      $this->render();
   }

   /**
    * Turns the mouse reporting on/off (SGR extended mode + all events).
    * While off, the terminal's native text selection works again.
    *
    * @param bool $enabled Whether to arm or release the reporting.
    *
    * @return void
    */
   private function track (bool $enabled): void
   {
      // * Metadata
      $this->tracking = $enabled;

      if ($enabled === true) {
         $this->Output->escape(self::_MOUSE_SET_SGR_EXT_MODE);
         $this->Output->escape(self::_MOUSE_ENABLE_ALL_EVENT_REPORTING);

         return;
      }

      $this->Output->escape(self::_MOUSE_DISABLE_ALL_EVENT_REPORTING);
      $this->Output->escape(self::_MOUSE_UNSET_SGR_EXT_MODE);
   }

   /**
    * Handles a pointer (SGR mouse) report: the wheel scrolls the content band;
    * the scrollbar thumb accepts hover, click and drag; a track click jumps the view.
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

      // @ The wheel scrolls the content band (three rows per notch)
      if ($Action === Mousestrokes::SCROLL_UP) {
         $this->Scrollarea->scroll(-3);

         return;
      }
      if ($Action === Mousestrokes::SCROLL_DOWN) {
         $this->Scrollarea->scroll(+3);

         return;
      }

      // @ Dragging the thumb follows the pointer line
      if ($this->dragging === true) {
         // ? Release drops the thumb
         if ($state === Mousestrokes::UNCLICKED->value) {
            $this->dragging = false;

            return;
         }

         $this->Scrollarea->aim($line);

         return;
      }

      // @ Pressing the scrollbar grabs the thumb or jumps the view
      if ($Action === Mousestrokes::LEFT_CLICK && $state === Mousestrokes::CLICKED->value) {
         $hit = $this->Scrollarea->hit($column, $line);

         if ($hit === 'thumb' || $hit === 'track') {
            // ? A track press jumps the view before the grab
            if ($hit === 'track') {
               $this->Scrollarea->aim($line);
            }

            $this->dragging = true;
         }

         return;
      }

      // @ Hovering highlights the thumb
      if ($Action === Mousestrokes::NONE_CLICK_WITH_MOVEMENT) {
         $this->Scrollarea->hover(
            $this->Scrollarea->hit($column, $line) === 'thumb'
         );
      }
   }

   /**
    * Yields submitted lines until a double Ctrl+C, Ctrl+D or EOF.
    * The first Ctrl+C shows a notice on the bottom border — a second press within
    * the timeout ends; otherwise the notice expires and the editing continues.
    * `↑`/`↓` recall the history (the current draft is preserved); `Alt+Enter`
    * accumulates multiline input, submitted together on Enter.
    *
    * @return Generator<int,string>
    */
   public function prompting (): Generator
   {
      $this->start();

      // ? Non-interactive input: plain stdin line loop — identical consumer code
      if (BOOTGLY_TTY === false) {
         while (($line = $this->Input->scan()) !== false) {
            yield $line;
         }

         $this->finished = true;

         // :
         return;
      }

      // @@ Edit until Ctrl+C, Ctrl+D or EOF
      while (true) {
         // @@ Wait for a key (listen() assembles whole sequences — parameterized
         //    keys like PgUp `\e[5~`, SS3, SGR mouse reports and Alt+key pairs)
         while (true) {
            $key = $this->Input->listen();

            // ? EOF: interactive input will never arrive
            if ($key === false || feof($this->Input->stream) === true) {
               break 2;
            }
            // ? Key available
            if ($key !== '') {
               break;
            }

            // ? The interruption notice expires
            if ($this->interrupted > 0.0 && $this->interrupting === false) {
               $this->interrupted = 0.0;
               $this->render();
            }

            usleep(50000);
         }

         // ? Mouse reports route to the pointer handler (the frame stays untouched)
         if (strncmp($key, "\e[<", 3) === 0) {
            $this->point($key);

            continue;
         }

         // ? Any key other than Ctrl+C dismisses the interruption notice
         if ($key !== Keystrokes::CTRL_C->value) {
            $this->interrupted = 0.0;
         }

         switch ($key) {
            // @ Ending
            case Keystrokes::CTRL_C->value:
               // ? The first Ctrl+C only warns — a second within the timeout ends
               if ($this->interrupting === true) {
                  break 2;
               }

               $this->interrupted = microtime(true);
               break;
            case Keystrokes::CTRL_D->value:
               break 2;

            // @ Selection mode (band mode: Ctrl+T releases/resumes the mouse reporting)
            case Keystrokes::CTRL_T->value:
               if ($this->buffered === true && $this->mouse === true) {
                  $this->selecting = ($this->selecting === false);
                  $this->track($this->selecting === false);
               }
               break;

            // ? Shift+Enter breaks the line — it only arrives when the terminal
            //   speaks the extended keyboard protocol (negotiated in start()),
            //   since a plain terminal sends CR for Enter and Shift+Enter alike
            case Keystrokes::SHIFT_ENTER->value:
               $this->Lines->control(Keystrokes::ENTER->value);

               // @ The frame grew by a row — the content band gives it up
               $this->fit();
               break;

            // @ Row navigation, then history recall at the edges — a multiline
            //   input walks its own rows first (the history is one row away)
            case Keystrokes::UP->value:
               if ($this->Lines->row > 0) {
                  $this->Lines->control($key);

                  break;
               }

               if ($this->recalled > 0) {
                  // ? The draft survives the first recall
                  if ($this->recalled === count($this->entries)) {
                     $this->draft = $this->Lines->value;
                  }

                  $this->recalled--;

                  $this->Lines->load($this->entries[$this->recalled]);
                  $this->fit();
               }
               break;
            case Keystrokes::DOWN->value:
               if ($this->Lines->row < count($this->Lines->Lines) - 1) {
                  $this->Lines->control($key);

                  break;
               }

               if ($this->recalled < count($this->entries)) {
                  $this->recalled++;

                  $this->Lines->load(
                     $this->recalled === count($this->entries)
                        ? $this->draft
                        : $this->entries[$this->recalled]
                  );
                  $this->fit();
               }
               break;

            // @ Content scrolling (band mode: one band page per key;
            //   native flow scrolls through the terminal itself)
            case Keystrokes::PAGEUP->value:
               if ($this->buffered === true) {
                  $this->Scrollarea->scroll(-($this->region - 1));
               }
               break;
            case Keystrokes::PAGEDOWN->value:
               if ($this->buffered === true) {
                  $this->Scrollarea->scroll(+($this->region - 1));
               }
               break;

            default:
               // ? Enter submits the line (plus the multiline buffer)
               if ($key === Keystrokes::ENTER->value || $key === Keystrokes::CTRL_M->value) {
                  $line = $this->Lines->value;

                  $this->Lines->reset();
                  $this->draft = '';

                  // @ The frame shrank back to one input row
                  $this->fit();

                  // @ Record the history (bounded ring) — lone empty lines never enter
                  if ($line !== '') {
                     $this->entries[] = $line;

                     if (count($this->entries) > $this->history) {
                        array_shift($this->entries);
                     }
                  }
                  $this->recalled = count($this->entries);

                  // ? Submitting sticks the content band back to the bottom
                  if ($this->buffered === true && $this->Scrollarea->stuck === false) {
                     $this->Scrollarea->stick();
                  }

                  $this->render();

                  yield $line;

                  break;
               }

               // ? The buffer classifies the key itself (control vs printable)
               //   and merges rows on Backspace/Delete at the edges
               $rows = count($this->Lines->Lines);

               $this->Lines->control($key);

               // ? A merged row shrank the frame
               if (count($this->Lines->Lines) !== $rows) {
                  $this->fit();
               }
         }

         $this->render();
      }

      $this->finish();
   }

   /**
    * Finishes the prompt: resets the scroll region (band mode) and restores the
    * terminal. A leaked scroll region breaks the terminal — also invoked by the
    * destructor.
    *
    * @return void
    */
   public function finish (): void
   {
      // ?
      if ($this->finished === true || $this->started === false) {
         return;
      }

      $this->finished = true;

      if (BOOTGLY_TTY === false) {
         return;
      }

      // ? Mouse reporting off (a leaked tracking floods the shell with escapes)
      if ($this->tracking === true) {
         $this->track(false);
      }

      // ? Band mode: reset the scroll region (full screen — DECSTBM homes the cursor)
      if ($this->buffered === true) {
         $this->Output->Viewport->clip();
         $this->Output->Cursor->moveTo(line: (int) Terminal::$height, column: 1);
      }

      $this->Output->write("\n");

      $this->Input->configure(blocking: true, canonical: true, echo: true);
      $this->Output->Cursor->show();
   }

   public function __destruct ()
   {
      $this->finish();
   }
}
