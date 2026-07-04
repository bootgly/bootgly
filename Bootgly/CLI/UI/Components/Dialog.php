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
use function rewind;
use function stream_get_contents;
use function strtolower;
use function trim;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Alert;


class Dialog extends Component
{
   public Input $Input;
   public Output $Output;

   // * Config
   public string $message;
   public bool $default;

   // * Data
   public string $suffix;

   // * Metadata
   public private(set) null|bool $confirmed;
   public private(set) string $answer;


   public function __construct (Input &$Input, Output &$Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->message = '';
      $this->default = false;

      // * Data
      $this->suffix = '';

      // * Metadata
      $this->confirmed = null;
      $this->answer = '';
   }


   protected function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      // * Config
      $message = $this->message;
      // * Data
      $suffix = $this->suffix;

      // @
      if ($mode === self::RETURN_OUTPUT) {
         $Output = new Output('php://memory');
      }
      $Output ??= $this->Output;
      // ---
      $Output->write("{$message}{$suffix}");

      if ($mode === self::RETURN_OUTPUT) {
         rewind($Output->stream);
         $output = stream_get_contents($Output->stream);
         return $output;
      }

      return null;
   }

   /**
    * Renders an alert message and, on interactive terminals, waits for Enter.
    *
    * @param string $message The message to display.
    *
    * @return void
    */
   public function alert (string $message): void
   {
      // ! Alert component
      $Alert = new Alert($this->Output);
      $Alert->Type::Attention->set();
      $Alert->message = $message;
      // @
      $Alert->render();

      // ? Non-interactive terminals do not wait for acknowledgement
      if (BOOTGLY_TTY === false) {
         return;
      }

      $this->message = 'Press Enter to continue...';
      $this->suffix = '';
      $this->render();

      $this->Input->scan();
   }

   /**
    * Asks for a yes/no confirmation.
    *
    * @param string $message The confirmation message.
    * @param bool $default The value assumed on empty answer or EOF.
    *
    * @return bool
    */
   public function confirm (string $message, bool $default = false): bool
   {
      // * Config
      $this->message = $message;
      $this->default = $default;
      // * Data
      $this->suffix = $default ? ' [Y/n] ' : ' [y/N] ';

      // ! Answer assumed on EOF, empty or non-interactive fallback
      $confirmed = $default;

      // @@ Ask until a valid answer, EOF or non-interactive fallback
      while (true) {
         $this->render();

         $line = $this->Input->scan();

         // ? EOF assumes the default
         if ($line === false) {
            $confirmed = $default;
            break;
         }

         $answer = strtolower(trim($line));

         // ? Empty answer assumes the default
         if ($answer === '') {
            $confirmed = $default;
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
            $confirmed = $default;
            break;
         }
      }

      $this->confirmed = $confirmed;

      // :
      return $confirmed;
   }

   /**
    * Prompts for a raw, unvalidated answer (validated input is Question's job).
    *
    * @param string $message The prompt message.
    * @param string $default The value assumed on empty answer or EOF.
    *
    * @return string
    */
   public function prompt (string $message, string $default = ''): string
   {
      // * Config
      $this->message = $message;
      // * Data
      $this->suffix = $default !== '' ? " [{$default}]: " : ': ';

      // @
      $this->render();

      $line = $this->Input->scan();

      // ? EOF assumes the default
      if ($line === false) {
         $this->answer = $default;

         // :
         return $this->answer;
      }

      $answer = trim($line);

      // ?: Empty answer assumes the default
      $this->answer = $answer === '' ? $default : $answer;

      // :
      return $this->answer;
   }
}
