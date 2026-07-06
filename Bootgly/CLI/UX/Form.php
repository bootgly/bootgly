<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UX;


use const BOOTGLY_TTY;
use function array_search;
use function count;
use function in_array;
use function is_numeric;
use function rewind;
use function rtrim;
use function str_repeat;
use function stream_get_contents;
use function trim;
use Closure;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Fieldset;
use Bootgly\CLI\UI\Components\Menu;
use Bootgly\CLI\UI\Components\Question;
use Bootgly\CLI\UX\Form\Controls;
use Bootgly\CLI\UX\Form\Field;
use Bootgly\CLI\UX\Form\Fields;


class Form extends Component
{
   public Input $Input;
   public Output $Output;

   // * Config
   public string $title;
   /** Per-field attempts forwarded to Question (0 = unlimited) */
   public int $attempts;

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
    * Interactive terminals support revert (`↑` then Enter goes back one field) and end
    * with a summary + confirm loop (edit any field before submitting). Non-interactive
    * streams read one stdin line per field, deterministically — no revert, no summary.
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

            continue;
         }

         $this->answers[$Field->label] = $Field->answer;
         $index++;
      }

      // @@ Summary + confirm loop (edit any field before submitting)
      while (true) {
         // ? The summary frame breathes: blank line before it
         $this->Output->write("\n");

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
    * Edits a field with its control editor (Question or Menu).
    *
    * @param Field $Field The field to edit.
    *
    * @return string The captured answer — or the revert sentinel (`\e[A`), not recorded.
    */
   private function edit (Field $Field): string
   {
      // ! Previous answer becomes the default when re-editing
      $default = $Field->answered === true ? $Field->answer : $Field->default;

      $answer = match ($Field->Control) {
         Controls::Text, Controls::Secret => $this->question($Field, $default),
         Controls::Select => $this->choose($Field, $default),
         Controls::Confirm => $this->confirm($Field, $default)
      };

      // ?: The revert sentinel is not an answer
      if ($answer === Keystrokes::UP->value) {
         return $answer;
      }

      $Field->update($answer);

      // :
      return $answer;
   }

   /**
    * Asks a Text / Secret field with Question.
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

      // ? The revert sentinel must bypass the field Validator
      $Validator = $Field->Validator;
      if ($Validator !== null) {
         $Question->Validator = static fn (string $answer): bool|string
            => $answer === Keystrokes::UP->value ? true : $Validator($answer);
      }

      // :
      return $Question->ask();
   }

   /**
    * Chooses a Select field option with Menu (interactive) or one stdin line (pipes).
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

      // ? Non-TTY: one stdin line — option index, exact label or empty for the default
      if (BOOTGLY_TTY === false) {
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

      // ! Interactive Menu — unique selection, aim starts at the default option
      // ? Block editors breathe: blank line before and after the Menu frame
      $this->Output->write("\n");

      $Menu = new Menu($this->Input, $this->Output);
      $Menu->prompt = "@#Cyan:{$Field->label}@;\n@#Black:(↑/↓ to move, Enter to confirm)@;\n";

      $Options = $Menu->Items->Options;
      $Options->Selection::Unique->set();

      foreach ($options as $label) {
         $Options->add(label: $label);
      }

      $aimed = array_search($assumed, $options, true);
      if ($aimed !== false) {
         $Options->aim((int) $aimed);
      }

      // @@ Render until Enter
      foreach ($Menu->rendering() as $ignored);

      $this->Output->write("\n");

      $index = (int) ($Menu->selected[0] ?? 0);

      // :
      return $options[$index] ?? $assumed;
   }

   /**
    * Confirms a Confirm field with Question.
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
