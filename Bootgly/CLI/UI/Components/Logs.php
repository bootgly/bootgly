<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components;


use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;
use function array_filter;
use function array_keys;
use function array_pop;
use function array_slice;
use function array_values;
use function count;
use function date;
use function explode;
use function implode;
use function is_array;
use function json_decode;
use function json_encode;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function ord;
use function preg_replace;
use function preg_split;
use function sort;
use function str_pad;
use function stripos;
use function strlen;
use function strrpos;
use function substr;
use function trim;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Output;


/**
 * Real-time, filterable log viewer (the Monitor-mode dashboard).
 *
 * Records arrive as newline-delimited JSON (from the worker→master log pipe), are kept in a bounded
 * ring buffer, and rendered into a full-screen TUI: a status bar, a windowed/filtered log pane and a
 * keybindings footer. Multiline messages (e.g. exceptions) are **collapsed to a single line** so they
 * never pollute the stream; selecting a record (↑/↓) and pressing Enter opens a full detail view with
 * every line. Filtering (level threshold, channel toggles, incremental text search) is driven live.
 */
class Logs
{
   use Formattable;


   // ANSI escape sequence matcher (strip server template styling to plain text).
   private const string ANSI = '/\x1b\[[0-9;?]*[ -\/]*[@-~]/';


   // * Config
   public int $max;
   // @ Geometry
   /** Frame anchor row (1-based); 1 = top of the screen */
   public int $row = 1;
   /** Frame height in rows; 0 = full terminal height */
   public int $rows = 0;

   // * Data
   public Input $Input;
   public Output $Output;
   /** @var array<int,Record> */
   public array $Records = [];
   // @ Filters
   public Levels $level = Levels::Debug;        // severity threshold (Debug = show all)
   /** @var array<string,bool> */
   public array $channels = [];                 // channel => visible
   public string $search = '';
   // @ View state
   public bool $paused = false;
   public bool $searching = false;
   public int $cursor = 0;                       // selected record index in the visible list (while paused)
   public private(set) null|Record $Detail = null; // expanded record (detail view); null in list mode

   // * Metadata
   private string $partial = '';                 // carried-over partial JSON line
   /** @var array<int,Record> */
   private array $Frozen = [];                   // buffer snapshot rendered while paused
   private int $top = 0;                          // window top index (paused navigation)
   private int $scroll = 0;                       // detail-view scroll offset


   /**
    * @param Input $Input Terminal input (configured for non-blocking raw reads by the loop).
    * @param Output $Output Terminal output sink.
    * @param int $max Ring-buffer capacity (records).
    */
   public function __construct (Input $Input, Output $Output, int $max = 5000)
   {
      // * Config
      $this->max = $max;

      // * Data
      $this->Input = $Input;
      $this->Output = $Output;
   }

   /**
    * Feed raw pipe bytes (newline-delimited JSON records) into the buffer.
    *
    * @param string $chunk Bytes drained from the log pipe.
    */
   public function feed (string $chunk): void
   {
      $this->partial .= $chunk;

      // @ Split into complete lines; keep the trailing incomplete fragment
      $lines = explode("\n", $this->partial);
      $this->partial = (string) array_pop($lines);

      foreach ($lines as $line) {
         $line = trim($line);
         if ($line === '') {
            continue;
         }

         $data = json_decode($line, true);
         if (is_array($data) === true) {
            /** @var array<string,mixed> $data */
            $this->append(Record::import($data));
         }
      }
   }

   /**
    * Handle a keystroke; mutates filter / selection / detail state.
    *
    * @param string $key Raw bytes read from the terminal.
    * @return bool False to quit the viewer, true to keep running.
    */
   public function control (string $key): bool
   {
      // ? Search-input sub-mode
      if ($this->searching === true) {
         return $this->find($key);
      }
      // ? Detail (expanded record) sub-mode
      if ($this->Detail !== null) {
         return $this->inspect($key);
      }

      switch ($key) {
         // @ Quit
         case 'q':
            return false;
         case Keystrokes::ESCAPE->value:
            // Esc resumes the live tail when paused, otherwise quits
            if ($this->paused === true) {
               $this->resume();
               break;
            }
            return false;
         // @ Pause / resume tailing
         case ' ':
            $this->paused === true ? $this->resume() : $this->freeze();
            break;
         // @ Cycle severity threshold
         case 'l':
            $this->cycle();
            break;
         // @ Enter incremental search
         case '/':
            $this->searching = true;
            $this->search = '';
            break;
         // @ Select a record (freezes a snapshot to navigate)
         case Keystrokes::UP->value:
            $this->move(-1);
            break;
         case Keystrokes::DOWN->value:
            $this->move(1);
            break;
         case Keystrokes::PAGEUP->value:
            $this->move(- $this->measure());
            break;
         case Keystrokes::PAGEDOWN->value:
            $this->move($this->measure());
            break;
         case Keystrokes::HOME->value:
         case "\e[1~":
            $this->freeze();
            $this->cursor = 0;
            break;
         case Keystrokes::END->value:
         case "\e[4~":
            $this->resume();
            break;
         // @ Expand the selected record (all lines)
         case Keystrokes::ENTER->value:
         case "\r": // raw mode (-icrnl) delivers Enter as CR
            $this->expand();
            break;
         default:
            // @ Digit toggles the Nth channel
            if (strlen($key) === 1 && $key >= '1' && $key <= '9') {
               $this->toggle((int) $key - 1);
            }
      }

      return true;
   }

   /**
    * Render one full frame (the detail view, or the status bar + log pane + footer).
    */
   public function render (): void
   {
      // ? Expanded record detail view
      if ($this->Detail !== null) {
         $this->Output->write($this->present($this->Detail));

         return;
      }

      $width = Terminal::$width;
      $pane = $this->measure();

      // @ Apply filters
      $Visible = $this->filter();
      $total = count($Visible);

      // @ Window: tail when live; keep the cursor visible while paused
      if ($this->paused === true) {
         $this->cursor = max(0, min($this->cursor, max(0, $total - 1)));
         if ($this->cursor < $this->top) {
            $this->top = $this->cursor;
         }
         else if ($this->cursor >= $this->top + $pane) {
            $this->top = $this->cursor - $pane + 1;
         }
         $this->top = max(0, min($this->top, max(0, $total - $pane)));
      }
      else {
         $this->top = max(0, $total - $pane);
      }

      $Window = array_slice($Visible, $this->top, $pane);

      // @ Build the frame (cursor to the anchor, per-line clear-to-EOL avoids flicker)
      // Every line is fitted to the width: a wrapped line would add a row,
      // scroll the frame and desync all the anchored redraws
      $frame = $this->anchor();
      $frame .= $this->fit($this->summarize($total)) . "\e[K\n";

      $rows = 0;
      foreach ($Window as $Record) {
         $selected = $this->paused === true && ($this->top + $rows) === $this->cursor;
         $frame .= $this->fit($this->format($Record, $width, $selected)) . "\e[K\n";
         $rows++;
      }
      for (; $rows < $pane; $rows++) {
         $frame .= "\e[K\n";
      }

      $frame .= $this->fit($this->assist()) . "\e[K";

      $this->Output->write($frame);
   }

   // # Filtering
   /**
    * @return array<int,Record> Records passing the active filters (oldest → newest).
    */
   private function filter (): array
   {
      $Visible = [];

      foreach ($this->fetch() as $Record) {
         if ($this->check($Record) === true) {
            $Visible[] = $Record;
         }
      }

      return $Visible;
   }

   /**
    * @return array<int,Record> The records to render — the frozen snapshot while paused, else live.
    */
   private function fetch (): array
   {
      return $this->paused === true ? $this->Frozen : $this->Records;
   }

   private function check (Record $Record): bool
   {
      // ? Severity threshold (lower value = more severe)
      if ($Record->Level->value > $this->level->value) {
         return false;
      }
      // ? Channel toggled off
      if (($this->channels[$Record->channel] ?? true) === false) {
         return false;
      }
      // ? Search term
      if ($this->search !== '' && stripos($Record->message, $this->search) === false) {
         return false;
      }

      return true;
   }

   // # Selection
   private function freeze (): void
   {
      if ($this->paused === false) {
         $this->paused = true;
         $this->Frozen = $this->Records;
         $this->cursor = max(0, count($this->filter()) - 1);   // start at the newest
      }
   }

   private function resume (): void
   {
      $this->paused = false;
      $this->Frozen = [];
      $this->cursor = 0;
      $this->top = 0;
   }

   private function move (int $delta): void
   {
      $this->freeze();   // navigating implies a frozen snapshot

      $last = max(0, count($this->filter()) - 1);
      $this->cursor = max(0, min($this->cursor + $delta, $last));
   }

   private function expand (): void
   {
      if ($this->paused === false) {
         return;
      }

      $Visible = $this->filter();
      if (isSet($Visible[$this->cursor]) === true) {
         $this->Detail = $Visible[$this->cursor];
         $this->scroll = 0;
      }
   }

   private function inspect (string $key): bool
   {
      switch ($key) {
         case 'q':
         case Keystrokes::ESCAPE->value:
         case Keystrokes::ENTER->value:
         case "\r": // raw mode (-icrnl) delivers Enter as CR
            $this->Detail = null;
            break;
         case Keystrokes::UP->value:
            $this->scroll = max(0, $this->scroll - 1);
            break;
         case Keystrokes::DOWN->value:
            $this->scroll++;
            break;
         case Keystrokes::PAGEUP->value:
            $this->scroll = max(0, $this->scroll - $this->measure());
            break;
         case Keystrokes::PAGEDOWN->value:
            $this->scroll += $this->measure();
            break;
      }

      return true;
   }

   // # Helpers
   private function append (Record $Record): void
   {
      $this->Records[] = $Record;
      $this->channels[$Record->channel] ??= true;

      $count = count($this->Records);
      if ($count > $this->max) {
         $this->Records = array_slice($this->Records, $count - $this->max);
      }
   }

   private function measure (): int
   {
      $height = $this->rows > 0 ? $this->rows : Terminal::$height;

      return max(1, $height - 2);
   }

   /**
    * Cursor move to the frame anchor (the pane origin).
    */
   private function anchor (): string
   {
      return $this->row > 1 ? "\e[{$this->row};1H" : "\e[H";
   }

   private function cycle (): void
   {
      // Debug → Info → … → Emergency → Debug (each step = stricter threshold)
      $next = $this->level->value - 1;
      $this->level = Levels::tryFrom($next) ?? Levels::Debug;
   }

   private function toggle (int $index): void
   {
      $channels = array_keys($this->channels);
      sort($channels);

      if (isSet($channels[$index]) === true) {
         $channel = $channels[$index];
         $this->channels[$channel] = ! $this->channels[$channel];
      }
   }

   private function find (string $key): bool
   {
      switch ($key) {
         case Keystrokes::ENTER->value:
         case Keystrokes::ESCAPE->value:
         case "\r": // raw mode (-icrnl) delivers Enter as CR
            $this->searching = false;
            break;
         case Keystrokes::BACKSPACE->value:
            $this->search = substr($this->search, 0, -1);
            break;
         default:
            if (strlen($key) === 1 && ord($key) >= 32 && ord($key) < 127) {
               $this->search .= $key;
            }
      }

      return true;
   }

   /**
    * Collapse a (possibly multiline, templated) message to one plain line + a count of hidden lines.
    *
    * @return array{0: string, 1: int} [first line, number of additional non-empty lines]
    */
   private function flatten (string $message): array
   {
      $plain = (string) preg_replace(self::ANSI, '', TemplateEscaped::render($message));

      $lines = preg_split('/\r\n|\r|\n/', $plain);
      if ($lines === false) {
         $lines = [$plain];
      }
      $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

      $first = trim((string) ($lines[0] ?? ''));
      $extra = max(0, count($lines) - 1);

      // :
      return [$first, $extra];
   }

   // # Rendering
   /**
    * Fit a styled line into the terminal width (escape-aware truncation).
    *
    * A line wider than the terminal wraps into an extra row, scrolls the frame and desyncs
    * every `\e[H`-anchored redraw — narrow terminals (embedded runtimes, split panes) would
    * corrupt the whole TUI. Escape sequences occupy no columns and pass through whole.
    */
   private function fit (string $line): string
   {
      // ?
      $width = Terminal::$width;
      $plain = (string) preg_replace(self::ANSI, '', $line);
      if (mb_strlen($plain) <= $width) {
         return $line;
      }

      // !
      $tokens = preg_split(
         '/(\x1b\[[0-9;?]*[ -\/]*[@-~])/',
         $line,
         -1,
         PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
      );
      $output = '';
      $visible = 0;

      // @@
      foreach ($tokens === false ? [$line] : $tokens as $token) {
         // ? Escape sequences pass through whole — they occupy no columns
         if ($token[0] === "\e") {
            $output .= $token;
            continue;
         }

         $remaining = $width - $visible;
         if ($remaining <= 0) {
            continue;
         }

         $chunk = mb_substr($token, 0, $remaining);
         $output .= $chunk;
         $visible += mb_strlen($chunk);
      }

      // :
      return $output;
   }

   private function format (Record $Record, int $width, bool $selected = false): string
   {
      $color = $this->color($Record->Level);

      $time = date('H:i:s', (int) $Record->timestamp);
      $severity = str_pad($Record->Level->render(), 9);
      $channel = $this->shorten($Record->channel);

      // @ One plain line + count of collapsed lines
      [$message, $extra] = $this->flatten($Record->message);

      // @ Budget the message to the remaining width
      $hidden = $extra > 0 ? " ⏎ +$extra lines" : '';
      $prefix = "› [$time] $severity $channel: ";
      $budget = max(0, $width - strlen($prefix) - strlen($hidden));
      if (strlen($message) > $budget) {
         $message = substr($message, 0, $budget);
      }

      $gutter = $selected
         ? self::wrap(self::_CYAN_BOLD) . '›' . self::_RESET_FORMAT
         : ' ';
      $more = $extra > 0
         ? self::wrap(self::_BLACK_BRIGHT_FOREGROUND) . $hidden . self::_RESET_FORMAT
         : '';

      return $gutter . ' '
         . self::wrap(self::_BLACK_BRIGHT_FOREGROUND) . "[$time] " . self::_RESET_FORMAT
         . self::wrap($color) . $severity . self::_RESET_FORMAT . ' '
         . self::wrap(self::_BLUE_BRIGHT_FOREGROUND) . $channel . self::_RESET_FORMAT . ': '
         . self::wrap($color) . $message . self::_RESET_FORMAT
         . $more;
   }

   private function present (Record $Record): string
   {
      $width = Terminal::$width;
      $pane = $this->measure();
      $color = $this->color($Record->Level);

      // @ Header
      $lines = [];
      $lines[] = self::wrap($color) . $Record->Level->render() . self::_RESET_FORMAT
         . '  ' . self::wrap(self::_BLUE_BRIGHT_FOREGROUND) . $Record->channel . self::_RESET_FORMAT
         . '  ' . self::wrap(self::_BLACK_BRIGHT_FOREGROUND) . date('Y-m-d H:i:s', (int) $Record->timestamp) . self::_RESET_FORMAT;
      $lines[] = '';

      // @ Full message (every line; fitted to the width at frame build)
      $plain = (string) preg_replace(self::ANSI, '', TemplateEscaped::render($Record->message));
      $body = preg_split('/\r\n|\r|\n/', $plain);
      foreach ($body === false ? [$plain] : $body as $row) {
         $lines[] = $row;
      }

      // @ Context + extra
      if ($Record->context !== []) {
         $lines[] = '';
         $lines[] = self::wrap(self::_BLACK_BRIGHT_FOREGROUND) . 'context: ' . self::_RESET_FORMAT . $this->encode($Record->context);
      }
      if ($Record->extra !== []) {
         $lines[] = self::wrap(self::_BLACK_BRIGHT_FOREGROUND) . 'extra:   ' . self::_RESET_FORMAT . $this->encode($Record->extra);
      }

      // @ Window
      $total = count($lines);
      $this->scroll = max(0, min($this->scroll, max(0, $total - $pane)));
      $Window = array_slice($lines, $this->scroll, $pane);

      $frame = $this->anchor();
      $frame .= $this->fit(self::wrap(self::_BLACK_BRIGHT_BACKGROUND) . ' Log detail  ▏ ' . $total . ' lines' . self::_RESET_FORMAT) . "\e[K\n";

      $rows = 0;
      foreach ($Window as $row) {
         $frame .= $this->fit($row) . "\e[K\n";
         $rows++;
      }
      for (; $rows < $pane; $rows++) {
         $frame .= "\e[K\n";
      }

      $frame .= $this->fit(self::wrap(self::_BLACK_BRIGHT_FOREGROUND) . ' [↑↓ PgUp/Dn] scroll   [Esc/Enter/q] back' . self::_RESET_FORMAT) . "\e[K";

      return $frame;
   }

   private function summarize (int $total): string
   {
      $bits = [];
      $bits[] = self::wrap(self::_BOLD_STYLE) . 'BOOTGLY logs ' . self::_RESET_FORMAT;
      $bits[] = 'level≥' . self::wrap($this->color($this->level)) . $this->level->render() . self::_RESET_FORMAT;

      if ($this->searching === true) {
         $bits[] = self::wrap(self::_YELLOW_BOLD) . 'search: ' . $this->search . '▏' . self::_RESET_FORMAT;
      }
      else if ($this->search !== '') {
         $bits[] = 'search:"' . $this->search . '"';
      }

      if ($this->paused === true) {
         $bits[] = self::wrap(self::_YELLOW_BOLD) . 'PAUSED' . self::_RESET_FORMAT;
         $bits[] = self::wrap(self::_CYAN_FOREGROUND) . ($this->cursor + 1) . '/' . $total . self::_RESET_FORMAT;
      }
      else {
         $bits[] = $total . ' lines';
      }

      // @ Channel legend (index = toggle key)
      $channels = array_keys($this->channels);
      sort($channels);
      $legend = [];
      foreach ($channels as $index => $channel) {
         if ($index > 8) {
            break;
         }
         $mark = $this->channels[$channel] ? self::wrap(self::_GREEN_BOLD) : self::wrap(self::_BLACK_BRIGHT_FOREGROUND);
         $legend[] = $mark . ($index + 1) . ':' . $this->shorten($channel) . self::_RESET_FORMAT;
      }

      $line = ' ' . implode('  ▏ ', $bits);
      if ($legend !== []) {
         $line .= '   ' . implode(' ', $legend);
      }

      return self::wrap(self::_BLACK_BRIGHT_BACKGROUND) . $line . self::_RESET_FORMAT;
   }

   private function assist (): string
   {
      $keys = $this->searching === true
         ? '[type to search]  [Enter/Esc] done  [⌫] delete'
         : '[l] level  [/] search  [space] pause  [↑↓ PgUp/Dn] select  [Home/End] top/live  [Enter] expand  [1-9] channel  [q] quit';

      return self::wrap(self::_BLACK_BRIGHT_FOREGROUND) . ' ' . $keys . self::_RESET_FORMAT;
   }

   private function shorten (string $channel): string
   {
      // @ Last path-ish segment of a channel name (e.g. FQCN → class)
      $position = strrpos($channel, '\\');
      if ($position !== false) {
         return substr($channel, $position + 1);
      }

      return $channel;
   }

   /**
    * @param array<string,mixed> $data
    */
   private function encode (array $data): string
   {
      $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

      return $json === false ? '{}' : $json;
   }

   // # Translating
   private function color (Levels $Level): string
   {
      return match ($Level) {
         Levels::Emergency => self::_RED_BOLD,
         Levels::Alert     => self::_MAGENTA_BOLD,
         Levels::Critical  => self::_MAGENTA_FOREGROUND,
         Levels::Error     => self::_RED_BRIGHT_FOREGROUND,
         Levels::Warning   => self::_YELLOW_BOLD,
         Levels::Notice    => self::_CYAN_FOREGROUND,
         Levels::Info      => self::_GREEN_BOLD,
         Levels::Debug     => self::_WHITE_FOREGROUND,
      };
   }
}
