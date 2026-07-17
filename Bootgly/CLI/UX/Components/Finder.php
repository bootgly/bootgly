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
use const PHP_EOL;
use function count;
use function is_int;
use function max;
use function mb_stripos;
use function mb_strlen;
use function mb_strtolower;
use function ord;
use function rewind;
use function strcasecmp;
use function stream_get_contents;
use function stripos;
use function strlen;
use function substr_count;
use function usleep;
use Closure;

use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Line;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Output\Window;
use Bootgly\CLI\UI\Components\Question;


/**
 * Live search selector — typing filters the options, `↑`/`↓` aim, Enter
 * confirms the aimed match (no match ⇒ no-op), Esc cancels. Options come
 * from a static array or from a dynamic source Closure called with the
 * query on every edit.
 */
class Finder
{
   public Input $Input;
   public Output $Output;

   // * Config
   /** Header / input prefix line */
   public string $prompt;
   /** Dim helper line rendered right below the prompt — empty hides it */
   public string $hint;
   /** @var array<int|string,string> Static options — key = returned value, item = shown label (int keys return the label) */
   public array $options;
   /** Dynamic source — receives the query on every edit and returns options in the same shape */
   public null|Closure $source;
   /** Max visible matches */
   public int $viewport;
   /** Blink the aim marker */
   public bool $blink;

   // * Data
   /** Last found value — null after a cancel */
   public private(set) mixed $found;

   // * Metadata
   /** Aimed row — index into the current matches */
   public private(set) int $aimed;
   public private(set) Window $Window;
   /** @var array<int,array{0:int|string,1:string}> Current matches as [value, label] pairs */
   private array $matches;
   private Line $Line;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->prompt = 'Search';
      $this->hint = '';
      $this->options = [];
      $this->source = null;
      $this->viewport = 8;
      $this->blink = false;

      // * Data
      $this->found = null;

      // * Metadata
      $this->aimed = 0;
      $this->Window = new Window;
      $this->matches = [];
      $this->Line = new Line;
   }

   /**
    * Finds a value interactively: typing filters the options, `↑`/`↓` aim,
    * Enter confirms the aimed match and Esc cancels. Non-interactive input
    * degrades to a typed line resolved by case-insensitive exact label match.
    *
    * @return mixed The found value — null on cancel or no match.
    */
   public function find (): mixed
   {
      // ? Non-interactive: pipes cannot aim — a typed line resolves by label
      if (BOOTGLY_TTY === false) {
         $Question = new Question($this->Input, $this->Output);
         $Question->prompt = $this->prompt;

         $answer = $Question->ask();

         // ? An empty line finds nothing
         if ($answer === '') {
            $this->found = null;

            // :
            return null;
         }

         // ! Candidates for the typed line
         $this->Line->reset();
         $this->Line->feed($answer);
         $this->search();

         // @@ Resolve by case-insensitive exact label match (multibyte-aware)
         $needle = mb_strtolower($answer);
         foreach ($this->matches as [$value, $label]) {
            if (mb_strtolower($label) === $needle) {
               $this->found = $value;

               // :
               return $value;
            }
         }

         $this->found = null;

         // :
         return null;
      }

      // ! Session
      $this->found = null;
      $this->Line->reset();
      // ! Line editor clamped to the terminal — a wrapped input line desyncs
      //   the relative repaint (it counts logical lines, not visual rows)
      $this->Line->width = max(1, Terminal::$width - mb_strlen($this->prompt) - 2);
      // ! Initial matches — the options show before any typing
      $this->search();
      // ! Height (lines) of the last rendered frame
      $height = 0;

      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();

      try {
         while (true) {
            // ? Reposition to the first line of the previous frame and erase it —
            //   relative movement: absolute save/restore drifts when rendering
            //   scrolls the screen
            if ($height > 0) {
               $this->Output->Cursor->up($height, column: 1);
               $this->Output->Text->clear(lines: $height);
            }

            // @ Render frame — markup resolves in memory first: the repaint
            //   height must count the painted lines, not the raw markup
            //   (labels may carry Template directives)
            $frame = $this->compile();

            $Memory = new Output('php://memory');
            $Memory->render($frame);
            rewind($Memory->stream);
            $painted = (string) stream_get_contents($Memory->stream);

            $height = substr_count($painted, "\n");
            $this->Output->write($painted);

            // @@ Wait for a key (listen() assembles full sequences)
            while (true) {
               $key = $this->Input->listen();

               // ? EOF: interactive input will never arrive — cancel
               if ($key === false) {
                  break 2;
               }
               // ? Key available
               if ($key !== '') {
                  break;
               }

               usleep(50000);
            }

            // @ Control
            if ($this->control($key) === false) {
               break;
            }
         }
      }
      finally {
         // ! Restore — runs even when a source Closure throws mid-loop
         $this->Input->configure(blocking: true, canonical: true, echo: true);
         $this->Output->Cursor->show();
      }

      // @ Final frame — the found label replaces the dropdown
      if ($height > 0) {
         $this->Output->Cursor->up($height, column: 1);
         $this->Output->Text->clear(lines: $height);
      }

      $label = $this->found !== null ? $this->matches[$this->aimed][1] : '';
      $this->Output->render("{$this->prompt}: {$label}\n");

      // :
      return $this->found;
   }

   /**
    * Controls the finder with one keystroke — a pure state machine: no I/O.
    *
    * @param string $key The assembled key bytes (see Input::listen).
    *
    * @return bool false when the interaction finishes (Enter / Esc).
    */
   public function control (string $key): bool
   {
      // ?
      if ($key === '') {
         // :
         return true;
      }

      switch ($key) {
         // @ Aiming — clamped, no wrap
         case "\e[A":
            if ($this->aimed > 0) {
               $this->aimed--;
            }

            break;
         case "\e[B":
            if ($this->aimed < count($this->matches) - 1) {
               $this->aimed++;
            }

            break;
         // @ Confirming
         case "\r":
         case PHP_EOL:
            // ? No aimed match — a pure selector never submits raw text
            if (isSet($this->matches[$this->aimed]) === false) {
               break;
            }

            $this->found = $this->matches[$this->aimed][0];

            // :
            return false;
         // @ Canceling
         case "\e":
            $this->found = null;

            // :
            return false;
         default:
            // ! Query before the edit
            $query = $this->Line->value;

            // ? Edit keys control the buffer; printable input feeds it
            if (
               $key[0] === "\e"
               || $key === "\x7F"
               || (strlen($key) === 1 && ord($key) < 32)
            ) {
               $this->Line->control($key);
            }
            else {
               $this->Line->feed($key);
            }

            // @ Refilter when the query changed
            if ($this->Line->value !== $query) {
               $this->search();
            }
      }

      $this->slide();

      // :
      return true;
   }

   /**
    * Searches the options with the current query — the dynamic source when
    * set, a case-insensitive `stripos` filter over the static options
    * otherwise. Refilters reset the aim.
    *
    * @return void
    */
   private function search (): void
   {
      // ! Query
      $query = $this->Line->value;

      // ! Candidates — a dynamic source filters by itself
      if ($this->source !== null) {
         /** @var array<int|string,string> $candidates */
         $candidates = ($this->source)($query);
         $filter = false;
      }
      else {
         $candidates = $this->options;
         $filter = true;
      }

      // @@ Normalize to [value, label] pairs — int keys return the label itself
      $this->matches = [];

      foreach ($candidates as $value => $label) {
         // ? Static options filter by the query (multibyte-aware, case-insensitive)
         if ($filter === true && $query !== '' && mb_stripos($label, $query) === false) {
            continue;
         }

         $this->matches[] = [is_int($value) === true ? $label : $value, $label];
      }

      // ! Aim reset — the previous row means nothing after a refilter
      $this->aimed = 0;
      $this->slide();
   }

   /**
    * Compiles the frame — the prompt/editor line plus the windowed matches.
    *
    * @return string
    */
   private function compile (): string
   {
      // ! Prompt + line editor
      $frame = "{$this->prompt}: {$this->Line->render()}\n";

      // ? Hint line — dim, right below the prompt: help stays near the cursor
      if ($this->hint !== '') {
         $frame .= "@#Black:{$this->hint}@;\n";
      }

      // ? Placeholder row when nothing matches
      if ($this->matches === []) {
         $frame .= "@#Black:(no matches)@;\n";

         // :
         return $frame;
      }

      // ! Window
      $Window = $this->Window;

      // ? `↑ N more` indicator — before the first visible row
      if ($Window->first > 0) {
         $frame .= "@#Black:↑ {$Window->first} more@;\n";
      }

      // @@ Rows
      for ($row = $Window->first; $row <= $Window->last; $row++) {
         $label = $this->matches[$row][1];

         $frame .= match (true) {
            $row !== $this->aimed => "   {$label}\n",
            $this->blink === true => "@@:=>@; @#Cyan:{$label}@;\n",
            default => "@#Cyan:=> {$label}@;\n"
         };
      }

      // ? `↓ N more` indicator — after the last visible row
      if ($Window->last < $Window->total - 1) {
         $below = $Window->total - 1 - $Window->last;
         $frame .= "@#Black:↓ {$below} more@;\n";
      }

      // :
      return $frame;
   }

   /**
    * Slides the viewport window so the aimed row stays visible.
    *
    * @return void
    */
   private function slide (): void
   {
      // ? A viewport below 1 would render an empty, lying dropdown
      $this->Window->size = max(1, $this->viewport);
      $this->Window->total = count($this->matches);
      $this->Window->slide($this->aimed);
   }
}
