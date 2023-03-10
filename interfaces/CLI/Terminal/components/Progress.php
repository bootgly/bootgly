<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components;


use Bootgly\CLI;

use Bootgly\CLI\Escaping;
use Bootgly\CLI\Escaping\cursor\Positioning;
use Bootgly\CLI\Escaping\cursor\Visualizing;
use Bootgly\CLI\Escaping\text\Modifying;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


class Progress
{
   use Escaping;
   use Positioning;
   use Visualizing;
   use Modifying;


   private Output $Output;

   // * Config
   // @ Tick
   public float $ticks = 1.0;
   public float $throttle = 0.1;
   // @ Templating
   public string $format;
   // @ Precision
   public int $secondPrecision  = 2;
   public int $percentPrecision = 1;
   // @ Bar
   public int $barUnits;
   // symbols
   public string $symbolComplete   = '=';
   public string $symbolCurrent    = '>';
   public string $symbolIncomplete = ' ';
   public string $symbolUnknown    = '?';
   // * Data
   public float $ticked;
   // * Meta
   public float|string $elapsed;
   public float|string $percent;
   public float|string $eta;
   public float|string $rate;
   // @ Timing
   private float $started;
   private bool $finished;
   private float $rendered;
   // @ Templating
   private array $tokens = ['@ticked;', '@ticks;', '@bar;', '@elapsed;', '@percent;', '@eta;', '@rate;'];


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->ticks = 100;
      // @ Bar
      $this->barUnits = Terminal::$width / 2;
      // @ Templating
      $this->format = <<<'TEMPLATE'
      @ticked;/@ticks;  [@bar;]  @percent;% - Elapsed: @elapsed;s - ETA: @eta;s - Rate: @rate;/s
      TEMPLATE;
      // * Data
      $this->ticked = 0.0;
      // * Meta
      $this->elapsed = 0.0;
      $this->percent = 0.0;
      $this->eta = 0.0;
      $this->rate = 0.0;
      // @ Timing
      $this->started = 0.0;
      $this->finished = false;
   }

   private function render ()
   {
      // @ Timing
      $this->rendered = microtime(true);

      // @ Escaping
      $this->Output->Cursor->moveTo(column: 1);

      // @ Templating
      if (strpos($this->format, '@bar;') !== false) {
         $bar = $this->renderBar();
      }

      $output = str_replace(
         search: $this->tokens,
         replace: [
            $this->ticked,
            $this->ticks,
            // @ Bar
            $bar ?? '',
            // @ Format numbers
            number_format($this->elapsed, $this->secondPrecision, '.', ''),
            number_format($this->percent, $this->percentPrecision, '.', ''),
            number_format($this->eta, $this->secondPrecision, '.', ''),
            number_format($this->rate, 0, '.', ''),
         ],
         subject: $this->format
      );

      // @ Write to output
      $this->Output->write(
         $output . self::_START_ESCAPE . self::_TEXT_DELETE_CHARACTER
      );
   }
   // TODO move to Progress/Bar
   public function renderBar() : string
   {
      $units = $this->barUnits;

      // @ done
      $done = $units * ($this->percent / 100);
      if ($done > $units) {
         $done = $units;
      }
      // @ left
      $left = $units - $done;

      // @ Construct symbols
      // incomplete
      $symbolsIncomplete = [];
      for ($i = 0; $i < $left; $i++) {
         $symbolsIncomplete[] = $this->symbolIncomplete;
      }
      // complete
      $symbolsComplete = [];
      if ($this->ticks <= 0) {
         for ($i = 0; $i < $done; $i++) {
            $symbolsComplete[] = $this->symbolIncomplete;
         }
      } else {
         for ($i = 0; $i < $done; $i++) {
            $symbolsComplete[] = $this->symbolComplete;
         }
      }
      // current
      $symbolCurrent = $this->symbolCurrent;

      $bar = implode('', $symbolsComplete) . $symbolCurrent . implode('', $symbolsIncomplete);

      return $bar;
   }
   
   private function getSymbols($symbol, $count) {
      return array_fill(0, $count, $symbol);
   }

   public function start ()
   {
      // * Meta
      $this->started = microtime(true);

      // @ Init the display
      $this->Output->Cursor->hide();
      $this->Output->Cursor->moveTo(column: 1);

      // @ Set the start time
      $this->rendered = microtime(true);

      $this->render();
   }

   public function tick (int $amount = 1)
   {
      $ticked = $this->ticked += $amount;
      $ticks = $this->ticks;

      if (microtime(true) - $this->rendered < $this->throttle) {
         return;
      }

      // @ Calculate
      // elapsed
      $elapsed = microtime(true) - $this->started;
      // percent
      if ($ticks > 0) {
         $percent = ($ticked / $ticks) * 100;
      } else {
         $percent = $ticked;
      }
      // eta
      if ($ticked > 0) {
         $eta = (($elapsed / $ticked) * $ticks) - $elapsed;
      } else {
         $eta = 0.0;
      }
      // rate
      if ($ticked > 0) {
         $rate = $ticked / $elapsed;
      } else {
         $rate = 0.0;
      }

      // @ Set
      $this->elapsed = $elapsed;
      $this->percent = $percent;
      $this->eta = $eta;
      $this->rate = $rate;

      $this->render();
   }

   public function finish ()
   {
      if ($this->finished) {
         return;
      }

      $this->finished = true;

      // TODO Check unknown symbol in ETA
      // TODO Check whether the last rendered showed the completed progress (when using throttle).

      $this->Output
         ->escape(self::_CURSOR_VISIBLE)
         ->write("\n");
   }

   public function __destruct()
   {
      $this->finish();
   }
}
