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


use const BOOTGLY_TTY;
use function array_slice;
use function array_splice;
use function count;
use function feof;
use function implode;
use function mb_strlen;
use function mb_substr;
use function min;
use function ord;
use function rewind;
use function stream_get_contents;
use function strlen;
use function substr_count;
use function usleep;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Output\Window;


/**
 * Multiline text editor — Enter breaks lines, `Ctrl+D` submits. The visible rows
 * window slides with the cursor. Non-interactive input reads stdin lines until EOF
 * (heredoc-style, deterministic).
 */
class Textarea extends Component
{
   use Formattable;


   public Input $Input;
   public Output $Output;

   // * Config
   public string $prompt;
   /** Visible rows */
   public int $rows;

   // * Data
   /** @var array<int,string> */
   public private(set) array $lines;

   // * Metadata
   /** Cursor line index */
   public private(set) int $row;
   /** Cursor column, in codepoints */
   public private(set) int $column;
   public private(set) Window $Window;
   public private(set) string $answer;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->prompt = '';
      $this->rows = 5;

      // * Data
      $this->lines = [''];

      // * Metadata
      $this->row = 0;
      $this->column = 0;
      $this->Window = new Window(size: 5);
      $this->answer = '';
   }


   /**
    * Renders the editor frame (prompt + visible lines + hint).
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   protected function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ! Visible window over the lines
      $this->Window->size = $this->rows;
      $this->Window->total = count($this->lines);
      $this->Window->slide($this->row);

      // ! Raw SGR prefix — Template resets swallow adjacent spaces/underscores
      $prefix = self::wrap(self::_CYAN_BRIGHT_FOREGROUND) . $this->prompt . self::_RESET_FORMAT;
      $frame = "{$prefix} @#Black:(Enter breaks the line, Ctrl+D finishes)@;\n";

      for ($index = $this->Window->first; $index <= $this->Window->last; $index++) {
         $line = $this->lines[$index];

         // ? The cursor cell renders inverse-video on the active line
         if ($index === $this->row) {
            $before = mb_substr($line, 0, $this->column);
            $current = mb_substr($line, $this->column, 1);
            $after = mb_substr($line, $this->column + 1);

            if ($current === '') {
               $current = ' ';
            }

            // ? Raw SGR — Template style markers swallow adjacent spaces (the cell is often a space)
            $cell = self::wrap(self::_INVERSE_STYLE) . $current . self::_RESET_FORMAT;
            $frame .= "@#Black:│@; {$before}{$cell}{$after}\n";
         }
         else {
            $frame .= "@#Black:│@; {$line}\n";
         }
      }

      // ? Hidden lines indicator
      if ($this->Window->last < $this->Window->total - 1) {
         $below = $this->Window->total - 1 - $this->Window->last;
         $frame .= "@#Black:↓ {$below} more@;\n";
      }

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $frame;
      }

      $this->Output->render($frame);

      return null;
   }

   /**
    * Asks for multiline input.
    * Interactive terminals edit in place (`Ctrl+D` submits); non-interactive input
    * reads stdin lines until EOF.
    *
    * @return string The lines joined by `\n`.
    */
   public function ask (): string
   {
      // ? Non-TTY: stdin lines until EOF — deterministic
      if (BOOTGLY_TTY === false) {
         $lines = [];

         while (($line = $this->Input->scan()) !== false) {
            $lines[] = $line;
         }

         $this->lines = $lines === [] ? [''] : $lines;
         $this->answer = implode("\n", $lines);

         // :
         return $this->answer;
      }

      $this->edit();

      $this->answer = implode("\n", $this->lines);

      // :
      return $this->answer;
   }

   /**
    * Runs the interactive edit loop (raw mode) until `Ctrl+D` or EOF.
    */
   private function edit (): void
   {
      // ! Raw input mode
      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();

      $height = 0;

      // @@ Edit until Ctrl+D or EOF
      while (true) {
         // @ Repaint relatively over the previous frame
         if ($height > 0) {
            $this->Output->Cursor->up($height, column: 1);
            $this->Output->Text->clear(down: true);
         }

         // ! php://memory resolves the markup before counting lines
         $frame = $this->render(self::RETURN_OUTPUT) ?? '';

         $Memory = new Output('php://memory');
         $Memory->render($frame);
         rewind($Memory->stream);
         $painted = (string) stream_get_contents($Memory->stream);

         $height = substr_count($painted, "\n");

         $this->Output->write($painted);

         // @@ Wait for input (non-blocking reads keep signals dispatched)
         while (true) {
            $key = $this->Input->read(1);

            if ($key !== false && $key !== '') {
               // ? Escape sequences arrive as up to 3 bytes (e.g. arrows: ESC [ A)
               if ($key === "\e") {
                  $key .= (string) $this->Input->read(2);
               }

               break;
            }

            // ? EOF: interactive input will never arrive — submit
            if (feof($this->Input->stream) === true) {
               break 2;
            }

            usleep(50000);
         }

         if ($key === false) {
            break;
         }

         // ? Ctrl+D submits
         if ($key === Keystrokes::CTRL_D->value) {
            break;
         }

         $this->control($key);
      }

      // @ Restore input settings and the cursor
      $this->Input->configure(blocking: true, canonical: true, echo: true);
      $this->Output->Cursor->show();
      $this->Output->write("\n");
   }

   /**
    * Handles an edit key.
    *
    * @param string $key The key (raw bytes — arrows arrive as escape sequences).
    *
    * @return void
    */
   public function control (string $key): void
   {
      $line = $this->lines[$this->row];
      $length = mb_strlen($line);

      switch ($key) {
         // @ Moving
         case Keystrokes::LEFT->value:
            if ($this->column > 0) {
               $this->column--;
            }
            else if ($this->row > 0) {
               // ? Wrap to the end of the previous line
               $this->row--;
               $this->column = mb_strlen($this->lines[$this->row]);
            }
            break;
         case Keystrokes::RIGHT->value:
            if ($this->column < $length) {
               $this->column++;
            }
            else if ($this->row < count($this->lines) - 1) {
               // ? Wrap to the start of the next line
               $this->row++;
               $this->column = 0;
            }
            break;
         case Keystrokes::UP->value:
            if ($this->row > 0) {
               $this->row--;
               $this->column = min($this->column, mb_strlen($this->lines[$this->row]));
            }
            break;
         case Keystrokes::DOWN->value:
            if ($this->row < count($this->lines) - 1) {
               $this->row++;
               $this->column = min($this->column, mb_strlen($this->lines[$this->row]));
            }
            break;
         case Keystrokes::HOME->value:
         case Keystrokes::CTRL_A->value:
            $this->column = 0;
            break;
         case Keystrokes::END->value:
         case Keystrokes::CTRL_E->value:
            $this->column = $length;
            break;

         // @ Breaking
         case Keystrokes::ENTER->value:
         case "\r":
            // ? Split the line at the cursor
            $before = mb_substr($line, 0, $this->column);
            $after = mb_substr($line, $this->column);

            $this->lines[$this->row] = $before;

            $tail = [];
            for ($index = $this->row + 1; $index < count($this->lines); $index++) {
               $tail[] = $this->lines[$index];
            }

            $this->lines = [...array_slice($this->lines, 0, $this->row + 1), $after, ...$tail];

            $this->row++;
            $this->column = 0;
            break;

         // @ Erasing
         case Keystrokes::BACKSPACE->value:
         case Keystrokes::CTRL_H->value:
            if ($this->column > 0) {
               $this->lines[$this->row] = mb_substr($line, 0, $this->column - 1)
                  . mb_substr($line, $this->column);

               $this->column--;
            }
            else if ($this->row > 0) {
               // ? Merge with the previous line
               $previous = $this->lines[$this->row - 1];

               $this->column = mb_strlen($previous);
               $this->lines[$this->row - 1] = "{$previous}{$line}";

               array_splice($this->lines, $this->row, 1);

               $this->row--;
            }
            break;
         case Keystrokes::DELETE->value:
            if ($this->column < $length) {
               $this->lines[$this->row] = mb_substr($line, 0, $this->column)
                  . mb_substr($line, $this->column + 1);
            }
            else if ($this->row < count($this->lines) - 1) {
               // ? Merge the next line
               $this->lines[$this->row] = "{$line}{$this->lines[$this->row + 1]}";

               array_splice($this->lines, $this->row + 1, 1);
            }
            break;

         default:
            // ? Printable input inserts at the cursor
            if (strlen($key) === 1 && (ord($key) < 32 || ord($key) === 127)) {
               break;
            }
            if ($key[0] === "\e") {
               break;
            }

            $this->lines[$this->row] = mb_substr($line, 0, $this->column)
               . $key
               . mb_substr($line, $this->column);

            $this->column++;
      }
   }
}
