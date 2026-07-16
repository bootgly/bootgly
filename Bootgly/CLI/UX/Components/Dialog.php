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
use function feof;
use function function_exists;
use function intdiv;
use function max;
use function microtime;
use function ord;
use function pcntl_signal_dispatch;
use function str_repeat;
use function strlen;
use function strtolower;
use function trim;
use function usleep;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Line;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Screen;
use Bootgly\CLI\UI\Atoms\Boxing;
use Bootgly\CLI\UI\Base\Frame;
use Bootgly\CLI\UI\Base\Frame\Borders;
use Bootgly\CLI\UI\Components\Question;


/**
 * Dialog — a modal box painted over the running interface: the body renders
 * through an internal Frame at absolute coordinates while an interactive
 * session owns every keystroke (input is structurally trapped — nothing else
 * reads stdin). Closing restores the screen by repainting the covered Boxing
 * components — or the whole main buffer when `$screen` wraps the session in
 * the alternate screen. Ready-made variants: `confirm()`, `alert()` and
 * `prompt()`; generic hosting writes into `$Frame->Output` between `open()`
 * and `close()`.
 */
class Dialog extends Component implements Boxing
{
   use Formattable;


   public Input $Input;
   public Output $Output;

   // * Config
   // # Geometry (outer rectangle, 1-based screen coordinates)
   /** Top screen row (1-based) */
   public int $row;
   /** Left screen column (1-based) */
   public int $column;
   /** Outer width, in columns */
   public int $width;
   /** Outer height, in rows */
   public int $height;
   /** Auto-center against the terminal on open()/resize() */
   public bool $centered;
   // # Style
   /** Hints color (Template markup) */
   public string $color;
   // # Behavior
   /** Wrap the session in the alternate screen buffer (standalone over scrolled output) */
   public bool $screen;
   // # Timing
   /** Seconds per interactive tick — held keys never accelerate the clock */
   public float $throttle;

   // * Data
   /** The body host — write into its isolated Output; style via its Borders/title */
   public private(set) Frame $Frame;
   /** @var array<int,Boxing> The covered components, repainted on close (painter order) */
   public private(set) array $Covered;

   // * Metadata
   /** Painted right now? */
   public private(set) bool $opened;
   /** Last confirm() answer */
   public private(set) null|bool $confirmed;
   /** Last prompt() answer */
   public private(set) string $answer;
   private Screen $Screen;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      // # Geometry
      $this->row = 1;
      $this->column = 1;
      $this->width = 50;
      $this->height = 7;
      $this->centered = true;
      // # Style
      $this->color = '@#Black:';
      // # Behavior
      $this->screen = false;
      // # Timing
      $this->throttle = 0.05;

      // * Data
      $Frame = new Frame($Output);
      $Frame->Borders = Borders::Round;
      $Frame->follow = false;
      $Frame->wrap = true;
      $this->Frame = $Frame;
      $this->Covered = [];

      // * Metadata
      $this->opened = false;
      $this->confirmed = null;
      $this->answer = '';
      $this->Screen = new Screen($Output);
   }


   /**
    * Registers the components covered by the modal — each one repaints when
    * the dialog closes (in painter order). Cover everything the rectangle
    * overlaps; covering more only costs diff-blit comparisons.
    *
    * @param Boxing ...$Boxes The covered components.
    *
    * @return self
    */
   public function cover (Boxing ...$Boxes): self
   {
      foreach ($Boxes as $Box) {
         $this->Covered[] = $Box;
      }

      // :
      return $this;
   }

   /**
    * Opens the dialog — centers against the terminal (unless `$centered` is
    * disabled), enters the alternate screen when `$screen` asks for it and
    * paints the box. Non-blocking: the caller keeps control to write into
    * `$Frame->Output`, re-render and `close()`.
    *
    * @return self
    */
   public function open (): self
   {
      // ?
      if ($this->opened === true) {
         return $this;
      }

      // * Metadata
      $this->opened = true;

      // ! Screen size resolved once (the Terminal statics are set by the CLI boot)
      if ($this->centered === true) {
         [$columns, $lines] = isSet(Terminal::$columns) === true
            ? [Terminal::$columns, Terminal::$lines]
            : Screen::measure();

         $this->center($columns, $lines);
      }

      // ? Standalone sessions preserve the whole screen in the terminal itself
      if ($this->screen === true && BOOTGLY_TTY === true) {
         $this->Screen->open();
      }

      // @
      $this->render();

      // :
      return $this;
   }

   /**
    * Closes the dialog restoring what it covered: leaves the alternate screen
    * (the terminal restores the main buffer by itself) or blanks the rectangle
    * and repaints the covered components. Idempotent.
    *
    * @return self
    */
   public function close (): self
   {
      // ?
      if ($this->opened === false) {
         return $this;
      }

      // * Metadata
      $this->opened = false;

      // ? Non-interactive output flows — nothing was painted in place
      if (BOOTGLY_TTY === false) {
         return $this;
      }

      // ? The restore strategy follows the mode LATCHED at open() (the Screen
      //   records whether the alternate buffer was entered) — never the live
      //   `$screen` Config, which may have been flipped mid-session
      if ($this->Screen->alternative === true) {
         // @ The terminal restores the main screen buffer by itself
         $this->Screen->close();
      }
      else {
         // @ Blank the rectangle, then the covered components repaint over it
         $this->blank();

         foreach ($this->Covered as $Box) {
            $Box->invalidate();
            $Box->render();
         }
      }

      // ! The next open() repaints the full rectangle
      $this->Frame->invalidate();

      // :
      return $this;
   }

   /**
    * Invalidates the box — the next render repaints the full rectangle
    * (screen cleared externally, overlapped, ...).
    *
    * @return void
    */
   public function invalidate (): void
   {
      $this->Frame->invalidate();
   }

   /**
    * Renders the dialog box — pure paint: geometry pushes to the internal
    * Frame and its diff-blit writes only the changed rows.
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

      $this->arrange();

      // :
      return $this->Frame->render($mode);
   }

   /**
    * Resizes against a new terminal size — recenters (unless `$centered` is
    * disabled) and, while the box is painted, wipes the screen and repaints
    * the covered components and the box. A closed dialog only recenters — the
    * screen belongs to its owner. The signature matches the `Screen::watch`
    * resize handler.
    *
    * @param int $columns The new terminal width, in columns.
    * @param int $lines The new terminal height, in rows.
    *
    * @return void
    */
   public function resize (int $columns, int $lines): void
   {
      // ?
      if ($this->centered === true) {
         $this->center($columns, $lines);
      }

      // ? A closed dialog left the screen to its owner; pipes have no artifacts
      if ($this->opened === false || BOOTGLY_TTY === false) {
         return;
      }

      // @ Wipe the screen and repaint: covered components first, the box on top
      $this->Output->clear();

      // ? In screen mode the covered components live in the main buffer — untouched
      if ($this->Screen->alternative === false) {
         foreach ($this->Covered as $Box) {
            $Box->invalidate();
            $Box->render();
         }
      }

      $this->Frame->invalidate();
      $this->render();
   }

   /**
    * Asks a modal yes/no confirmation: `y`/`n` answer; Enter, Esc and EOF
    * assume the default. Non-interactive input keeps the Question semantics
    * (no box on pipes).
    *
    * @param string $prompt The confirmation prompt.
    * @param bool $default The value assumed on Enter, Esc or EOF.
    *
    * @return bool
    */
   public function confirm (string $prompt, bool $default = false): bool
   {
      // ? Non-interactive input delegates to the Question semantics
      if (BOOTGLY_TTY === false) {
         $Question = new Question($this->Input, $this->Output);

         $confirmed = $Question->confirm($prompt, $default);

         // * Metadata
         $this->confirmed = $confirmed;

         // :
         return $confirmed;
      }

      // ! Standalone calls wrap the interaction with open/close; nested calls
      //   inherit the caller's terminal modes and restore its hosted body
      $standalone = $this->opened === false;
      $rows = $this->snapshot();

      // ! Body — prompt + keys hint
      $paint = TemplateEscaped::render($this->color);
      $reset = self::_RESET_FORMAT;
      $keys = $default === true ? '[Y/n]' : '[y/N]';

      $this->compose(
         $prompt,
         '',
         "{$paint}{$keys} · Enter/Esc assume the default{$reset}"
      );

      if ($standalone === true) {
         $this->open();
         $this->trap();
      }

      $confirmed = $default;

      // @@ Render → drain the pending keys → pace the tick
      while (true) {
         $started = microtime(true);

         $this->render();

         $key = $this->listen();

         // ? Channel closed assumes the default
         if ($key === false) {
            break;
         }

         // ? Drained
         if ($key === '') {
            $this->pace($started);

            continue;
         }

         // @ Answer keys — Enter and Esc assume the default
         $answer = strtolower($key);
         if ($answer === 'y') {
            $confirmed = true;

            break;
         }
         if ($answer === 'n') {
            $confirmed = false;

            break;
         }
         if (
            $key === Keystrokes::ENTER->value
            || $key === "\r"
            || $key === Keystrokes::ESCAPE->value
         ) {
            break;
         }
      }

      if ($standalone === true) {
         $this->free();
         $this->close();
      }
      else {
         $this->restore($rows);
      }

      // * Metadata
      $this->confirmed = $confirmed;

      // :
      return $confirmed;
   }

   /**
    * Shows a modal message acknowledged by any key (or EOF). Non-interactive
    * output writes the message and returns immediately.
    *
    * @param string $message The message.
    *
    * @return void
    */
   public function alert (string $message): void
   {
      // ? Non-interactive output flows without acknowledgment
      if (BOOTGLY_TTY === false) {
         $this->Output->write("{$message}\n");

         return;
      }

      // ! Standalone calls wrap the interaction with open/close; nested calls
      //   inherit the caller's terminal modes and restore its hosted body
      $standalone = $this->opened === false;
      $rows = $this->snapshot();

      // ! Body — message + keys hint
      $paint = TemplateEscaped::render($this->color);
      $reset = self::_RESET_FORMAT;

      $this->compose(
         $message,
         '',
         "{$paint}Press any key to continue{$reset}"
      );

      if ($standalone === true) {
         $this->open();
         $this->trap();
      }

      // @@ Render → wait the first key or a closed channel
      while (true) {
         $started = microtime(true);

         $this->render();

         $key = $this->listen();

         // ? Any key (or a closed channel) acknowledges
         if ($key !== '') {
            break;
         }

         $this->pace($started);
      }

      if ($standalone === true) {
         $this->free();
         $this->close();
      }
      else {
         $this->restore($rows);
      }
   }

   /**
    * Asks a modal line of text with the Line editor (arrows, Home/End,
    * Backspace/Delete, kill keys): Enter submits — an empty value keeps the
    * default; Esc and EOF keep the default. Non-interactive input keeps the
    * Question semantics (no box on pipes).
    *
    * @param string $prompt The prompt.
    * @param string $default The value kept on empty submit, Esc or EOF.
    *
    * @return string
    */
   public function prompt (string $prompt, string $default = ''): string
   {
      // ? Non-interactive input delegates to the Question semantics
      if (BOOTGLY_TTY === false) {
         $Question = new Question($this->Input, $this->Output);
         $Question->prompt = $prompt;
         $Question->default = $default;

         $answer = $Question->ask();

         // * Metadata
         $this->answer = $answer;

         // :
         return $answer;
      }

      // ! Standalone calls wrap the interaction with open/close; nested calls
      //   inherit the caller's terminal modes and restore its hosted body
      $standalone = $this->opened === false;
      $rows = $this->snapshot();

      // ! Line editor windowed to the box interior
      $this->arrange();

      $Line = new Line;
      $Line->width = max(1, $this->Frame->columns);

      // ! Keys hint
      $paint = TemplateEscaped::render($this->color);
      $reset = self::_RESET_FORMAT;
      $keys = $default !== ''
         ? "Enter submits · Esc keeps [{$default}]"
         : 'Enter submits · Esc cancels';

      // ! Initial editor view — composed before the box paints, so a reused
      //   dialog never flashes the previous variant's body
      $this->compose(
         "{$prompt}:",
         TemplateEscaped::render($Line->render()),
         '',
         "{$paint}{$keys}{$reset}"
      );

      if ($standalone === true) {
         $this->open();
         $this->trap();
      }

      $answer = $default;

      // @@ Compose the editor view → render → read → control/feed → pace
      while (true) {
         $started = microtime(true);

         $this->compose(
            "{$prompt}:",
            TemplateEscaped::render($Line->render()),
            '',
            "{$paint}{$keys}{$reset}"
         );
         $this->render();

         $key = $this->listen();

         // ? Channel closed keeps the default
         if ($key === false) {
            break;
         }

         // ? Drained
         if ($key === '') {
            $this->pace($started);

            continue;
         }

         // ? Esc keeps the default
         if ($key === Keystrokes::ESCAPE->value) {
            break;
         }

         // ? Enter submits — an empty value keeps the default (trimmed, so a
         //   whitespace-only submit matches the non-interactive Question path)
         if ($key === Keystrokes::ENTER->value || $key === "\r") {
            $value = trim($Line->value);
            if ($value !== '') {
               $answer = $value;
            }

            break;
         }

         // @ Edit keys control the buffer; printable input feeds it
         if (
            $key[0] === "\e"
            || $key === "\x7F"
            || (strlen($key) === 1 && ord($key) < 32)
         ) {
            $Line->control($key);
         }
         else {
            $Line->feed($key);
         }
      }

      if ($standalone === true) {
         $this->free();
         $this->close();
      }
      else {
         $this->restore($rows);
      }

      // * Metadata
      $this->answer = $answer;

      // :
      return $answer;
   }

   /**
    * Centers the rectangle against a terminal size.
    *
    * @param int $columns The terminal width, in columns.
    * @param int $lines The terminal height, in rows.
    *
    * @return void
    */
   private function center (int $columns, int $lines): void
   {
      // * Config
      $this->row = max(1, intdiv($lines - $this->height, 2) + 1);
      $this->column = max(1, intdiv($columns - $this->width, 2) + 1);
   }

   /**
    * Arranges the internal Frame — it receives the dialog rectangle.
    * Pure geometry.
    *
    * @return void
    */
   private function arrange (): void
   {
      $this->Frame->row = $this->row;
      $this->Frame->column = $this->column;
      $this->Frame->width = $this->width;
      $this->Frame->height = $this->height;
   }

   /**
    * Rebuilds the body — complete lines only: an unterminated tail never
    * renders in a Frame.
    *
    * @param string ...$rows The body rows (raw SGR allowed; no line breaks).
    *
    * @return void
    */
   private function compose (string ...$rows): void
   {
      $this->Frame->clear();

      foreach ($rows as $line) {
         $this->Frame->Output->write("{$line}\n");
      }
   }

   /**
    * Snapshots the hosted body — the drained buffer rows, taken before a
    * nested variant composes over them.
    *
    * @return array<int,string>
    */
   private function snapshot (): array
   {
      $this->Frame->drain();

      // :
      return $this->Frame->buffer;
   }

   /**
    * Restores a hosted body snapshot after a nested variant — the caller's
    * rows return to the Frame and the box repaints.
    *
    * @param array<int,string> $rows The snapshotted body rows.
    *
    * @return void
    */
   private function restore (array $rows): void
   {
      $this->Frame->clear();

      foreach ($rows as $row) {
         $this->Frame->Output->write("{$row}\n");
      }

      $this->render();
   }

   /**
    * Blanks the rectangle with literal spaces — no erase escapes (the Frame
    * blit convention), so uncovered surroundings stay untouched.
    *
    * @return void
    */
   private function blank (): void
   {
      $spaces = str_repeat(' ', $this->width);

      // @@
      for ($offset = 0; $offset < $this->height; $offset++) {
         $this->Output->Cursor->moveTo(line: $this->row + $offset, column: $this->column);
         $this->Output->write($spaces);
      }
   }

   /**
    * Enters the interactive session — raw input and a hidden cursor: the loop
    * that follows owns every keystroke.
    *
    * @return void
    */
   private function trap (): void
   {
      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();
   }

   /**
    * Leaves the interactive session — restores the terminal modes and the
    * cursor.
    *
    * @return void
    */
   private function free (): void
   {
      $this->Input->configure(blocking: true, canonical: true, echo: true);
      $this->Output->Cursor->show();
   }

   /**
    * Reads one key attempt: `false` on a closed channel, an empty string when
    * drained, the key bytes otherwise. CSI and SS3 read until their final
    * byte, and UTF-8 lead bytes assemble their continuation bytes — the tail
    * bytes may lag the first on non-blocking channels, so empty reads retry
    * briefly before the sequence is given up.
    *
    * @return string|false
    */
   private function listen (): string|false
   {
      $key = $this->Input->read(1);

      // ? Channel closed
      if ($key === false || feof($this->Input->stream) === true) {
         // :
         return false;
      }

      // ? Drained
      if ($key === '') {
         if (function_exists('pcntl_signal_dispatch') === true) {
            pcntl_signal_dispatch();
         }

         // :
         return '';
      }

      // ? Escape sequences
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
            // @@ CSI — reads until its final byte (0x40-0x7E)
            while (true) {
               $byte = $this->catch();

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
         else if ($next === 'O') {
            // @ SS3 (F1-F4, application-mode Home/End) — exactly one final byte
            $key .= $this->catch();
         }

         // :
         return $key;
      }

      // ? UTF-8 lead bytes assemble their continuation bytes, so hosted line
      //   editors always receive complete characters (mirrors Input::scan)
      $lead = ord($key);
      if ($lead >= 0xC0) {
         $remaining = match (true) {
            $lead >= 0xF0 => 3,
            $lead >= 0xE0 => 2,
            default => 1
         };

         // @@
         while ($remaining-- > 0) {
            $byte = $this->catch();

            if ($byte === '') {
               break;
            }

            $key .= $byte;
         }
      }

      // :
      return $key;
   }

   /**
    * Catches one lagging sequence byte — empty reads retry briefly (the tail
    * bytes may lag on non-blocking channels).
    *
    * @return string The byte — empty when the channel stays drained.
    */
   private function catch (): string
   {
      $byte = '';

      // @@
      for ($retry = 0; $retry < 5; $retry++) {
         $byte = (string) $this->Input->read(1);
         if ($byte !== '') {
            break;
         }

         usleep(1000);
      }

      // :
      return $byte;
   }

   /**
    * Paces the interactive tick — the clock is fixed whatever the keyboard
    * does.
    *
    * @param float $started The tick start (microtime).
    *
    * @return void
    */
   private function pace (float $started): void
   {
      $remaining = $this->throttle - (microtime(true) - $started);

      // ?
      if ($remaining > 0) {
         usleep((int) ($remaining * 1000000));
      }
   }

   public function __destruct ()
   {
      // ? The screen restores even when a session dies before close()
      $this->close();
   }
}
