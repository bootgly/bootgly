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
use const STR_PAD_RIGHT;
use function count;
use function microtime;
use function str_pad;
use function strlen;
use function strtr;

use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


/**
 * Indeterminate activity indicator — tick-driven (the caller loop drives `spin()`),
 * no process forking. Non-interactive output renders the description once and the
 * resolution line at the end.
 */
class Spinner extends Component
{
   private Output $Output;

   // * Config
   /** @var array<string> Animation frames */
   public array $frames;
   public float $throttle;
   // # Templating
   public string $template;

   // * Metadata
   public private(set) int $frame;
   public private(set) string $description;
   // # Time
   public private(set) float $started;
   public private(set) float $rendered;
   public private(set) bool $finished;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;

      // * Config
      $this->frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
      $this->throttle = 0.08;
      // # Templating
      $this->template = '@spinner; @description;';

      // * Metadata
      $this->frame = 0;
      $this->description = '';
      // # Time
      $this->started = 0.0;
      $this->rendered = 0.0;
      $this->finished = false;
   }


   protected function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      $this->rendered = microtime(true);

      // ! Templating
      $output = strtr($this->template, [
         '@spinner;' => $this->frames[$this->frame % count($this->frames)],
         '@description;' => $this->description
      ]);

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $output;
      }

      $this->Output->write("{$output}\n");

      return null;
   }

   /**
    * Starts the spinner (reserves its line and hides the cursor).
    *
    * @param string $description The activity description.
    *
    * @return void
    */
   public function start (string $description = ''): void
   {
      // ?
      if ($this->started > 0.0) {
         return;
      }

      $this->started = microtime(true);

      if ($description !== '') {
         $this->describe($description);
      }

      // ? Non-interactive output renders the description once
      if (BOOTGLY_TTY === false) {
         $this->Output->write("{$this->description}\n");

         return;
      }

      // @ Reserve the spinner line and hide the cursor
      $this->Output->expand(1);
      $this->Output->Cursor->hide();

      $this->render();
   }

   /**
    * Advances the animation (throttled) — call it from the working loop.
    *
    * @return void
    */
   public function spin (): void
   {
      // ? Non-interactive output never animates
      if (BOOTGLY_TTY === false || $this->started === 0.0 || $this->finished === true) {
         return;
      }

      // ? Throttle
      if (microtime(true) - $this->rendered < $this->throttle) {
         return;
      }

      $this->frame++;

      // @ Repaint relatively (pipe-safe: no absolute cursor position involved)
      $this->Output->Cursor->up(1, column: 1);
      $this->Output->Text->clear(down: true);

      $this->render();
   }

   /**
    * Updates the activity description (shorter texts pad-clear the previous one).
    *
    * @param string $description The activity description.
    *
    * @return void
    */
   public function describe (string $description): void
   {
      // ?
      if ($this->description === $description) {
         return;
      }

      $length = strlen($this->description);

      if (strlen($description) < $length) {
         $description = str_pad($description, $length, ' ', STR_PAD_RIGHT);
      }

      $this->description = TemplateEscaped::render($description);
   }

   /**
    * Finishes the spinner with a resolution line (e.g. `✔ done`).
    *
    * @param string $resolution The final line — empty keeps the last frame.
    *
    * @return void
    */
   public function finish (string $resolution = ''): void
   {
      // ?
      if ($this->finished === true || $this->started === 0.0) {
         return;
      }

      $this->finished = true;

      // ? Non-interactive output renders the resolution line only
      if (BOOTGLY_TTY === false) {
         if ($resolution !== '') {
            $this->Output->render("{$resolution}\n");
         }

         return;
      }

      // @ Replace the spinner line with the resolution
      if ($resolution !== '') {
         $this->Output->Cursor->up(1, column: 1);
         $this->Output->Text->clear(down: true);
         $this->Output->render("{$resolution}\n");
      }

      $this->Output->Cursor->show();
   }

   public function __destruct ()
   {
      $this->finish();
   }
}
