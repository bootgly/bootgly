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
use function count;
use function feof;
use function implode;
use function mb_strlen;
use function mb_substr;
use function rewind;
use function stream_get_contents;
use function substr_count;
use function usleep;

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Lines;
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
   /** The multiline buffer — one Line per row */
   public private(set) Lines $Lines;
   /** @var array<int,string> The row values, top to bottom */
   public array $lines {
      get => $this->Lines->lines;
   }

   // * Metadata
   /** Cursor line index */
   public int $row {
      get => $this->Lines->row;
   }
   /** Cursor column, in codepoints */
   public int $column {
      get => $this->Lines->column;
   }
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
      $this->Lines = new Lines;

      // * Metadata
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

      // ! Horizontal viewport — rows never exceed the terminal (wrapped physical
      //   rows would break the block-repaint height bookkeeping)
      $columns = (isSet(Terminal::$width) === true ? Terminal::$width : 80) - 4;

      for ($index = $this->Window->first; $index <= $this->Window->last; $index++) {
         $Line = $this->Lines->Lines[$index];
         $line = $Line->value;

         // ? The active row renders through its own buffer — visible slice,
         //   inverse-video cursor cell and truncation ellipsis
         if ($index === $this->row) {
            $Line->width = $columns;

            $frame .= "@#Black:│@; {$Line->render()}\n";
         }
         else {
            // ? Inactive lines crop with an ellipsis
            if (mb_strlen($line) > $columns) {
               $line = mb_substr($line, 0, $columns - 1) . '…';
            }

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

         $this->Lines->load(implode("\n", $lines));
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
            $this->Output->Text->clear(lines: $height);
         }

         // ! php://memory resolves the markup before counting lines
         $frame = $this->render(self::RETURN_OUTPUT) ?? '';

         $Memory = new Output('php://memory');
         $Memory->render($frame);
         rewind($Memory->stream);
         $painted = (string) stream_get_contents($Memory->stream);

         $height = substr_count($painted, "\n");

         $this->Output->write($painted);

         // @@ Wait for a key (listen() assembles full sequences)
         while (true) {
            $key = $this->Input->listen();

            // ? EOF: interactive input will never arrive — submit
            if ($key === false || feof($this->Input->stream) === true) {
               break 2;
            }
            // ? Key available
            if ($key !== '') {
               break;
            }

            usleep(50000);
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
      $this->Lines->control($key);
   }
}
