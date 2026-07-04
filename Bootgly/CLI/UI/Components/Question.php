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


use function is_string;
use function rewind;
use function stream_get_contents;
use function trim;
use Closure;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
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

   // * Metadata
   public private(set) string $answer;
   public private(set) int $attempt;


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

      // * Metadata
      $this->answer = '';
      $this->attempt = 0;
   }


   protected function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      // * Config
      $prompt = $this->prompt;
      $default = $this->default;

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

         $line = $this->Input->scan();

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
}
