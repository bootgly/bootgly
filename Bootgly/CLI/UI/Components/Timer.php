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
use function max;
use function microtime;
use function min;
use function number_format;
use function str_pad;
use function strlen;
use function strtr;
use function substr_count;
use function usleep;
use Closure;

use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


/**
 * Countdown timer with a completion callback.
 * The remaining time always derives from the wall clock (never tick counts), so
 * `usleep` drift can never desynchronize it. Non-interactive output renders the
 * initial and the final frames only.
 */
class Timer extends Component
{
   private Output $Output;

   // * Config
   /** Countdown total, in seconds */
   public float $seconds;
   public float $throttle;
   public int $precision;
   /** Invoked once when the countdown reaches zero */
   public null|Closure $Handler;
   // # Templating
   public string $template;

   // * Metadata
   public private(set) string $description;
   public private(set) float $remaining;
   public private(set) float $elapsed;
   public private(set) float $percent;
   // # Time
   public private(set) float $started;
   public private(set) float $rendered;
   public private(set) bool $finished;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;

      // * Config
      $this->seconds = 0.0;
      $this->throttle = 0.1;
      $this->precision = 2;
      $this->Handler = null;
      // # Templating
      $this->template = '⏳ @remaining;s @description;';

      // * Metadata
      $this->description = '';
      $this->remaining = 0.0;
      $this->elapsed = 0.0;
      $this->percent = 0.0;
      // # Time
      $this->started = 0.0;
      $this->rendered = 0.0;
      $this->finished = false;
   }


   protected function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      $this->rendered = microtime(true);

      // ! Templating
      $precision = $this->precision;

      $output = strtr($this->template, [
         '@description;' => $this->description,
         '@remaining;' => number_format($this->remaining, $precision, '.', ''),
         '@elapsed;' => number_format($this->elapsed, $precision, '.', ''),
         '@percent;' => number_format($this->percent, 0, '.', '')
      ]);

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $output;
      }

      $this->Output->write("{$output}\n");

      return null;
   }

   /**
    * Starts the countdown (reserves the frame lines and hides the cursor).
    *
    * @param string $description The countdown description.
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
      $this->remaining = $this->seconds;

      if ($description !== '') {
         $this->describe($description);
      }

      // ? Non-interactive output renders the initial frame once
      if (BOOTGLY_TTY === false) {
         $this->render();

         return;
      }

      // @ Reserve the frame lines and hide the cursor
      $lines = substr_count($this->template, "\n") + 1;
      $this->Output->expand($lines);
      $this->Output->Cursor->hide();

      $this->render();
   }

   /**
    * Recomputes the remaining time from the wall clock and repaints (throttled).
    * Reaching zero finishes the countdown and invokes the Handler.
    *
    * @return void
    */
   public function tick (): void
   {
      // ?
      if ($this->started === 0.0 || $this->finished === true) {
         return;
      }

      // @ Wall clock — usleep drift never desynchronizes the countdown
      $this->elapsed = microtime(true) - $this->started;
      $this->remaining = max(0.0, $this->seconds - $this->elapsed);
      $this->percent = $this->seconds > 0.0
         ? min(100.0, ($this->elapsed / $this->seconds) * 100)
         : 100.0;

      // ? Zero finishes the countdown
      if ($this->remaining <= 0.0) {
         $this->finish();

         return;
      }

      // ? Throttle; non-interactive output never repaints
      if (BOOTGLY_TTY === false || microtime(true) - $this->rendered < $this->throttle) {
         return;
      }

      // @ Repaint relatively (pipe-safe: no absolute cursor position involved)
      $lines = substr_count($this->template, "\n") + 1;
      $this->Output->Cursor->up($lines, column: 1);
      $this->Output->Text->clear(down: true);

      $this->render();
   }

   /**
    * Runs the countdown synchronously until it finishes.
    *
    * @param string $description The countdown description.
    *
    * @return void
    */
   public function run (string $description = ''): void
   {
      $this->start($description);

      // @@ Tick until zero
      while ($this->finished === false) {
         usleep((int) ($this->throttle * 1_000_000));

         $this->tick();
      }
   }

   /**
    * Updates the countdown description (shorter texts pad-clear the previous one).
    *
    * @param string $description The countdown description.
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
    * Finishes the countdown: forces the final frame and invokes the Handler once.
    *
    * @return void
    */
   public function finish (): void
   {
      // ?
      if ($this->finished === true || $this->started === 0.0) {
         return;
      }

      $this->finished = true;

      // ! Final frame values
      $this->elapsed = microtime(true) - $this->started;
      $this->remaining = 0.0;
      $this->percent = 100.0;

      // @ Force the final frame
      if (BOOTGLY_TTY === true) {
         $lines = substr_count($this->template, "\n") + 1;
         $this->Output->Cursor->up($lines, column: 1);
         $this->Output->Text->clear(down: true);
      }

      $this->render();

      if (BOOTGLY_TTY === true) {
         $this->Output->Cursor->show();
      }

      // @ Completion callback
      if ($this->Handler !== null) {
         ($this->Handler)($this);
      }
   }

   public function __destruct ()
   {
      $this->finish();
   }
}
