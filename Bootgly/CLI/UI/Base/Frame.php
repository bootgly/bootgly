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


use const BOOTGLY_TTY;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;
use function array_pop;
use function array_slice;
use function array_splice;
use function count;
use function explode;
use function ftruncate;
use function implode;
use function max;
use function mb_str_split;
use function preg_match;
use function preg_replace;
use function preg_split;
use function rewind;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function stream_get_contents;
use function strrpos;
use function substr;

use Bootgly\ABI\Data\__String;
use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Atoms\Boxing;
use Bootgly\CLI\UI\Base\Frame\Borders;


/**
 * Frame — a rectangular screen region with its own isolated/individual Output
 * (like an HTML iframe): anything written into `$Frame->Output` is buffered,
 * wrapped or clipped to the interior and diff-blitted in place, independent of
 * sibling frames. Only text and SGR styling enter the buffer — cursor, erase
 * and OSC escapes are stripped, so content can never leak outside the frame.
 */
class Frame extends Component implements Boxing
{
   use Formattable;


   /**
    * Strips every escape that is not a plain SGR (`\e[` + digits/semicolons + `m`,
    * the exact form the chunk/crop tokenizer recognizes): any other CSI, a CSI
    * interrupted by a line break, OSC (BEL/ST-terminated or interrupted) and bare
    * ESC pairs. A trailing unterminated `ESC`/`ESC[` survives to reassemble with
    * the next drained bytes.
    */
   private const string UNSUPPORTED_ESCAPES_REGEX =
      '/\x1B\[(?![0-9;]*m)[\x20-\x3F]*[\x40-\x7E]|\x1B\[[\x20-\x3F]*(?=\n)|\x1B\][^\x07\x1B\n]*(?:\x07|\x1B\\\\)?|\x1B(?=\n)|\x1B[^\x5B\x5D\n]/';


   /** Host Output — the terminal surface the frame blits onto */
   private Output $Host;

   // * Config
   // # Geometry (outer rectangle, 1-based screen coordinates; the border consumes 2 columns / 2 rows)
   /** Top screen row (1-based) */
   public int $row;
   /** Left screen column (1-based) */
   public int $column;
   /** Outer width, in columns */
   public int $width;
   /** Outer height, in rows */
   public int $height;
   // # Style
   /** Border glyph set — `Borders::None` removes the border */
   public Borders $Borders;
   /** Border and title color (Template markup) */
   public string $color;
   // # View
   /** Follow the newest lines (tail) instead of the first lines (head) */
   public bool $follow;
   /** Wrap long lines into extra rows instead of clipping the overflow */
   public bool $wrap;
   /** Max buffered logical lines — older lines are dropped */
   public int $capacity;

   // * Data
   /** Frame title — rendered into the top border row */
   public null|string $title = null {
      get {
         return $this->title;
      }
      set {
         // ? Titles are single-line: control characters (\n, \r, \t, ... —
         //   Template `@.;` even injects PHP_EOL) never break the border row
         $this->title = ($value
            ? (string) preg_replace(
               ['/[\x00-\x1A\x1C-\x1F\x7F]/', self::UNSUPPORTED_ESCAPES_REGEX],
               '',
               TemplateEscaped::render(" $value ")
            )
            : ''
         );
      }
   }
   /** The isolated/individual Output — anything written here renders inside the frame */
   public private(set) Output $Output;
   /** @var array<int,string> Buffered logical lines (painted bytes, SGR only) */
   public private(set) array $buffer;

   // * Metadata
   /** Inner width, in columns */
   public int $columns {
      get => max(0, $this->width - ($this->Borders === Borders::None ? 0 : 2));
   }
   /** Inner height, in lines */
   public int $lines {
      get => max(0, $this->height - ($this->Borders === Borders::None ? 0 : 2));
   }
   /** @var array<int,string> Last blitted rectangle rows (row offset ⇒ painted bytes) */
   private array $front;
   /** @var array<int,int> Geometry snapshot of the last blit (front invalidation) */
   private array $rect;
   /** Unterminated drained tail carried over to the next drain */
   private string $partial;


   public function __construct (Output $Output)
   {
      $this->Host = $Output;

      // * Config
      // # Geometry
      $this->row = 1;
      $this->column = 1;
      $this->width = 20;
      $this->height = 5;
      // # Style
      $this->Borders = Borders::Sharp;
      $this->color = '@#Black:';
      // # View
      $this->follow = true;
      $this->wrap = false;
      $this->capacity = 1000;

      // * Data
      $this->title = null;
      $this->Output = new Output('php://memory');
      $this->buffer = [];

      // * Metadata
      $this->front = [];
      $this->rect = [];
      $this->partial = '';
   }


   /**
    * Clears the frame content — the buffer, the carried tail and the isolated
    * Output stream are emptied (the stream resource is preserved, so hosted
    * components keep writing into the same Output).
    *
    * @return void
    */
   public function clear (): void
   {
      // * Data
      $this->buffer = [];

      // * Metadata
      $this->partial = '';

      ftruncate($this->Output->stream, 0);
      rewind($this->Output->stream);
   }

   /**
    * Invalidates the blitted front buffer — the next render repaints the full
    * rectangle (screen cleared externally, overlapped by another frame, ...).
    *
    * @return void
    */
   public function invalidate (): void
   {
      // * Metadata
      $this->front = [];
      $this->rect = [];
   }

   /**
    * Renders the frame rectangle — border, title and the visible interior view
    * of the buffered content. Interactive terminals diff-blit: only rows that
    * changed since the last render are repainted, each at its exact rectangle
    * position (erase escapes are never emitted — sibling frames stay intact).
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ! New content drained from the isolated Output
      $this->drain();

      $rows = $this->compose();

      // ?: Rectangle as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         // :
         return implode("\n", $rows) . "\n";
      }

      // ? Non-interactive output writes the rectangle rows plainly
      if (BOOTGLY_TTY === false) {
         foreach ($rows as $row) {
            $this->Host->write("{$row}\n");
         }

         return null;
      }

      // ? Geometry changes drop the front buffer (full repaint)
      $rect = [$this->row, $this->column, $this->width, $this->height];
      if ($rect !== $this->rect) {
         $this->front = [];
         $this->rect = $rect;
      }

      // @@ Diff blit — only changed rows repaint, anchored at the rectangle
      foreach ($rows as $offset => $row) {
         // ? Unchanged since the last blit
         if (($this->front[$offset] ?? null) === $row) {
            continue;
         }

         $this->Host->Cursor->moveTo(line: $this->row + $offset, column: $this->column);
         $this->Host->write($row);

         $this->front[$offset] = $row;
      }

      return null;
   }

   /**
    * Drains the isolated Output stream into the line buffer — bytes are
    * sanitized (only SGR escapes survive), split into logical lines and capped
    * to the capacity. A carriage return overwrites the pending line (the
    * latest state wins) and an unterminated tail carries over. Coordinators
    * (like Tabs) drain inactive frames to keep their streams and buffers
    * bounded without painting.
    *
    * @return void
    */
   public function drain (): void
   {
      // ! New bytes pulled out of the isolated stream
      rewind($this->Output->stream);
      $bytes = (string) stream_get_contents($this->Output->stream);
      ftruncate($this->Output->stream, 0);
      rewind($this->Output->stream);

      // ?
      if ($bytes === '') {
         return;
      }

      // ! Carried tail + normalized line breaks (after the carry — a CRLF split
      //   across two drains still normalizes)
      $bytes = "{$this->partial}{$bytes}";
      $bytes = str_replace("\r\n", "\n", $bytes);

      // @ Sanitize — only SGR escapes may enter the buffer
      $bytes = (string) preg_replace(self::UNSUPPORTED_ESCAPES_REGEX, '', $bytes);

      // @@ Split into logical lines — the unterminated tail carries over
      $lines = explode("\n", $bytes);
      $this->partial = (string) array_pop($lines);

      // ? Carriage returns overwrite the pending tail (CR-only writers stay
      //   bounded) — a trailing one stays pending: it may be half of a split CRLF
      $pending = str_ends_with($this->partial, "\r");
      $tail = $pending ? substr($this->partial, 0, -1) : $this->partial;

      $position = strrpos($tail, "\r");
      if ($position !== false) {
         $tail = substr($tail, $position + 1);
      }

      $this->partial = $pending ? "{$tail}\r" : $tail;

      foreach ($lines as $line) {
         // ? A carriage return overwrites the pending line — the latest state wins
         $position = strrpos($line, "\r");
         if ($position !== false) {
            $line = substr($line, $position + 1);
         }

         $this->buffer[] = $line;
      }

      // ? Drop the oldest lines over capacity
      $overflow = count($this->buffer) - $this->capacity;
      if ($overflow > 0) {
         array_splice($this->buffer, 0, $overflow);
      }
   }

   /**
    * Composes the full rectangle rows — the visible interior view (tail or
    * head, wrapped or clipped) padded to the exact inner width, framed by the
    * border glyphs with the title embedded in the top border row.
    *
    * @return array<int,string> The rectangle rows (`height` rows of `width` visible columns).
    */
   private function compose (): array
   {
      // !
      $columns = $this->columns;
      $lines = $this->lines;

      // # Visible interior view — [painted, visible columns] pairs
      $pairs = [];
      if ($lines > 0 && $columns > 0) {
         if ($this->follow === true) {
            // @@ Walk the buffer backwards — only the visible tail is chunked
            for ($index = count($this->buffer) - 1; $index >= 0; $index--) {
               $visuals = ($this->wrap === true)
                  ? $this->chunk($this->buffer[$index], $columns)
                  : [$this->crop($this->buffer[$index], $columns)];

               $pairs = [...$visuals, ...$pairs];

               // ? Interior filled
               if (count($pairs) >= $lines) {
                  break;
               }
            }

            $pairs = array_slice($pairs, -$lines);
         }
         else {
            // @@ Walk the buffer forwards — only the visible head is chunked
            foreach ($this->buffer as $line) {
               $visuals = ($this->wrap === true)
                  ? $this->chunk($line, $columns)
                  : [$this->crop($line, $columns)];

               foreach ($visuals as $visual) {
                  $pairs[] = $visual;
               }

               // ? Interior filled
               if (count($pairs) >= $lines) {
                  break;
               }
            }

            $pairs = array_slice($pairs, 0, $lines);
         }
      }

      // # Interior rows padded to the exact inner width (erase escapes are never used)
      $rows = [];
      foreach ($pairs as [$painted, $visible]) {
         $rows[] = $painted . str_repeat(' ', max(0, $columns - $visible));
      }
      // ? Blank rows complete the interior
      while (count($rows) < $lines) {
         $rows[] = str_repeat(' ', $columns);
      }

      // ?: Borderless frames expose the interior rows directly
      if ($this->Borders === Borders::None) {
         // :
         return $rows;
      }

      // ! Border painting — degenerate widths paint at most `width` visible columns
      $map = $this->Borders->map();
      $paint = TemplateEscaped::render($this->color);
      $reset = self::_RESET_FORMAT;
      $width = $this->width;

      $rect = [];

      // # Top border row — embeds the title
      if ($this->height >= 1) {
         if ($width >= 2) {
            [$title, $entitled] = $this->crop($this->title ?? '', $columns);
            $fill = str_repeat($map['top'], max(0, $columns - $entitled));

            $rect[] = "{$paint}{$map['top-left']}{$reset}{$title}{$paint}{$fill}{$map['top-right']}{$reset}";
         }
         else {
            $rect[] = ($width === 1) ? "{$paint}{$map['top-left']}{$reset}" : '';
         }
      }

      // # Interior rows framed by the side borders
      foreach ($rows as $row) {
         if ($width >= 2) {
            $rect[] = "{$paint}{$map['left']}{$reset}{$row}{$paint}{$map['right']}{$reset}";
         }
         else {
            $rect[] = ($width === 1) ? "{$paint}{$map['left']}{$reset}" : '';
         }
      }

      // # Bottom border row
      if ($this->height >= 2) {
         if ($width >= 2) {
            $fill = str_repeat($map['bottom'], $columns);

            $rect[] = "{$paint}{$map['bottom-left']}{$fill}{$map['bottom-right']}{$reset}";
         }
         else {
            $rect[] = ($width === 1) ? "{$paint}{$map['bottom-left']}{$reset}" : '';
         }
      }

      // :
      return $rect;
   }

   /**
    * Chunks a painted line into visual rows at the given width — escape
    * sequences are zero-width and the active SGR closes at each row end and
    * reopens on the next row (styles never bleed into siblings).
    *
    * @param string $painted The painted line (resolved escapes).
    * @param int $columns The row width, in visible columns.
    *
    * @return array<int,array{0:string,1:int}> Visual rows as [painted, visible columns].
    */
   private function chunk (string $painted, int $columns): array
   {
      // ! Tokens — escape sequences split apart from the text
      $tokens = (array) preg_split(
         '/(' . substr(__String::ANSI_ESCAPE_SEQUENCE_REGEX, 1, -1) . ')/',
         $painted,
         flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
      );

      $rows = [];
      $current = '';
      $count = 0;
      $SGR = '';

      // @@ Chunk the visible characters; escapes pass through with zero width
      foreach ($tokens as $token) {
         // ? Escape sequence
         if (preg_match(__String::ANSI_ESCAPE_SEQUENCE_REGEX, (string) $token) === 1) {
            $current .= $token;

            // # The active SGRs accumulate and reopen on the next visual row
            if (str_ends_with((string) $token, 'm') === true) {
               $SGR = ($token === self::_RESET_FORMAT) ? '' : "{$SGR}{$token}";
            }

            continue;
         }

         foreach (mb_str_split((string) $token) as $character) {
            $current .= $character;
            $count++;

            // ? Row full — close the SGRs and carry them over
            if ($count === $columns) {
               if ($SGR !== '') {
                  $current .= self::_RESET_FORMAT;
               }

               $rows[] = [$current, $count];
               $current = $SGR;
               $count = 0;
            }
         }
      }

      // ? The last partial row (an empty line still occupies one row)
      if ($count > 0 || $rows === []) {
         if ($SGR !== '') {
            $current .= self::_RESET_FORMAT;
         }

         $rows[] = [$current, $count];
      }

      // :
      return $rows;
   }

   /**
    * Crops a painted line at the given width — escape sequences are zero-width,
    * the overflow is discarded and an open SGR closes at the cut (styles never
    * bleed into the padding or siblings).
    *
    * @param string $painted The painted line (resolved escapes).
    * @param int $columns The width, in visible columns.
    *
    * @return array{0:string,1:int} The cropped line as [painted, visible columns].
    */
   private function crop (string $painted, int $columns): array
   {
      // ? Nothing fits
      if ($columns < 1) {
         return ['', 0];
      }

      // ! Tokens — escape sequences split apart from the text
      $tokens = (array) preg_split(
         '/(' . substr(__String::ANSI_ESCAPE_SEQUENCE_REGEX, 1, -1) . ')/',
         $painted,
         flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
      );

      $cropped = '';
      $count = 0;
      $SGR = '';

      // @@ Take the visible characters up to the width; escapes pass through
      foreach ($tokens as $token) {
         // ? Escape sequence
         if (preg_match(__String::ANSI_ESCAPE_SEQUENCE_REGEX, (string) $token) === 1) {
            $cropped .= $token;

            if (str_ends_with((string) $token, 'm') === true) {
               $SGR = ($token === self::_RESET_FORMAT) ? '' : "{$SGR}{$token}";
            }

            continue;
         }

         foreach (mb_str_split((string) $token) as $character) {
            $cropped .= $character;
            $count++;

            // ?: Width reached — the overflow is clipped
            if ($count === $columns) {
               if ($SGR !== '') {
                  $cropped .= self::_RESET_FORMAT;
               }

               // :
               return [$cropped, $count];
            }
         }
      }

      // ? An open SGR never bleeds into the padding
      if ($SGR !== '') {
         $cropped .= self::_RESET_FORMAT;
      }

      // :
      return [$cropped, $count];
   }
}
