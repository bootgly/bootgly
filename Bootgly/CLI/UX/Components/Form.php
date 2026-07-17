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
use function array_search;
use function count;
use function in_array;
use function is_numeric;
use function is_string;
use function max;
use function mb_strlen;
use function preg_replace;
use function rewind;
use function rtrim;
use function str_repeat;
use function stream_get_contents;
use function strtolower;
use function substr_count;
use function trim;
use function usleep;
use Closure;

use Bootgly\ABI\Data\__String;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Line;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Alert;
use Bootgly\CLI\UI\Components\Fieldset;
use Bootgly\CLI\UI\Components\Menu;
use Bootgly\CLI\UI\Components\Question;
use Bootgly\CLI\UX\Components\Form\Controls;
use Bootgly\CLI\UX\Components\Form\Field;
use Bootgly\CLI\UX\Components\Form\Fields;


class Form extends Component
{
   public Input $Input;
   public Output $Output;

   // * Config
   public string $title;
   /** Per-field attempts forwarded to the field editors (0 = unlimited) */
   public int $attempts;
   /** Field frame width, in columns — null follows the terminal */
   public null|int $width;

   // * Data
   public Fields $Fields;

   // * Metadata
   /** @var array<string,string> */
   public private(set) array $answers;
   public private(set) bool $confirmed;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->title = '';
      $this->attempts = 0;
      $this->width = null;

      // * Data
      $this->Fields = new Fields;

      // * Metadata
      $this->answers = [];
      $this->confirmed = false;
   }


   /**
    * Adds a declarative field to the form.
    *
    * @param string $label The field label (also the answers array key).
    * @param Controls $Control The field control (Text, Secret, Select, Confirm).
    * @param string $default The value assumed on empty answer or EOF.
    * @param bool $required Whether an answer is required (Text / Secret).
    * @param null|Closure $Validator Validates the answer: returns true or an error message.
    * @param array<string> $options The choices of a Select field.
    * @param null|string $mask The mask echoed per typed character (Secret defaults to `•`).
    *
    * @return Field
    */
   public function add (
      string $label,
      Controls $Control = Controls::Text,
      string $default = '',
      bool $required = false,
      null|Closure $Validator = null,
      array $options = [],
      null|string $mask = null
   ): Field
   {
      $Field = new Field($label);
      // * Config
      $Field->Control = $Control;
      $Field->default = $default;
      $Field->required = $required;
      $Field->Validator = $Validator;
      $Field->options = $options;
      // ? Secret fields mask with `•` unless a custom mask is set
      $Field->mask = $mask ?? ($Control === Controls::Secret ? '•' : null);

      // :
      return $this->Fields->add($Field);
   }

   /**
    * Renders the answers summary (Fieldset frame).
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return mixed
    */
   protected function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      // ! Summary lines — masked answers are never revealed
      $content = '';
      foreach ($this->Fields->Fields as $Field) {
         $answer = $Field->answer;

         if ($Field->mask !== null && $answer !== '') {
            $answer = str_repeat($Field->mask, 3);
         }

         $content .= "{$Field->label}: {$answer}\n";
      }
      $content = rtrim($content, "\n");

      // @
      if ($mode === self::RETURN_OUTPUT) {
         $Output = new Output('php://memory');
      }
      $Output ??= $this->Output;
      // ---
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = $this->title !== '' ? $this->title : 'Summary';
      $Fieldset->content = $content;
      $Fieldset->render();

      if ($mode === self::RETURN_OUTPUT) {
         rewind($Output->stream);
         $output = stream_get_contents($Output->stream);
         return $output;
      }

      return null;
   }

   /**
    * Asks all fields sequentially.
    * Interactive terminals render each field inside a fieldset frame — the label as
    * the legend on the top border, the editor inside — support revert (`↑` then Enter
    * goes back one field) and end with a summary + confirm loop (edit any field before
    * submitting). Non-interactive streams read one stdin line per field,
    * deterministically — no frames, no revert, no summary.
    *
    * @return array<string,string> The answers, keyed by field label.
    */
   public function ask (): array
   {
      $Fields = $this->Fields->Fields;

      // ? Non-TTY: strictly sequential — one stdin line per field, deterministic
      if (BOOTGLY_TTY === false) {
         foreach ($Fields as $Field) {
            $this->edit($Field);

            $this->answers[$Field->label] = $Field->answer;
         }

         $this->confirmed = true;

         // :
         return $this->answers;
      }

      // @@ Sequential fields with revert (`↑` then Enter steps back one field)
      $index = 0;
      $count = count($Fields);

      while ($index < $count) {
         $Field = $Fields[$index];

         $answer = $this->edit($Field);

         // ? Revert sentinel: step back with the previous answer as default
         if ($answer === Keystrokes::UP->value && $index > 0) {
            $index--;

            // @ Erase the previous field's settled frame (3 rows + 1 gap)
            $this->Output->Cursor->up(4, column: 1);
            $this->Output->Text->clear(lines: 4);

            continue;
         }

         $this->answers[$Field->label] = $Field->answer;
         $index++;
      }

      // @@ Summary + confirm loop (edit any field before submitting)
      while (true) {
         $this->render();

         // ! Confirm Menu — option 0 confirms; option N edits field N-1
         $Menu = new Menu($this->Input, $this->Output);
         $Menu->prompt = "@#Black:(↑/↓ to move, Enter to confirm)@;\n";

         $Options = $Menu->Items->Options;
         $Options->Selection::Unique->set();

         $Options->add(label: 'Confirm');
         foreach ($Fields as $Field) {
            $Options->add(label: "Edit {$Field->label}");
         }

         // @@ Render until Enter
         foreach ($Menu->rendering() as $ignored);

         $choice = (int) ($Menu->selected[0] ?? 0);

         // ?: Confirm submits the form
         if ($choice === 0) {
            $this->Output->write("\n");

            break;
         }

         $this->Output->write("\n");

         // @ Edit the chosen field (revert sentinel stays in the loop)
         $Field = $Fields[$choice - 1];

         $answer = $this->edit($Field);

         if ($answer !== Keystrokes::UP->value) {
            $this->answers[$Field->label] = $Field->answer;
         }
      }

      $this->confirmed = true;

      // :
      return $this->answers;
   }

   /**
    * Edits a field with its control editor.
    * Interactive terminals edit inside a fieldset frame; the settled frame (dim
    * legend, recorded answer) stays on screen. Non-interactive streams keep the
    * plain line editors.
    *
    * @param Field $Field The field to edit.
    *
    * @return string The captured answer — or the revert sentinel (`\e[A`), not recorded.
    */
   private function edit (Field $Field): string
   {
      // ! Previous answer becomes the default when re-editing
      $default = $Field->answered === true ? $Field->answer : $Field->default;

      // ? Non-TTY: plain line editors — one stdin read per field
      if (BOOTGLY_TTY === false) {
         $answer = match ($Field->Control) {
            Controls::Text, Controls::Secret => $this->question($Field, $default),
            Controls::Select => $this->choose($Field, $default),
            Controls::Confirm => $this->confirm($Field, $default)
         };
      }
      else {
         $answer = match ($Field->Control) {
            Controls::Text, Controls::Secret => $this->capture($Field, $default),
            Controls::Select => $this->select($Field, $default),
            Controls::Confirm => $this->select($Field, $default, confirm: true)
         };
      }

      // ?: The revert sentinel is not an answer
      if ($answer === Keystrokes::UP->value) {
         return $answer;
      }

      $Field->update($answer);

      // ? The settled frame replaces the live editor frame
      if (BOOTGLY_TTY === true) {
         $this->settle($Field);
      }

      // :
      return $answer;
   }

   // # Fieldset frames (interactive editors)

   /**
    * Resolves Template markup into painted output (SGR).
    *
    * @param string $markup The content with Template markup.
    *
    * @return string
    */
   private function paint (string $markup): string
   {
      $Memory = new Output('php://memory');
      $Memory->render($markup);
      rewind($Memory->stream);

      // :
      return (string) stream_get_contents($Memory->stream);
   }

   /**
    * Measures the visible width of painted output (escape-aware).
    *
    * @param string $painted The painted output.
    *
    * @return int
    */
   private function measure (string $painted): int
   {
      // :
      return mb_strlen(
         (string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $painted)
      );
   }

   /**
    * Renders a fieldset frame — the legend embedded in the top border, one
    * content row per line — as a painted string.
    *
    * @param string $legend The frame legend (the field label).
    * @param array<string> $lines The content rows (Template markup allowed).
    * @param string $state The frame state: `active` (cyan legend), `valid`
    *                      (green legend and borders — the value passes the
    *                      field Validator) or `settled` (dim).
    *
    * @return string
    */
   private function frame (string $legend, array $lines, string $state): string
   {
      $width = max(20, $this->width
         ?? (isSet(Terminal::$width) === true ? Terminal::$width : 80));

      // ! State colors — valid values paint the whole fieldset line green
      $painting = match ($state) {
         'active' => '@#Cyan:',
         'valid' => '@#Green:',
         default => '@#Black:'
      };
      $bordering = $state === 'valid' ? '@#Green:' : '@#Black:';

      // ! Legend — colored by state, breathing spaces like a fieldset title
      // ? Spaces stay OUTSIDE the markup — Template style markers swallow adjacent spaces
      $legend = ' ' . $this->paint("{$painting}{$legend}@;") . ' ';
      $entitled = $this->measure($legend);

      // # Top border row — embeds the legend
      $fill = str_repeat('─', max(1, $width - 2 - $entitled));
      $frame = $this->paint("{$bordering}┌@;") . $legend . $this->paint("{$bordering}{$fill}┐@;") . "\n";

      // # Content rows
      $left = $this->paint("{$bordering}│@;") . ' ';
      $right = ' ' . $this->paint("{$bordering}│@;");
      $interior = $width - 4;

      foreach ($lines as $line) {
         $painted = $this->paint($line);
         $pad = str_repeat(' ', max(0, $interior - $this->measure($painted)));

         $frame .= "{$left}{$painted}{$pad}{$right}\n";
      }

      // # Bottom border row
      $bottom = str_repeat('─', max(1, $width - 2));
      $frame .= $this->paint("{$bordering}└{$bottom}┘@;") . "\n";

      // :
      return $frame;
   }

   /**
    * Repaints a live frame relatively over the previous one.
    *
    * @param string $frame The painted frame.
    * @param int $height The height (rows) of the previous frame — 0 on the first paint.
    *
    * @return int The height (rows) of the painted frame.
    */
   private function repaint (string $frame, int $height): int
   {
      if ($height > 0) {
         $this->Output->Cursor->up($height, column: 1);
         $this->Output->Text->clear(lines: $height);
      }

      $this->Output->write($frame);

      // :
      return substr_count($frame, "\n");
   }

   /**
    * Erases the current live frame.
    *
    * @param int $height The height (rows) of the live frame.
    */
   private function erase (int $height): void
   {
      if ($height > 0) {
         $this->Output->Cursor->up($height, column: 1);
         $this->Output->Text->clear(lines: $height);
      }
   }

   /**
    * Renders the settled frame of an answered field — dim legend, recorded answer,
    * green when the answer passes the field Validator — followed by a blank gap row.
    *
    * @param Field $Field The answered field.
    */
   private function settle (Field $Field): void
   {
      $answer = $Field->answer;

      // ? Validated answers settle green (HTML `:valid`-like)
      $state = 'settled';
      if (
         $Field->Validator !== null
         && $answer !== ''
         && ($Field->Validator)($answer) === true
      ) {
         $state = 'valid';
      }

      // ? Masked answers are never revealed
      if ($Field->mask !== null && $answer !== '') {
         $answer = str_repeat($Field->mask, 3);
      }
      // ? Confirm answers settle with their option label
      if ($Field->Control === Controls::Confirm) {
         $answer = $answer === 'yes' ? 'Yes' : 'No';
      }

      $frame = $this->frame($Field->label, [$answer], $state);

      $this->Output->write("{$frame}\n");
   }

   /**
    * Waits for a keystroke (non-blocking reads keep signals dispatched).
    *
    * @return string|false The keystroke — false when the input closes (EOF).
    */
   private function listen (): string|false
   {
      while (true) {
         $key = $this->Input->listen();

         // ?: Input closed — interactive input will never arrive
         if ($key === false) {
            return false;
         }
         // ?: A complete keystroke
         if ($key !== '') {
            return $key;
         }

         usleep(50000);
      }
   }

   /**
    * Captures a Text / Secret field inside a live fieldset frame — raw line editor,
    * masked echo, required/Validator semantics and the `↑` + Enter revert.
    *
    * @param Field $Field The field to capture.
    * @param string $default The value assumed on empty answer or EOF.
    *
    * @return string The captured answer — or the revert sentinel.
    */
   private function capture (Field $Field, string $default): string
   {
      // ! Line editor — masked echo and a viewport bound to the frame interior
      $width = max(20, $this->width
         ?? (isSet(Terminal::$width) === true ? Terminal::$width : 80));

      $Line = new Line;
      $Line->mask = $Field->mask;
      $Line->width = max(6, $width - 7);

      // ! Default placeholder — masked defaults are never revealed
      $placeholder = '';
      if ($default !== '') {
         $placeholder = $Field->mask !== null
            ? '[' . str_repeat($Field->mask, 3) . ']'
            : "[{$default}]";
      }

      $Alert = new Alert($this->Output);
      $Alert->spaced = false;

      // ! Raw input mode
      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();

      // * Metadata
      $height = 0;
      $attempt = 1;
      $reverting = false;
      $alert = '';
      $answer = '';

      // @@ Edit until a valid answer, revert or EOF — every exit assigns and breaks
      while (true) {
         // @ Compose the live frame — the editor line plus the default placeholder,
         //   the validation alert below the frame (HTML-form-like)
         $content = $Line->render();
         if ($Line->value === '' && $placeholder !== '') {
            $content .= "@#Black: {$placeholder}@;";
         }

         // ? Live validation — the fieldset line turns green while the typed
         //   value passes the field Validator (HTML `:valid`-like)
         $state = 'active';
         if (
            $Field->Validator !== null
            && $Line->value !== ''
            && ($Field->Validator)(trim($Line->value)) === true
         ) {
            $state = 'valid';
         }

         $height = $this->repaint(
            $this->frame($Field->label, [$content], $state) . $alert,
            $height
         );

         $key = $this->listen();

         // ? EOF assumes the default
         if ($key === false) {
            $answer = $default;
            break;
         }

         // ? `↑` arms the revert — the next Enter steps back one field
         if ($key === Keystrokes::UP->value) {
            $reverting = true;
            continue;
         }

         // ? Enter submits — or reverts when armed
         if ($key === Keystrokes::ENTER->value || $key === "\r") {
            if ($reverting === true) {
               $this->erase($height);
               $this->restore();

               // :
               return Keystrokes::UP->value;
            }

            $candidate = trim($Line->value);

            // ? Empty answer assumes the default
            if ($candidate === '') {
               // ? Required fields without a default re-ask — alert below the frame
               if ($Field->required === true && $default === '') {
                  $Alert->Type::Failure->set();
                  $Alert->message = 'An answer is required.';
                  $rendered = $Alert->render(self::RETURN_OUTPUT);
                  $alert = is_string($rendered) ? $rendered : '';

                  continue;
               }

               $candidate = $default;
            }

            // ? Validate the candidate answer
            if ($Field->Validator !== null) {
               $result = ($Field->Validator)($candidate);

               if ($result !== true) {
                  $Alert->Type::Failure->set();
                  $Alert->message = (string) $result;
                  $rendered = $Alert->render(self::RETURN_OUTPUT);
                  $alert = is_string($rendered) ? $rendered : '';

                  // ? Attempts exhausted assume the default
                  $attempt++;
                  if ($this->attempts > 0 && $attempt > $this->attempts) {
                     $answer = $default;
                     break;
                  }

                  $Line->reset();

                  continue;
               }
            }

            $answer = $candidate;
            break;
         }

         // @ Edit keys control the buffer; printable input feeds it
         $reverting = false;
         // ? Typing dismisses the validation alert (HTML-form-like)
         $alert = '';

         if ($key[0] === "\e" || $key === "\x7F" || $key < ' ') {
            $Line->control($key);
         }
         else {
            $Line->feed($key);
         }
      }

      $this->erase($height);
      $this->restore();

      // :
      return $answer;
   }

   /**
    * Chooses a Select / Confirm field option inside a live fieldset frame —
    * a radio list aimed with `↑`/`↓` (Confirm adds `y`/`n` hotkeys).
    *
    * @param Field $Field The field to choose.
    * @param string $default The option assumed on empty answer or EOF.
    * @param bool $confirm Whether the field is a Confirm (Yes / No radio).
    *
    * @return string The chosen option — Confirm fields answer `yes` / `no`.
    */
   private function select (Field $Field, string $default, bool $confirm = false): string
   {
      $options = $confirm === true ? ['Yes', 'No'] : $Field->options;

      // ! Option aimed initially — the default when listed, the first otherwise
      $aimed = 0;
      if ($confirm === true) {
         $aimed = $default === 'yes' ? 0 : 1;
      }
      else if ($default !== '') {
         $found = array_search($default, $options, true);
         $aimed = $found === false ? 0 : (int) $found;
      }

      // ! Raw input mode
      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();

      // * Metadata
      $height = 0;

      // @@ Aim until Enter or EOF
      while (true) {
         // @ Compose the radio rows — the aimed option carries the cursor and a filled dot
         $lines = [];
         foreach ($options as $index => $label) {
            $lines[] = $aimed === $index
               ? "@#Cyan:› ● {$label}@;"
               : "  @#Black:○@; {$label}";
         }

         $height = $this->repaint(
            $this->frame($Field->label, $lines, state: 'active'),
            $height
         );

         $key = $this->listen();

         // ? EOF selects the aimed option
         if ($key === false) {
            break;
         }
         // ? Enter selects the aimed option
         if ($key === Keystrokes::ENTER->value || $key === "\r") {
            break;
         }

         match (true) {
            $key === Keystrokes::UP->value && $aimed > 0
               => $aimed--,
            $key === Keystrokes::DOWN->value && $aimed < count($options) - 1
               => $aimed++,
            // ? Confirm hotkeys aim directly
            $confirm === true && strtolower($key) === 'y'
               => $aimed = 0,
            $confirm === true && strtolower($key) === 'n'
               => $aimed = 1,
            default => null
         };
      }

      $this->erase($height);
      $this->restore();

      $answer = $options[$aimed] ?? ($options[0] ?? '');

      // ?: Confirm fields answer `yes` / `no`
      if ($confirm === true) {
         return $aimed === 0 ? 'yes' : 'no';
      }

      // :
      return $answer;
   }

   /**
    * Restores the input settings and the cursor after a raw editor.
    */
   private function restore (): void
   {
      $this->Input->configure(blocking: true, canonical: true, echo: true);
      $this->Output->Cursor->show();
   }

   // # Plain line editors (non-interactive streams)

   /**
    * Asks a Text / Secret field with Question (one stdin line).
    *
    * @param Field $Field The field to ask.
    * @param string $default The value assumed on empty answer or EOF.
    *
    * @return string
    */
   private function question (Field $Field, string $default): string
   {
      $Question = new Question($this->Input, $this->Output);
      // * Config
      $Question->prompt = $Field->label;
      $Question->default = $default;
      $Question->required = $Field->required;
      $Question->attempts = $this->attempts;
      $Question->mask = $Field->mask;
      $Question->Validator = $Field->Validator;

      // :
      return $Question->ask();
   }

   /**
    * Chooses a Select field option with one stdin line — option index, exact
    * label or empty for the default.
    *
    * @param Field $Field The field to choose.
    * @param string $default The option assumed on empty answer or EOF.
    *
    * @return string
    */
   private function choose (Field $Field, string $default): string
   {
      $options = $Field->options;

      // ! Option assumed on empty / invalid answers
      $assumed = $default !== '' ? $default : ($options[0] ?? '');

      $this->Output->write("{$Field->label} [{$assumed}]: ");

      $line = $this->Input->scan();

      $answer = $line === false ? '' : trim($line);

      // ?: Empty answer assumes the default
      if ($answer === '') {
         return $assumed;
      }
      // ?: Option index
      if (is_numeric($answer) === true && isSet($options[(int) $answer]) === true) {
         return $options[(int) $answer];
      }
      // ?: Exact option label
      if (in_array($answer, $options, true) === true) {
         return $answer;
      }

      // : Invalid answers assume the default
      return $assumed;
   }

   /**
    * Confirms a Confirm field with Question (one stdin line).
    *
    * @param Field $Field The field to confirm.
    * @param string $default The answer (`yes` / `no`) assumed on empty answer or EOF.
    *
    * @return string `yes` or `no`.
    */
   private function confirm (Field $Field, string $default): string
   {
      $Question = new Question($this->Input, $this->Output);

      $confirmed = $Question->confirm($Field->label, $default === 'yes');

      // :
      return $confirmed === true ? 'yes' : 'no';
   }
}
