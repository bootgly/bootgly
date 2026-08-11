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
use function array_column;
use function feof;
use function is_int;
use function is_string;
use function max;
use function mb_stripos;
use function mb_strtolower;
use function mb_strwidth;
use function preg_replace;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function stream_isatty;
use function strtolower;
use function substr_count;
use function trim;
use function usleep;
use Closure;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Line;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Base\Listbox;
use Bootgly\CLI\UI\Components\Alert;


/**
 * Textbox — the single-line text input: a prompt, a line editor and, when it
 * has options to offer, a Listbox listing them under the input.
 *
 * The same component covers free text (`ask()`), a yes/no answer (`confirm()`),
 * secret input (`mask`), autocompletion (`options`) and search (`source` — a
 * Closure re-queried on every edit). What separates completing from picking is
 * `strict`: with it off Enter submits the typed text and the options are mere
 * suggestions; with it on only a listed option can be submitted, and Enter
 * returns the aimed one's value.
 *
 * Validation runs on the candidate answer through `Validator`, which returns
 * `true` or an error message; `required`, `default` and `attempts` bound the
 * asking loop. Non-interactive input reads a plain stdin line, so consumer code
 * stays identical on pipes and CI.
 */
class Textbox extends Component
{
   public Input $Input;
   public Output $Output;

   // * Config
   public string $prompt;
   /** Dim helper line rendered below the prompt while editing — empty hides it */
   public string $hint;
   public string $default;
   public bool $required;
   /** Max asking rounds before the default is assumed (0 = unbounded) */
   public int $attempts;
   /** Receives the candidate answer; returns true or an error message */
   public null|Closure $Validator;
   /** Mask self-echoed per typed character (secret input) — null echoes as typed */
   public null|string $mask;
   /** @var array<int|string,string> Options — key = returned value, item = shown label (int keys return the label) */
   public array $options;
   /** Dynamic source — receives the query on every edit and returns options in the same shape */
   public null|Closure $source;
   /** Max visible option rows */
   public int $viewport;
   /** Input marker drawn before the prompt in every mode (empty disables) — markup */
   public string $marker;
   /** Border line character drawn above and below the input (empty disables) — markup */
   public string $border;
   /** The answer must be one of the options — Enter submits the aimed one */
   public bool $strict;

   // * Metadata
   public private(set) string $answer;
   public private(set) int $attempt;
   public private(set) null|bool $confirmed;
   /** @var array<int,array{0:string,1:string}> Current matches as [value, label] pairs */
   private array $matches;
   /** Whether the last capture submitted an option picked from the list */
   private bool $selected;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->prompt = '';
      $this->hint = '';
      $this->default = '';
      $this->required = false;
      $this->attempts = 0;
      $this->Validator = null;
      $this->mask = null;
      $this->options = [];
      $this->source = null;
      $this->viewport = 5;
      $this->marker = '@#Cyan:❯@; ';
      $this->border = '─';
      $this->strict = false;

      // * Metadata
      $this->answer = '';
      $this->attempt = 0;
      $this->confirmed = null;
      $this->matches = [];
      $this->selected = false;
   }


   /**
    * Composes the rules framing the input — the hint rides the bottom one,
    * interrupting it like a legend, so it reads right under what it explains.
    * Borderless boxes return empty rows.
    *
    * @return array{0: string,1: string} The top and bottom rule rows.
    */
   private function frame (): array
   {
      // ? Borderless
      if ($this->border === '') {
         // :
         return ['', ''];
      }

      $columns = isSet(Terminal::$width) === true ? (int) Terminal::$width : 80;
      $rule = '@#Black:' . str_repeat($this->border, $columns) . "@;\n";

      // ?: A hint interrupts the bottom rule
      if ($this->hint === '') {
         return [$rule, $rule];
      }

      $filled = max(0, $columns - mb_strwidth($this->hint) - 3);

      // :
      return [
         $rule,
         "@#Black:{$this->border} {$this->hint} " . str_repeat($this->border, $filled) . "@;\n"
      ];
   }

   /**
    * Draws the framed prompt and anchors the cursor at the end of its line —
    * the whole box is on screen before the first keystroke, so the terminal's
    * own echo lands inside it instead of pushing the bottom rule away.
    *
    * @param string $line The prompt line markup (no trailing break).
    *
    * @return void
    */
   private function anchor (string $line): void
   {
      [$rule, $legend] = $this->frame();

      // ! Painted width decides where the echo starts
      $Memory = new Output('php://memory');
      $Memory->render($line);
      rewind($Memory->stream);

      $painted = (string) preg_replace(
         '/\e\[[0-9;]*m/',
         '',
         (string) stream_get_contents($Memory->stream)
      );

      // @ The full box
      $this->Output->render($rule);
      $this->Output->render($line);
      $this->Output->write("\n");
      $this->Output->render($legend);

      // @ Back onto the prompt line, after the prompt — relative only
      $this->Output->Cursor->up($rule === '' ? 1 : 2, column: 1);
      $this->Output->Cursor->moveTo(column: mb_strwidth($painted) + 1);
   }

   /**
    * Renders the prompt line (with the default hint, masked when secret).
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string
    */
   protected function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      $default = $this->default;

      // ? Masked questions never reveal the default value
      if ($this->mask !== null && $default !== '') {
         $default = str_repeat($this->mask, 3);
      }

      $suffix = $default !== '' ? " [{$default}]: " : ': ';

      // ?: The line markup — anchor() paints it and measures the result
      if ($mode === self::RETURN_OUTPUT) {
         return "{$this->marker}{$this->prompt}{$suffix}";
      }

      // ? render, not write — prompts may carry Template markup
      $this->Output->render("{$this->marker}{$this->prompt}{$suffix}");

      return null;
   }

   /**
    * Asks until a valid answer, EOF or exhausted attempts.
    * Empty answers assume the default.
    *
    * @return string
    */
   public function ask (): string
   {
      // ! Validation feedback
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

         // ? Masked input: real TTYs disable kernel echo and self-echo the mask
         if ($this->mask !== null) {
            $this->selected = false;

            if (BOOTGLY_TTY === true) {
               $this->anchor((string) $this->render(self::RETURN_OUTPUT));
            }
            else {
               $this->render();
            }

            $tty = stream_isatty($this->Input->stream);

            // ? Raw mode: the kernel line discipline would buffer the whole
            //   line and only release it on Enter, so the mask would appear
            //   all at once instead of per typed character
            if ($tty === true) {
               $this->Input->configure(canonical: false, echo: false);
               $this->Input->echo = true;
            }

            $line = $this->Input->scan($this->mask);

            if ($tty === true) {
               $this->Input->echo = false;
               $this->Input->configure(canonical: true, echo: true);
            }

            // @ Nothing echoed the Enter (raw mode suppressed it on real
            //   terminals; emulated ones are pipes with no line discipline),
            //   so step past the bottom rule explicitly
            if (BOOTGLY_TTY === true) {
               $this->Output->Cursor->down($this->border === '' ? 1 : 2, column: 1);
            }
         }
         // ? Interactive terminals always edit in the framed editor — the
         //   option list simply stays empty when there is nothing to offer
         else if (BOOTGLY_TTY === true) {
            $line = $this->capture();
         }
         // ? Non-interactive input: a plain prompt and a stdin line, unframed
         else {
            $this->selected = false;

            $this->render();

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

         // ? Strict mode requires a listed answer — an option picked from the
         //   list is listed by construction (a dynamic source would not resolve
         //   it again from an empty query)
         if ($this->strict === true && $this->selected === false) {
            $resolved = $this->resolve($answer);

            if ($resolved === null) {
               $Alert->Type::Failure->set();
               $Alert->message = 'Pick one of the options.';
               $Alert->render();

               continue;
            }

            // ? A typed label answers with the option's value, like a pick does
            $answer = $resolved;
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
         // ? Framed on terminals — pipes keep their output clean
         if (BOOTGLY_TTY === true) {
            $this->anchor("{$this->marker}{$this->prompt}{$suffix}");
         }
         else {
            $this->Output->render("{$this->marker}{$this->prompt}{$suffix}");
         }

         $line = $this->Input->scan();

         // @ The echoed break landed on the bottom rule — step past it
         if (BOOTGLY_TTY === true) {
            $this->Output->Cursor->down(1, column: 1);
         }

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

      // * Metadata
      $this->confirmed = $confirmed;

      // :
      return $confirmed;
   }

   /**
    * Captures a line interactively with the options listed in a Listbox below it.
    * Typing re-queries the options; `↑`/`↓` aim; `Tab` completes to the aimed
    * label; `Esc` closes the list keeping the typed text; Enter submits the
    * typed text — or the aimed option's value on strict mode.
    *
    * @return string|false The captured line — false on EOF.
    */
   private function capture (): string|false
   {
      // ! Line editor + option list
      $Line = new Line;

      $Listbox = new Listbox($this->Output);
      $Listbox->viewport = $this->viewport;
      $Listbox->width = isSet(Terminal::$width) === true ? Terminal::$width - 4 : null;

      // ! Raw input mode
      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();

      // ! The rules framing the input row
      [$rule, $legend] = $this->frame();

      $this->selected = false;

      $this->search('');
      $open = true;
      $height = 0;
      $eof = false;

      // @@ Edit until Enter or EOF
      while (true) {
         // @ Compose the frame — the input sits between two rules, with the
         //   option list dropping below them
         $frame = $rule;
         $frame .= "{$this->marker}{$this->prompt}: {$Line->render()}\n";

         // ? Borderless boxes have no rule to carry the hint
         if ($this->border === '' && $this->hint !== '') {
            $frame .= "@#Black:{$this->hint}@;\n";
         }

         $frame .= $legend;

         // ? The list shows the labels; the values answer for them on submit —
         //   the typed text lights up inside each match
         $Listbox->query = $Line->value;
         $Listbox->options = $open === true ? array_column($this->matches, 1) : [];
         $frame .= (string) $Listbox->render(self::RETURN_OUTPUT);

         // @ Repaint relatively over the previous frame
         if ($height > 0) {
            $this->Output->Cursor->up($height, column: 1);
            $this->Output->Text->clear(lines: $height);
         }

         // ! php://memory resolves the markup before counting lines
         $Memory = new Output('php://memory');
         $Memory->render($frame);
         rewind($Memory->stream);
         $painted = (string) stream_get_contents($Memory->stream);

         $height = substr_count($painted, "\n");

         $this->Output->write($painted);

         // @@ Wait for a key (listen() assembles full sequences)
         while (true) {
            $key = $this->Input->listen();

            // ? EOF: interactive input will never arrive
            if ($key === false || feof($this->Input->stream) === true) {
               $eof = true;

               break 2;
            }
            // ? Key available
            if ($key !== '') {
               break;
            }

            usleep(50000);
         }

         // @ Control
         switch ($key) {
            case Keystrokes::UP->value:
               $Listbox->regress();
               break;
            case Keystrokes::DOWN->value:
               $Listbox->advance();
               break;
            case Keystrokes::TAB->value:
               // ? Tab completes to the aimed label
               if (isSet($this->matches[$Listbox->aimed]) === true) {
                  $Line->reset();
                  $Line->feed($this->matches[$Listbox->aimed][1]);
               }
               break;
            case Keystrokes::ESCAPE->value:
               // ? Bare Escape closes the list, keeping the typed text
               $open = false;
               break;
            default:
               // ? Enter submits
               if ($key === Keystrokes::ENTER->value || $key === Keystrokes::CTRL_M->value) {
                  break 2;
               }

               // ? Edit keys control the buffer; printable input feeds it
               $key[0] === "\e" || $key === Keystrokes::BACKSPACE->value || $key < ' '
                  ? $Line->control($key)
                  : $Line->feed($key);

               // @ Re-query on any edit — the previous aim means nothing after it
               $this->search($Line->value);

               $Listbox->aim(0);
               $open = true;
         }
      }

      // ! Captured line — strict mode submits the aimed option's value
      $line = $Line->value;
      if ($eof === false && $this->strict === true && isSet($this->matches[$Listbox->aimed]) === true) {
         $line = $this->matches[$Listbox->aimed][0];

         $this->selected = true;
      }

      // @ Final frame — the captured line replaces the option list
      if ($height > 0) {
         $this->Output->Cursor->up($height, column: 1);
         $this->Output->Text->clear(lines: $height);
      }

      // ? The interaction is over — the settled frame drops the hint legend
      $this->Output->render("{$rule}{$this->marker}{$this->prompt}: {$line}\n{$rule}");

      // @ Restore input settings and the cursor
      $this->Input->configure(blocking: true, canonical: true, echo: true);
      $this->Output->Cursor->show();

      // ?:
      return $eof === true ? false : $line;
   }

   /**
    * Re-queries the options for a query, normalizing them to [value, label]
    * pairs — a dynamic source filters by itself; static options filter here.
    *
    * @param string $query The current query.
    *
    * @return void
    */
   private function search (string $query): void
   {
      // ! Candidates
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

         $this->matches[] = [is_int($value) === true ? $label : (string) $value, $label];
      }
   }

   /**
    * Resolves an answer into an option value (strict mode) — a typed label
    * answers with its value, exactly like picking the row would. Values match
    * exactly (they are identifiers); labels match case-insensitively, the same
    * way the interactive list filters them.
    *
    * @param string $answer The candidate answer.
    *
    * @return null|string The option value — null when the answer is not listed.
    */
   private function resolve (string $answer): null|string
   {
      // ? Nothing to resolve against
      if ($this->options === [] && $this->source === null) {
         // :
         return $answer;
      }

      // ! A dynamic source is queried with the answer itself — an empty query
      //   would resolve nothing
      $this->search($this->source !== null ? $answer : '');

      // @@
      foreach ($this->matches as [$value, $label]) {
         if ($answer === $value || mb_strtolower($answer) === mb_strtolower($label)) {
            // :
            return $value;
         }
      }

      // :
      return null;
   }
}
