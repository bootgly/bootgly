<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
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
use function in_array;
use function is_array;
use function is_int;
use function max;
use function mb_strlen;
use function mb_strwidth;
use function mb_substr;
use function microtime;
use function min;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rewind;
use function rtrim;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function stream_get_contents;
use function strncmp;
use function substr;
use function substr_count;
use function usleep;
use Closure;
use Generator;

use Bootgly\ABI\Code\__String;
use Bootgly\ABI\Code\__String\Escapeable\Mouse\Reportable;
use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Lines;
use Bootgly\CLI\Terminal\Input\Mousestrokes;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Base\Flyout;
use Bootgly\CLI\UI\Base\Listbox;
use Bootgly\CLI\UI\Components\Scrollarea;


/**
 * Bottom-fixed input with content scrolling above (like Claude Code, Codex, ...).
 * The content area is a Scrollarea band (internally buffered — `PgUp`/`PgDn` scroll
 * it while the input stays fixed); a DECSTBM scroll region protects the frame.
 * `feed()` writes app content into the band; `prompting()` yields submitted lines
 * with `↑`/`↓` history recall and `Shift+Enter` multiline input (the frame grows
 * one row per break). Non-interactive input degrades to a plain stdin line loop
 * — identical consumer code.
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
   /** @var array<string,array<int|string,string|array<string,string>>|Closure> Context-menu
    *  triggers — key = the leading symbol (`'/'`, `'@'`, `'#'`, ...); value = options or a
    *  Closure receiving the typed query (the cursor token without the symbol) and returning
    *  options. Option shapes: `'command'` (bare), `'value' => 'label'` (insert the key, show
    *  the label) or `'command' => ['skeleton' => ..., 'description' => ...]` (the key is the
    *  command — the skeleton and the description show per `listing`/`resolution`). Commands
    *  are full tokens including the symbol. Empty disables the menu. */
   public array $triggers;
   /** @var array<int,string> Parts shown beside each command while the menu lists options —
    *  any of `skeleton`, `description` */
   public array $listing;
   /** @var array<int,string> Parts shown once a single option remains (command resolved) —
    *  any of `skeleton`, `description` */
   public array $resolution;
   /** @var array<string,array{border?: string, prompt?: string}> Per-trigger frame styling —
    *  while a symbol's menu or argument hint is up, `border` recolors the input frame lines
    *  and paints the marker (markup token), and `prompt` replaces the input marker text */
   public array $styles;
   /** @var array<int,string> Trigger symbols absorbed as mode prefixes — typed into an empty
    *  input, the symbol lives in the marker instead of the buffer (a raw bash `!`, e.g.);
    *  symbols outside this list stay literal in the text (inline mentions like `@`) */
   public array $modes;
   /** @var array<string,bool> Per-trigger line breaking — `false` locks the input to a
    *  single line while the symbol is active (Shift+Enter is ignored); absent = allowed */
   public array $breaks;
   /** @var array<string,string> Shortcut hint slots below the input (key => action) —
    *  they take the bottom-left slot over `bottom['left']` when set */
   public array $shortcuts;
   /** The shortcut key paint (markup token) — the action stays dim */
   public string $tint;

   // * Data
   /** The multiline input buffer — one Line per row */
   public private(set) Lines $Lines;
   /** The buffered content band (scrollable above the input frame) */
   public private(set) Scrollarea $Scrollarea;
   /** The trigger context-menu overlay, rising over the input frame */
   public private(set) Flyout $Flyout;
   /** The trigger context-menu option list */
   public private(set) Listbox $Listbox;
   /** @var array<string> History entries (oldest first) */
   public private(set) array $entries;

   // * Metadata
   /** Content region bottom row (the frame rows come next) */
   private int $region;
   /** Frame rows: optional context menu + optional top texts + border + input + border + optional bottom texts —
    *  the open menu covers the top-texts row and sits flush on the top border;
    *  an open bottom sheet replaces the whole frame with its own rows */
   public int $rows {
      get => $this->sheet !== ''
         ? substr_count($this->sheet, "\n") + 1
         : 3
            + count($this->Lines->Lines) - 1
            + $this->Flyout->height
            + (($this->top['left'] !== '' || $this->top['right'] !== '') && $this->menu === '' ? 1 : 0)
            + ($this->bottom['left'] !== '' || $this->bottom['right'] !== '' || $this->shortcuts !== [] ? 1 : 0);
   }
   /** History recall index (count(entries) = the draft) */
   private int $recalled;
   /** The draft saved while recalling history */
   private string $draft;
   /** The cursor token the last search saw ('' = none) */
   private string $token;
   /** @var array<int,array{0:string,1:string}> Current trigger matches as [value, label] pairs */
   private array $matches;
   /** The token Esc dismissed — its menu stays closed until the token changes */
   private string $dismissed;
   /** The composed context-menu block ('' = closed) */
   private string $menu;
   /** The composed bottom-sheet block ('' = closed) — replaces the whole input
    *  frame, anchored to the terminal's last row (input and shortcuts covered) */
   private string $sheet;
   /** The active trigger symbol ('' = none) — drives the per-trigger styling */
   private string $symbol;
   /** The absorbed mode prefix ('' = none) — a mode trigger symbol typed into
    *  an empty input lives in the marker, not in the buffer */
   private string $absorbed;
   /** Next content line in the native flow (1-based screen row) */
   private int $flowed;
   /** Dragging the band scrollbar thumb? */
   private bool $dragging;
   /** Dragging the menu scrollbar thumb? */
   private bool $sliding;
   /** Mouse reporting currently on? */
   private bool $tracking;
   /** Selection mode on (Ctrl+T released the mouse for native selection)? */
   private bool $selecting;
   /** A terminal resize arrived (SIGWINCH) — the loops re-anchor on it */
   private bool $resized;
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
      $this->triggers = [];
      $this->listing = ['description'];
      $this->resolution = ['skeleton', 'description'];
      $this->styles = [];
      $this->modes = [];
      $this->breaks = [];
      $this->shortcuts = [];
      $this->tint = '@#White:';

      // * Data
      $this->Lines = new Lines;
      $this->Scrollarea = new Scrollarea($Output);
      // ? Overlay shades rise toward the aim: terminal < panel < aimed row
      $this->Flyout = new Flyout($Output);
      $this->Flyout->bordered = true;
      $this->Flyout->width = 0;
      $this->Flyout->background = self::wrap(self::_BLACK_DIM_BACKGROUND);
      $this->Listbox = new Listbox($Output);
      $this->Listbox->viewport = 5;
      $this->Listbox->tint = '@#Cyan:';
      $this->Listbox->background = self::wrap(self::_BLACK_SOFT_BACKGROUND);
      $this->Listbox->scrollbar = true;
      $this->Listbox->circular = true;
      $this->entries = [];

      // * Metadata
      $this->region = 0;
      $this->recalled = 0;
      $this->draft = '';
      $this->token = '';
      $this->matches = [];
      $this->dismissed = '';
      $this->menu = '';
      $this->sheet = '';
      $this->symbol = '';
      $this->absorbed = '';
      $this->flowed = 0;
      $this->resized = false;
      $this->dragging = false;
      $this->sliding = false;
      $this->tracking = false;
      $this->selecting = false;
      $this->interrupted = 0.0;
      $this->started = false;
      $this->finished = false;
   }


   /**
    * Renders the input frame: optional top texts, the top border, one row per
    * input line (the prompt marks the first, continuations align under it), the
    * bottom border and optional bottom texts.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      $width = (int) Terminal::$width;

      // ! Frame lines
      // ? An open bottom sheet replaces the whole input frame — its block
      //   anchors to the terminal's last row, covering the input, the borders
      //   and the shortcuts until it closes
      if ($this->sheet !== '') {
         $lines = explode("\n", $this->sheet);
      }
      else {
         $lines = [];

         // ? The trigger context menu rises flush over the input frame
         if ($this->menu !== '') {
            foreach (explode("\n", rtrim($this->menu, "\n")) as $row) {
               $lines[] = $row;
            }
         }
         // ? Fixed texts above the top border — the open menu covers their row
         else if ($this->top['left'] !== '' || $this->top['right'] !== '') {
            $lines[] = $this->align($this->top['left'], $this->top['right'], $width);
         }

         // ! Per-trigger styling — an active symbol recolors the frame lines and
         //   swaps the input marker (an absorbed mode prefix wins)
         $active = $this->absorbed !== '' ? $this->absorbed : $this->symbol;
         $style = $this->styles[$active] ?? [];
         $edge = $style['border'] ?? '@#Black:';
         $glyph = $style['prompt'] ?? $this->prompt;

         // # Borders — the input keeps its two frame lines; the open menu's box
         //   sits flush on the top one
         $rule = str_repeat($this->border, $width);
         $border = "{$edge}{$rule}@;";
         $lines[] = $border;

         // # Input rows — the prompt marks the first row, broken lines stack above
         //   the active one and continuation rows align under the marker
         //   (raw SGR prefix — Template resets swallow adjacent spaces/underscores;
         //   an active trigger paints the marker with its border token)
         $paint = isSet($style['border']) === true
            ? TemplateEscaped::render($style['border'])
            : self::wrap(self::_CYAN_BRIGHT_FOREGROUND);

         $prefix = $paint . $glyph . self::_RESET_FORMAT;
         $indent = str_repeat(' ', mb_strlen($glyph));

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

         // ? Fixed texts below the bottom border — shortcut slots take the left
         $left = $this->bottom['left'];
         if ($this->shortcuts !== []) {
            $slots = [];
            foreach ($this->shortcuts as $key => $action) {
               $slots[] = "{$this->tint}{$key}\e[0m@#Black::{$action}\e[0m";
            }

            $left = implode('  ', $slots);
         }

         if ($left !== '' || $this->bottom['right'] !== '') {
            $lines[] = $this->align($left, $this->bottom['right'], $width);
         }
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
    * Re-anchors the frame after a terminal resize: the emulator reflows the
    * painted rows on its own, so no row is trustworthy — wipe the screen,
    * refit the content band to the new size and repaint it. The caller
    * repaints the frame (and recomposes any open menu) right after.
    *
    * @return void
    */
   private function resize (): void
   {
      // * Metadata
      $this->resized = false;

      // ! No painted row survives an emulator reflow — wipe and re-anchor
      $this->Output->Cursor->moveTo(line: 1, column: 1);
      $this->Output->Text->clear(down: true);

      // ! The band geometry follows the new size (fit() re-clips the region)
      $this->Scrollarea->width = (int) Terminal::$width;
      $this->region = 0;
      $this->fit();

      // @ Repaint the band content
      if ($this->buffered === true) {
         $this->Scrollarea->render();
      }
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

      // ? Terminal resizes re-anchor the frame — the handler only records the
      //   new size (painting from a signal frame would interleave with any
      //   in-flight write); the wait loops consume the flag and repaint
      if (isSet(Terminal::$Terminal) === true) {
         Terminal::$Terminal->Screen->watch(function (int $columns, int $lines): void {
            Terminal::$columns = $columns;
            Terminal::$lines = $lines;
            Terminal::$width = $columns;
            Terminal::$height = $lines;

            $this->resized = true;
         });
      }

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

      // ? Foreign escape sequences would corrupt the managed frame — colors
      //   (SGR) pass, cursor/erase controls drop (a fed `clear` output must
      //   not wipe the band behind the buffer's back)
      $content = (string) preg_replace_callback(
         '/\e\[[^@-~]*[@-~]/',
         static fn (array $found): string => str_ends_with($found[0], 'm') === true ? $found[0] : '',
         $content
      );
      $content = (string) preg_replace(
         '/\e\][^\x07\e]*(?:\x07|\e\\\\)?|\e[^[]|[\x00-\x08\x0B-\x1A\x1C-\x1F\x7F]/',
         '',
         $content
      );

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
    * Handles a pointer (SGR mouse) report: the wheel scrolls the content band
    * (or aims the open trigger menu under the pointer); the band scrollbar
    * thumb accepts hover, click and drag; a track click jumps the view; over
    * the open menu, movement aims the option under the pointer, a left press
    * selects it (as Tab does) and the menu bar accepts hover, click and drag.
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

      // ! Trigger-menu geometry — the flyout box opens the frame rows, the
      //   option window sits right after its top border and the bar strip
      //   rides the inner right edge (full-width boxes only)
      $menued = $this->matches !== [] && $this->menu !== '';
      $boxed = $menued === true
         && $line > $this->region && $line <= $this->region + $this->Flyout->height;
      $row = $line - ($this->region + 2);
      $optioned = $menued === true && $row >= 0 && $row < $this->Listbox->height;
      $barred = $optioned === true
         && $this->Flyout->width === 0
         && $column === (int) Terminal::$width - 2
         && $this->Listbox->Scrollbar->check() === true;

      // @ The wheel aims the open menu under the pointer — and scrolls the
      //   content band (three rows per notch) elsewhere
      if ($Action === Mousestrokes::SCROLL_UP || $Action === Mousestrokes::SCROLL_DOWN) {
         if ($boxed === true) {
            $Action === Mousestrokes::SCROLL_UP
               ? $this->Listbox->regress()
               : $this->Listbox->advance();

            $this->search();
            $this->fit();
            $this->render();

            return;
         }

         $this->Scrollarea->scroll($Action === Mousestrokes::SCROLL_UP ? -3 : +3);

         return;
      }

      // @ Dragging the band thumb follows the pointer line
      if ($this->dragging === true) {
         // ? Release drops the thumb
         if ($state === Mousestrokes::UNCLICKED->value) {
            $this->dragging = false;

            return;
         }

         $this->Scrollarea->aim($line);

         return;
      }

      // @ Dragging the menu thumb slides the option window
      if ($this->sliding === true) {
         // ? Release (or a closed menu) drops the thumb
         if ($state === Mousestrokes::UNCLICKED->value || $menued === false) {
            $this->sliding = false;

            return;
         }

         $this->slide($line);

         return;
      }

      if ($Action === Mousestrokes::LEFT_CLICK && $state === Mousestrokes::CLICKED->value) {
         // ? A press on the menu bar grabs the thumb — a track press jumps the
         //   window first (a thumb-center press holds)
         if ($barred === true) {
            $this->slide($line);

            $this->sliding = true;

            return;
         }

         // ? A press on a menu option selects it — complete, keep the menu
         //   flow (the argument hint rises on the resolved command)
         if ($optioned === true) {
            $this->Listbox->aim($this->Listbox->Window->first + $row);
            $this->complete();

            $this->search();
            $this->fit();
            $this->render();

            return;
         }

         // @ Pressing the band scrollbar grabs the thumb or jumps the view
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

      if ($Action === Mousestrokes::NONE_CLICK_WITH_MOVEMENT) {
         // ? Movement over the menu bar accents its thumb
         if ($barred === true) {
            $this->Scrollarea->hover(false);

            $Scrollbar = $this->Listbox->Scrollbar;
            $Scrollbar->row = $this->region + 2;
            $Scrollbar->column = $column;
            $Scrollbar->hover($Scrollbar->hit($column, $line) === 'thumb');

            return;
         }

         // ? Movement over the menu aims the option under the pointer
         if ($optioned === true) {
            $this->Scrollarea->hover(false);
            $this->Listbox->Scrollbar->hover(false);

            $aimed = $this->Listbox->Window->first + $row;
            if ($aimed !== $this->Listbox->aimed) {
               $this->Listbox->aim($aimed);

               $this->search();
               $this->fit();
               $this->render();
            }

            return;
         }

         // ? Leaving the menu drops its bar accent
         if ($menued === true) {
            $this->Listbox->Scrollbar->hover(false);
         }

         // @ Hovering highlights the band thumb
         $this->Scrollarea->hover(
            $this->Scrollarea->hit($column, $line) === 'thumb'
         );
      }
   }

   /**
    * Slides the trigger-menu window by the bar thumb center: maps the pointer
    * line back to the window's first option and aims leading in the drag
    * direction — the window top going up, the window bottom going down. A
    * thumb-center press maps to the current window and holds.
    *
    * @param int $line The screen line (1-based).
    *
    * @return void
    */
   private function slide (int $line): void
   {
      $Scrollbar = $this->Listbox->Scrollbar;
      $Scrollbar->row = $this->region + 2;
      $Scrollbar->column = (int) Terminal::$width - 2;

      $current = $this->Listbox->Window->first;
      $first = $Scrollbar->aim($line);

      // ? The thumb holds — nothing slides
      if ($first === $current) {
         return;
      }

      $this->Listbox->aim(
         $first < $current ? $first : $first + $Scrollbar->height - 1
      );

      $this->search();
      $this->fit();
      $this->render();
   }

   /**
    * Decodes a trigger option item into its `[value, label, skeleton,
    * description]` quad — the structured shape carries the command in its key.
    *
    * @param int|string $value The option key.
    * @param string|array<string,string> $item The option item.
    *
    * @return array{0: string, 1: string, 2: string, 3: string}
    */
   private function decode (int|string $value, string|array $item): array
   {
      // ? The structured shape carries the command in its key
      if (is_array($item) === true) {
         // :
         return [
            (string) $value,
            (string) $value,
            (string) ($item['skeleton'] ?? ''),
            (string) ($item['description'] ?? '')
         ];
      }

      // :
      return [
         is_int($value) === true ? $item : (string) $value,
         $item,
         '',
         ''
      ];
   }

   /**
    * Re-queries the trigger context menu for the token under the cursor on the
    * active row (delimited by whitespace, up to the cursor). A token whose
    * first character is a registered trigger symbol opens the menu; static
    * options filter by prefix and a Closure source filters by itself. The
    * composed block lands in `menu` and its height in `Flyout->height` — run
    * `fit()` after, so the frame accounts for it.
    *
    * @return void
    */
   private function search (): void
   {
      // ! The token under the cursor
      $head = mb_substr($this->Lines->Line->value, 0, $this->Lines->column);

      $token = '';
      if (preg_match('/(\S+)$/u', $head, $found) === 1) {
         $token = $found[1];
      }

      // ? The absorbed mode prefix rides the line-leading token invisibly
      if ($this->absorbed !== '' && $this->Lines->row === 0 && $head === $token) {
         $token = "{$this->absorbed}{$token}";
      }

      // ? A changed token re-aims the list at the top
      if ($token !== $this->token) {
         $this->Listbox->aim(0);
      }
      $this->token = $token;

      // ? A dismissal only lives while its token stays under the cursor —
      //   erasing, submitting or typing past it re-arms the menu
      if ($this->dismissed !== '' && $token !== $this->dismissed) {
         $this->dismissed = '';
      }

      $symbol = mb_substr($token, 0, 1);

      // ! Candidates
      $open = $token !== ''
         && isSet($this->triggers[$symbol]) === true
         && $token !== $this->dismissed;

      if ($open === true) {
         $Trigger = $this->triggers[$symbol];

         /** @var array<int|string,string|array<string,string>> $candidates */
         $candidates = $Trigger instanceof Closure
            ? $Trigger(mb_substr($token, 1))
            : $Trigger;

         // @@ Normalize to [value, command, skeleton, description] — static
         //   options filter by command prefix
         $this->matches = [];

         foreach ($candidates as $value => $item) {
            [$value, $label, $skeleton, $description] = $this->decode($value, $item);

            if ($Trigger instanceof Closure === false && str_starts_with($label, $token) === false) {
               continue;
            }

            $this->matches[] = [$value, $label, $skeleton, $description];
         }

         $open = $this->matches !== [];
      }

      // ! Menu rows — the matched options, or the line-leading command's hint
      $options = [];
      $details = [];
      $query = $token;

      if ($open === true) {
         // ! Parts beside each command — a resolved command (single option)
         //   shows its `resolution` parts; a listing shows its `listing` parts
         $parts = count($this->matches) === 1 ? $this->resolution : $this->listing;

         foreach ($this->matches as [$value, $label, $skeleton, $description]) {
            // ? The skeleton rides beside the command (never inserted by Tab)
            if ($skeleton !== '' && in_array('skeleton', $parts, true) === true) {
               $label .= " {$skeleton}";
            }

            $options[] = $label;
            $details[] = in_array('description', $parts, true) === true ? $description : '';
         }
      }
      else {
         // ? The cursor token opens nothing — a resolved line-leading command
         //   keeps its skeleton/description up while the arguments are typed
         $this->matches = [];

         [$options, $details, $query] = $this->hint();

         // ? Closed: no token, unregistered symbol, dismissal or nothing to hint
         if ($options === []) {
            $this->Listbox->options = [];
            $this->Listbox->details = [];
            $this->Flyout->content = '';
            $this->Flyout->render(self::RETURN_OUTPUT);
            $this->menu = '';
            $this->symbol = '';

            return;
         }
      }

      // * Metadata
      $this->symbol = mb_substr($query, 0, 1);

      // ? A full-width box spans the terminal — pad/crop the rows to its inner
      //   columns (borders + paddings take 4, the scrollbar column one more),
      //   so the aimed-row highlight sweeps the whole line
      if ($this->Flyout->width === 0) {
         $columns = isSet(Terminal::$width) === true ? (int) Terminal::$width : 80;

         $this->Listbox->width = max(1, $columns - 5 - mb_strwidth($this->Listbox->marker));
      }

      // ? An all-empty detail column keeps the plain row layout
      if (implode('', $details) === '') {
         $details = [];
      }

      // @ Compose the menu block — Listbox rows inside the Flyout box, with the
      //   typed token lighting up inside each match
      $this->Listbox->query = $query;
      $this->Listbox->options = $options;
      $this->Listbox->details = $details;

      $rows = (string) $this->Listbox->render(self::RETURN_OUTPUT);

      $this->Flyout->content = rtrim($rows, "\n");
      $this->menu = (string) $this->Flyout->render(self::RETURN_OUTPUT);
   }

   /**
    * Hints the arguments of the line-leading command: when the first word of
    * the input exactly matches a command of its trigger, its `resolution`
    * parts (skeleton, description) stay up while the arguments are typed.
    * Menus opened by the cursor token take precedence — this runs only when
    * the cursor opens none.
    *
    * @return array{0: array<int,string>, 1: array<int,string>, 2: string} Options, details and the query.
    */
   private function hint (): array
   {
      // ! The line-leading token — the absorbed mode prefix rides it invisibly
      $lead = '';
      if (preg_match('/^(\S+)/u', $this->Lines->lines[0] ?? '', $found) === 1) {
         $lead = $found[1];
      }

      if ($this->absorbed !== '' && $lead !== '') {
         $lead = "{$this->absorbed}{$lead}";
      }

      $symbol = mb_substr($lead, 0, 1);

      // ? No lead or unregistered symbol
      if ($lead === '' || isSet($this->triggers[$symbol]) === false) {
         // :
         return [[], [], ''];
      }

      $Trigger = $this->triggers[$symbol];

      /** @var array<int|string,string|array<string,string>> $candidates */
      $candidates = $Trigger instanceof Closure
         ? $Trigger(mb_substr($lead, 1))
         : $Trigger;

      // @@ An exact command match with something to show earns the hint
      foreach ($candidates as $value => $item) {
         [$value, $label, $skeleton, $description] = $this->decode($value, $item);

         // ? Not this command
         if ($value !== $lead && $label !== $lead) {
            continue;
         }

         $option = $label;
         if ($skeleton !== '' && in_array('skeleton', $this->resolution, true) === true) {
            $option .= " {$skeleton}";
         }

         $detail = in_array('description', $this->resolution, true) === true ? $description : '';

         // ? The bare command with nothing to add hints nothing
         if ($option === $lead && $detail === '') {
            break;
         }

         // :
         return [[$option], [$detail], $lead];
      }

      // :
      return [[], [], ''];
   }

   /**
    * Completes the cursor token to the aimed option's value. The menu stays
    * open on the resolved command, showing its `resolution` parts (skeleton,
    * description) as an argument hint — Esc closes it, typing past the token
    * or submitting dismisses it naturally.
    *
    * @return void
    */
   private function complete (): void
   {
      // ? Nothing aimed
      if (isSet($this->matches[$this->Listbox->aimed]) === false) {
         return;
      }

      $value = $this->matches[$this->Listbox->aimed][0];

      // ? The absorbed mode prefix is not in the buffer — strip it from the
      //   token measure and from the inserted value
      $length = mb_strlen($this->token);
      if ($this->absorbed !== '' && str_starts_with($this->token, $this->absorbed) === true) {
         $length -= mb_strlen($this->absorbed);

         if (str_starts_with($value, $this->absorbed) === true) {
            $value = mb_substr($value, mb_strlen($this->absorbed));
         }
      }

      // @ Replace the token (the part before the cursor) with the value
      $Line = $this->Lines->Line;
      $cursor = $this->Lines->column;

      $head = mb_substr($Line->value, 0, $cursor - $length);
      $tail = mb_substr($Line->value, $cursor);

      $Line->value = "{$head}{$value}{$tail}";
      $Line->move(mb_strlen($head) + mb_strlen($value));
   }

   /**
    * Opens a bottom sheet — the full-width Flyout anchored to the terminal
    * footer, REPLACING the input frame (input, borders and shortcuts stay
    * covered) while a Listbox of options browses inside it (`↑`/`↓` aim, Enter
    * selects, Esc cancels — the dim hint rides on the very last row). Options
    * take the trigger shape: bare commands, `'value' => 'label'` pairs or
    * `'command' => ['skeleton' => ..., 'description' => ...]` — so a trigger's
    * own options browse directly (`pick($triggers['/'])`). Call it between
    * `prompting()` yields; the input frame repaints itself on close.
    *
    * @param array<int|string,string|array<string,string>> $options The options, in the trigger shape.
    * @param null|string $title The sheet title (markup supported).
    * @param null|string $hint The dim hint row inside the sheet (null hides it).
    *
    * @return null|string The selected value — null on cancel (or non-interactive input).
    */
   public function pick (
      array $options,
      null|string $title = null,
      null|string $hint = 'Enter selects · Esc cancels'
   ): null|string
   {
      // ? Non-interactive input has no sheet to open
      if (BOOTGLY_TTY === false || $this->started === false || $options === []) {
         // :
         return null;
      }

      // ! The sheet quads — no filter: the sheet browses every option
      $values = [];
      $labels = [];
      $details = [];
      foreach ($options as $value => $item) {
         [$value, $label, $skeleton, $description] = $this->decode($value, $item);

         $values[] = $value;
         $labels[] = $skeleton !== '' ? "{$label} {$skeleton}" : $label;
         $details[] = $description;
      }

      // ! A sheet-local Listbox — the trigger menu's visual config carries over
      //   (a shallow clone: the shared Window is re-set on every render)
      $Listbox = clone $this->Listbox;
      $Listbox->query = '';
      $Listbox->options = $labels;
      $Listbox->details = implode('', $details) === '' ? [] : $details;
      $Listbox->aim(0);

      $header = $this->Flyout->title;
      $this->Flyout->title = $title;

      $picked = null;

      // @@ Browse until Enter or Esc
      while (true) {
         // ? The sheet grows tall — every option shows when the screen allows
         //   it (recomputed per pass: a resize lands mid-browse)
         $Listbox->viewport = max(1, min(count($labels), (int) Terminal::$height - 8));

         // ? A full-width box pads/crops the rows to its inner columns
         //   (borders + paddings take 4, the scrollbar column one more)
         if ($this->Flyout->width === 0) {
            $columns = isSet(Terminal::$width) === true ? (int) Terminal::$width : 80;

            $Listbox->width = max(1, $columns - 5 - mb_strwidth($Listbox->marker));
         }

         // @ Compose the sheet — the boxed Listbox rows, with the dim hint
         //   riding below the box on the terminal's very last row
         $this->Flyout->content = rtrim((string) $Listbox->render(self::RETURN_OUTPUT), "\n");

         $block = rtrim((string) $this->Flyout->render(self::RETURN_OUTPUT), "\n");
         if ($hint !== null && $hint !== '') {
            $block .= "\n @#Black:{$hint}\e[0m";
         }

         $this->sheet = $block;

         $this->fit();
         $this->render();

         // @@ Wait for a key
         while (true) {
            // ? A terminal resize re-anchors the frame — recompose the sheet
            if ($this->resized === true) {
               $this->resize();

               continue 2;
            }

            $key = $this->Input->listen();

            // ? EOF cancels the sheet
            if ($key === false || feof($this->Input->stream) === true) {
               break 2;
            }
            // ? Key available
            if ($key !== '') {
               break;
            }

            usleep(50000);
         }

         // ? Mouse reports stay out of the sheet
         if (strncmp($key, "\e[<", 3) === 0) {
            continue;
         }

         switch ($key) {
            case Keystrokes::UP->value:
               $Listbox->regress();
               break;
            case Keystrokes::DOWN->value:
               $Listbox->advance();
               break;
            case Keystrokes::ENTER->value:
            case Keystrokes::CTRL_M->value:
               $picked = $values[$Listbox->aimed] ?? null;
               break 2;
            case Keystrokes::ESCAPE->value:
            case Keystrokes::CTRL_C->value:
            case Keystrokes::CTRL_D->value:
               break 2;
         }
      }

      // @ Close the sheet — the input frame repaints in its place
      $this->sheet = '';
      $this->Flyout->title = $header;

      $this->search();
      $this->fit();
      $this->render();

      // :
      return $picked;
   }

   /**
    * Yields submitted lines until a double Ctrl+C, Ctrl+D or EOF.
    * The first Ctrl+C shows a notice on the bottom border — a second press within
    * the timeout ends; otherwise the notice expires and the editing continues.
    * `↑`/`↓` walk the input rows first and reach the history at the edges (the
    * current draft is preserved); `Shift+Enter` breaks the line, and Enter
    * submits every row joined by `\n`.
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
            // ? A terminal resize re-anchors the whole frame
            if ($this->resized === true) {
               $this->resize();
               $this->search();
               $this->fit();
               $this->render();
            }

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

         // ? Trigger context-menu keys take precedence while the menu is open
         if ($this->matches !== []) {
            $consumed = true;

            switch ($key) {
               case Keystrokes::UP->value:
                  $this->Listbox->regress();
                  break;
               case Keystrokes::DOWN->value:
                  $this->Listbox->advance();
                  break;
               case Keystrokes::TAB->value:
                  $this->complete();
                  break;
               case Keystrokes::ESCAPE->value:
                  // ? Esc closes the menu, keeping the text — until the token changes
                  $this->dismissed = $this->token;
                  break;
               case Keystrokes::ENTER->value:
               case Keystrokes::CTRL_M->value:
                  // ? Enter submits the aimed option — complete, then let the
                  //   submit flow take the completed line (Esc first to submit
                  //   the text as typed)
                  $this->complete();

                  $consumed = false;
                  break;
               default:
                  $consumed = false;
            }

            if ($consumed === true) {
               // @ Recompose the menu (the window may grow/shrink with the aim)
               $this->search();
               $this->fit();
               $this->render();

               continue;
            }
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
               // ? An active trigger may lock the input to a single line
               $active = $this->absorbed !== '' ? $this->absorbed : $this->symbol;
               if (($this->breaks[$active] ?? true) === false) {
                  break;
               }

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
                  // ? The absorbed mode prefix rejoins the submitted line
                  $line = "{$this->absorbed}{$this->Lines->value}";

                  $this->Lines->reset();
                  $this->draft = '';
                  $this->absorbed = '';

                  // @ The frame shrank back to one input row — menu included
                  $this->search();
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

               // ? A mode trigger symbol typed into an empty input is absorbed
               //   as the mode prefix — it lives in the marker, not in the buffer
               if (
                  $this->absorbed === ''
                  && $this->Lines->value === ''
                  && isSet($this->triggers[$key]) === true
                  && in_array($key, $this->modes, true) === true
               ) {
                  $this->absorbed = $key;

                  break;
               }
               // ? Backspace on the empty input releases the mode — as if the
               //   invisible leading symbol were erased
               if (
                  $this->absorbed !== ''
                  && $this->Lines->value === ''
                  && ($key === Keystrokes::BACKSPACE->value || $key === Keystrokes::CTRL_H->value)
               ) {
                  $this->absorbed = '';

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

         // @ Any handled key may have moved the cursor token — requery the menu
         $this->search();
         $this->fit();

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

      // ? Resize watching off (the handler outliving the prompt would repaint
      //   a finished frame)
      if (isSet(Terminal::$Terminal) === true) {
         Terminal::$Terminal->Screen->watch(null);
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
