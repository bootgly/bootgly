<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Atoms;


use const BOOTGLY_TTY;
use function count;
use function mb_str_split;
use function microtime;
use function usleep;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Atoms\Text\Effects;


/**
 * Animated text effects. `Typewriter` and `Fade` are one-shot (`play()`); `Shimmer`
 * — a color wave passing letter by letter, left to right — is continuous and
 * tick-driven (`start()`/`tick()`/`finish()`). Non-interactive output always renders
 * the final frame only.
 */
class Text extends Component
{
   /** Shimmer highlight window, in characters */
   protected const int WAVE = 4;


   private Output $Output;

   // * Config
   public Effects $Effects;
   /** Microseconds per animation step */
   public int $interval;

   // * Data
   public string $content;

   // * Metadata
   public private(set) int $frame;
   // # Time
   public private(set) float $started;
   public private(set) float $rendered;
   public private(set) bool $finished;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->Effects = Effects::Typewriter;
      $this->interval = 30_000;

      // * Data
      $this->content = '';

      // * Metadata
      $this->frame = 0;
      // # Time
      $this->started = 0.0;
      $this->rendered = 0.0;
      $this->finished = false;
   }


   /**
    * Renders the final frame (the plain content line).
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return mixed
    */
   protected function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return "{$this->content}\n";
      }

      $this->Output->write("{$this->content}\n");

      return null;
   }

   /**
    * Plays a one-shot effect (Typewriter or Fade) synchronously.
    * Non-interactive output renders the final frame only.
    *
    * @return void
    */
   public function play (): void
   {
      // ? Non-interactive output renders the final frame only
      if (BOOTGLY_TTY === false || $this->Effects === Effects::Shimmer) {
         $this->render();

         return;
      }

      match ($this->Effects) {
         Effects::Typewriter => $this->type(),
         default => $this->fade()
      };
   }

   /**
    * Starts the continuous Shimmer effect (tick-driven).
    *
    * @return void
    */
   public function start (): void
   {
      // ?
      if ($this->started > 0.0) {
         return;
      }

      $this->started = microtime(true);

      // ? Non-interactive output renders the final frame only
      if (BOOTGLY_TTY === false) {
         $this->render();

         return;
      }

      $this->Output->Cursor->hide();
      $this->Output->write("{$this->content}\n");
   }

   /**
    * Advances the Shimmer wave one step (throttled) — call it from the waiting loop.
    *
    * @return void
    */
   public function tick (): void
   {
      // ? Non-interactive output never animates
      if (BOOTGLY_TTY === false || $this->started === 0.0 || $this->finished === true) {
         return;
      }

      // ? Throttle
      if (microtime(true) - $this->rendered < ($this->interval / 1_000_000)) {
         return;
      }

      $this->rendered = microtime(true);
      $this->frame++;

      // ! Shimmer frame — a bright window slides over the dimmed content
      $characters = mb_str_split($this->content);
      $count = count($characters);
      $head = $this->frame % ($count + self::WAVE);

      $line = '';
      foreach ($characters as $index => $character) {
         $line .= $index >= $head - self::WAVE && $index < $head
            ? "@#White:{$character}@;"
            : "@#Black:{$character}@;";
      }

      // @ Repaint relatively (pipe-safe)
      $this->Output->Cursor->up(1, column: 1);
      $this->Output->Text->clear(down: true);
      $this->Output->render("{$line}\n");
   }

   /**
    * Finishes the Shimmer effect with the final plain frame.
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

      // ? Non-interactive output already rendered the final frame
      if (BOOTGLY_TTY === false) {
         return;
      }

      // @ Final plain frame
      $this->Output->Cursor->up(1, column: 1);
      $this->Output->Text->clear(down: true);
      $this->render();

      $this->Output->Cursor->show();
   }

   /**
    * Types the content one character at a time.
    */
   private function type (): void
   {
      // @@
      foreach (mb_str_split($this->content) as $character) {
         $this->Output->write($character);

         usleep($this->interval);
      }

      $this->Output->write("\n");
   }

   /**
    * Fades the content in: dim → normal → bold, repainted in place.
    */
   private function fade (): void
   {
      // ! SGR ramp
      $ramp = [
         "@#Black:{$this->content}@;",
         "{$this->content}",
         "@*:{$this->content}@;"
      ];

      // @@
      $painted = false;
      foreach ($ramp as $step) {
         if ($painted === true) {
            $this->Output->Cursor->up(1, column: 1);
            $this->Output->Text->clear(down: true);
         }

         $this->Output->render("{$step}\n");
         $painted = true;

         usleep($this->interval * 6);
      }
   }
}
