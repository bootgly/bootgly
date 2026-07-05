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
use function count;
use function feof;
use function in_array;
use function is_string;
use function ord;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function stream_isatty;
use function stripos;
use function strlen;
use function strtolower;
use function substr_count;
use function trim;
use function usleep;
use Closure;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Line;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Output\Window;
use Bootgly\CLI\UI\Components\Alert;


class Question extends Component
{
   public Input $Input;
   public Output $Output;

   // * Config
   public string $prompt;
   public string $default;
   public bool $required;
   public int $attempts;
   public null|Closure $Validator;
   /** Mask self-echoed per typed character (secret input) — null echoes as typed */
   public null|string $mask;
   /** @var array<string> Autocomplete suggestions — interactive dropdown (empty disables) */
   public array $suggestions;
   /** Visible dropdown rows */
   public int $limit;
   /** Strict mode: the answer must be one of the suggestions */
   public bool $strict;

   // * Metadata
   public private(set) string $answer;
   public private(set) int $attempt;
   public private(set) null|bool $confirmed;


   public function __construct (Input &$Input, Output &$Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->prompt = '';
      $this->default = '';
      $this->required = false;
      $this->attempts = 0;
      $this->Validator = null;
      $this->mask = null;
      $this->suggestions = [];
      $this->limit = 5;
      $this->strict = false;

      // * Metadata
      $this->answer = '';
      $this->attempt = 0;
      $this->confirmed = null;
   }


   protected function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      // * Config
      $prompt = $this->prompt;
      $default = $this->default;

      // ? Masked questions never reveal the default value
      if ($this->mask !== null && $default !== '') {
         $default = str_repeat($this->mask, 3);
      }

      // @
      if ($mode === self::RETURN_OUTPUT) {
         $Output = new Output('php://memory');
      }
      $Output ??= $this->Output;
      // ---
      $suffix = $default !== '' ? " [{$default}]: " : ': ';
      $Output->write("{$prompt}{$suffix}");

      if ($mode === self::RETURN_OUTPUT) {
         rewind($Output->stream);
         $output = stream_get_contents($Output->stream);
         return $output;
      }

      return null;
   }

   /**
    * Asks the question until a valid answer, EOF or exhausted attempts.
    * Empty answers assume the default; the Validator Closure receives the
    * candidate answer and returns true or an error message string.
    *
    * @return string
    */
   public function ask (): string
   {
      // ! Alert component (validation feedback)
      $Alert = new Alert($this->Output);

      // * Metadata
      $this->attempt = 0;

      // @@ Ask until a valid answer, EOF or exhausted attempts
      while (true) {
         // ? Attempts exhausted assume the default
         if ($this->attempts > 0 && $this->attempt >= $this->attempts) {
            $this->answer = $this->default;
            break;
         }

         $this->attempt++;

         $this->render();

         // ? Masked input: real TTYs disable kernel echo and self-echo the mask instead
         if ($this->mask !== null) {
            $tty = stream_isatty($this->Input->stream);

            if ($tty === true) {
               $this->Input->configure(echo: false);
               $this->Input->echo = true;
            }

            $line = $this->Input->scan($this->mask);

            if ($tty === true) {
               $this->Input->echo = false;
               $this->Input->configure(echo: true);
            }
         }
         else if ($this->suggestions !== [] && BOOTGLY_TTY === true) {
            // ? Interactive autocomplete — line editor + filtered dropdown
            $line = $this->suggest();
         }
         else {
            $line = $this->Input->scan();
         }

         // ? EOF assumes the default
         if ($line === false) {
            $this->answer = $this->default;
            break;
         }

         $answer = trim($line);

         // ? Empty answer assumes the default
         if ($answer === '') {
            // ? Required questions without a default re-ask
            if ($this->required === true && $this->default === '') {
               $Alert->Type::Failure->set();
               $Alert->message = 'An answer is required.';
               $Alert->render();

               continue;
            }

            $answer = $this->default;
         }

         // ? Strict suggestions require a listed answer
         if (
            $this->strict === true
            && $this->suggestions !== []
            && in_array($answer, $this->suggestions, true) === false
         ) {
            $Alert->Type::Failure->set();
            $Alert->message = 'Pick one of the suggestions.';
            $Alert->render();

            continue;
         }

         // ? Validate the candidate answer
         if ($this->Validator !== null) {
            $result = ($this->Validator)($answer);

            if (is_string($result) === true) {
               $Alert->Type::Failure->set();
               $Alert->message = $result;
               $Alert->render();

               continue;
            }
         }

         $this->answer = $answer;
         break;
      }

      // :
      return $this->answer;
   }

   /**
    * Asks for a yes/no confirmation.
    * Empty answers and EOF assume the default; on non-interactive input,
    * invalid answers also fall back to the default.
    *
    * @param string $prompt The confirmation prompt — empty keeps the configured one.
    * @param bool $default The value assumed on empty answer or EOF.
    *
    * @return bool
    */
   public function confirm (string $prompt = '', bool $default = false): bool
   {
      // ? Override the configured prompt when provided
      if ($prompt !== '') {
         $this->prompt = $prompt;
      }

      // ! Answer assumed on EOF, empty answer or non-interactive fallback
      $suffix = $default === true ? ' [Y/n] ' : ' [y/N] ';
      $confirmed = $default;

      // @@ Ask until a valid answer, EOF or non-interactive fallback
      while (true) {
         $this->Output->write("{$this->prompt}{$suffix}");

         $line = $this->Input->scan();

         // ? EOF assumes the default
         if ($line === false) {
            break;
         }

         $answer = strtolower(trim($line));

         // ? Empty answer assumes the default
         if ($answer === '') {
            break;
         }
         // ? Affirmative
         if ($answer === 'y' || $answer === 'yes') {
            $confirmed = true;
            break;
         }
         // ? Negative
         if ($answer === 'n' || $answer === 'no') {
            $confirmed = false;
            break;
         }

         // ? Non-interactive invalid answers fall back to the default
         if (BOOTGLY_TTY === false) {
            break;
         }
      }

      $this->confirmed = $confirmed;

      // :
      return $confirmed;
   }

   /**
    * Captures a line interactively with a filtered suggestions dropdown.
    * Typing filters (`stripos`); `↑`/`↓` aim; `Tab` completes to the aimed match;
    * `Esc` closes the dropdown keeping the typed text; Enter submits the typed text
    * (or the aimed match on strict mode).
    *
    * @return string|false The captured line — false on EOF.
    */
   private function suggest (): string|false
   {
      // ! Line editor + dropdown window
      $Line = new Line;
      $Window = new Window(size: $this->limit);

      // ! Raw input mode
      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();

      // * Metadata
      /** @var array<string> $matches */
      $matches = $this->suggestions;
      $aimed = 0;
      $open = true;
      $height = 0;
      $eof = false;

      // @@ Edit until Enter or EOF
      while (true) {
         // @ Compose the frame — prompt line + dropdown rows
         $frame = "{$this->prompt}: {$Line->render()}\n";

         if ($open === true && $matches !== []) {
            $Window->total = count($matches);
            $Window->slide($aimed);

            if ($Window->first > 0) {
               $frame .= "@#Black:↑ {$Window->first} more@;\n";
            }

            for ($index = $Window->first; $index <= $Window->last; $index++) {
               $frame .= $aimed === $index
                  ? "@#Cyan:=> {$matches[$index]}@;\n"
                  : "   {$matches[$index]}\n";
            }

            if ($Window->last < $Window->total - 1) {
               $below = $Window->total - 1 - $Window->last;
               $frame .= "@#Black:↓ {$below} more@;\n";
            }
         }

         // @ Repaint relatively over the previous frame
         if ($height > 0) {
            $this->Output->Cursor->up($height, column: 1);
            $this->Output->Text->clear(down: true);
         }

         // ! php://memory resolves the markup before counting lines
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

            // ? EOF: interactive input will never arrive
            if (feof($this->Input->stream) === true) {
               $eof = true;

               break 2;
            }

            usleep(50000);
         }

         if ($key === false) {
            $eof = true;

            break;
         }

         // @ Control
         switch ($key) {
            case Keystrokes::UP->value:
               if ($aimed > 0) {
                  $aimed--;
               }
               break;
            case Keystrokes::DOWN->value:
               if ($aimed < count($matches) - 1) {
                  $aimed++;
               }
               break;
            case Keystrokes::TAB->value:
               // ? Tab completes to the aimed match
               if (isSet($matches[$aimed]) === true) {
                  $Line->reset();
                  $Line->feed($matches[$aimed]);
               }
               break;
            case Keystrokes::ESCAPE->value:
               // ? Bare Escape closes the dropdown, keeping the typed text
               $open = false;
               break;
            default:
               // ? Enter submits
               if ($key === Keystrokes::ENTER->value || $key === "\r") {
                  break 2;
               }

               // ? Edit keys control the buffer; printable input feeds it
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

               // @ Refilter on any edit
               $filtered = [];
               foreach ($this->suggestions as $suggestion) {
                  if ($Line->value === '' || stripos($suggestion, $Line->value) !== false) {
                     $filtered[] = $suggestion;
                  }
               }

               $matches = $filtered;
               $aimed = 0;
               $open = true;
         }
      }

      // ! Captured line — strict mode submits the aimed match
      $line = $Line->value;
      if ($eof === false && $this->strict === true && isSet($matches[$aimed]) === true) {
         $line = $matches[$aimed];
      }

      // @ Final frame — the captured line replaces the dropdown
      if ($height > 0) {
         $this->Output->Cursor->up($height, column: 1);
         $this->Output->Text->clear(down: true);
      }
      $this->Output->write("{$this->prompt}: {$line}\n");

      // @ Restore input settings and the cursor
      $this->Input->configure(blocking: true, canonical: true, echo: true);
      $this->Output->Cursor->show();

      // ?: EOF with no typed text
      if ($eof === true && $line === '') {
         return false;
      }

      // :
      return $line;
   }
}
