<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Input;


use function array_map;
use function array_splice;
use function count;
use function explode;
use function implode;
use function mb_strlen;
use function mb_substr;
use function ord;
use function strlen;

use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Line;


/**
 * Multiline editor engine — one Line per row plus the active row index, with a
 * virtual cursor that walks the whole buffer. Intra-line keys delegate to the
 * active Line; only the keys that cross a row boundary are handled here
 * (vertical moves, splitting on Enter, merging on Backspace/Delete at the
 * edges). No stream I/O and no windowing: consumers own the read loop, decide
 * which key submits and render the visible rows themselves.
 */
class Lines
{
   // * Data
   /** @var array<int,Line> The row buffers, top to bottom (never empty) */
   public private(set) array $Lines;

   // * Metadata
   /** Active row index */
   public private(set) int $row;
   /** The active row buffer */
   public Line $Line {
      get => $this->Lines[$this->row];
   }
   /** Active row cursor, in codepoints */
   public int $column {
      get => $this->Lines[$this->row]->cursor;
   }
   /** @var array<int,string> The row values, top to bottom */
   public array $lines {
      get => array_map(static fn (Line $Line): string => $Line->value, $this->Lines);
   }
   /** The whole buffer, rows joined by `\n` */
   public string $value {
      get => implode("\n", $this->lines);
   }


   public function __construct ()
   {
      // * Data
      $this->Lines = [new Line];

      // * Metadata
      $this->row = 0;
   }


   /**
    * Inserts printable input at the cursor of the active row (UTF-8 aware).
    *
    * @param string $input The printable input (control bytes are ignored).
    *
    * @return self
    */
   public function feed (string $input): self
   {
      $this->Lines[$this->row]->feed($input);

      // :
      return $this;
   }

   /**
    * Handles an edit key. Row-crossing keys are handled here; everything else
    * delegates to the active row — including printable input, which inserts.
    * Enter always breaks the line: which key submits is the host's policy.
    *
    * @param string $key The key (raw bytes — arrows arrive as escape sequences).
    *
    * @return void
    */
   public function control (string $key): void
   {
      $Line = $this->Lines[$this->row];
      $last = count($this->Lines) - 1;

      switch ($key) {
         // @ Moving across rows
         case Keystrokes::UP->value:
            if ($this->row > 0) {
               $column = $Line->cursor;

               $this->row--;
               $this->Lines[$this->row]->move($column);
            }
            break;
         case Keystrokes::DOWN->value:
            if ($this->row < $last) {
               $column = $Line->cursor;

               $this->row++;
               $this->Lines[$this->row]->move($column);
            }
            break;
         case Keystrokes::LEFT->value:
            // ? At the row start, wrap to the end of the previous row
            if ($Line->cursor === 0 && $this->row > 0) {
               $this->row--;
               $this->Lines[$this->row]->move(mb_strlen($this->Lines[$this->row]->value));

               break;
            }

            $Line->control($key);
            break;
         case Keystrokes::RIGHT->value:
            // ? At the row end, wrap to the start of the next row
            if ($Line->cursor === mb_strlen($Line->value) && $this->row < $last) {
               $this->row++;
               $this->Lines[$this->row]->move(0);

               break;
            }

            $Line->control($key);
            break;

         // @ Breaking
         case Keystrokes::ENTER->value:
         case Keystrokes::CTRL_M->value:
            $this->split();
            break;

         // @ Erasing across rows
         case Keystrokes::BACKSPACE->value:
         case Keystrokes::CTRL_H->value:
            // ? At the row start, merge into the previous row
            if ($Line->cursor === 0 && $this->row > 0) {
               $this->merge($this->row - 1);

               break;
            }

            $Line->control($key);
            break;
         case Keystrokes::DELETE->value:
            // ? At the row end, pull the next row up
            if ($Line->cursor === mb_strlen($Line->value) && $this->row < $last) {
               $this->merge($this->row);

               break;
            }

            $Line->control($key);
            break;

         default:
            // ? Control keys edit the active row; printable input inserts
            if (
               $key[0] === "\e"
               || $key === Keystrokes::BACKSPACE->value
               || (strlen($key) === 1 && ord($key) < 32)
            ) {
               $Line->control($key);

               break;
            }

            $Line->feed($key);
      }
   }

   /**
    * Loads a value into the buffer, splitting it on line breaks — the cursor
    * lands at the end of the last row.
    *
    * @param string $value The value (rows separated by `\n`).
    *
    * @return self
    */
   public function load (string $value): self
   {
      // * Data
      $this->Lines = [];
      foreach (explode("\n", $value) as $line) {
         $Line = new Line;
         $Line->value = $line;
         $Line->move(mb_strlen($line));

         $this->Lines[] = $Line;
      }

      // * Metadata
      $this->row = count($this->Lines) - 1;

      // :
      return $this;
   }

   /**
    * Resets the buffer to a single empty row.
    *
    * @return self
    */
   public function reset (): self
   {
      // * Data
      $this->Lines = [new Line];

      // * Metadata
      $this->row = 0;

      // :
      return $this;
   }

   /**
    * Splits the active row at the cursor — the tail becomes the next row and
    * the cursor lands at its start.
    */
   private function split (): void
   {
      $Line = $this->Lines[$this->row];

      $before = mb_substr($Line->value, 0, $Line->cursor);
      $after = mb_substr($Line->value, $Line->cursor);

      $Line->value = $before;
      $Line->move(mb_strlen($before));

      $Tail = new Line;
      $Tail->value = $after;

      array_splice($this->Lines, $this->row + 1, 0, [$Tail]);

      // * Metadata
      $this->row++;
   }

   /**
    * Merges the row after `$row` into it — the cursor lands at the seam.
    *
    * @param int $row The row that absorbs its successor.
    */
   private function merge (int $row): void
   {
      $Head = $this->Lines[$row];
      $Tail = $this->Lines[$row + 1];

      $seam = mb_strlen($Head->value);

      $Head->value = "{$Head->value}{$Tail->value}";
      $Head->move($seam);

      array_splice($this->Lines, $row + 1, 1);

      // * Metadata
      $this->row = $row;
   }
}
