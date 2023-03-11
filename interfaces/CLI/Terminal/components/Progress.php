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
use Bootgly\CLI\Escaping\cursor;
use Bootgly\CLI\Escaping\text;

use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


class Progress
{
   use Escaping;
   use cursor\Positioning;
   use cursor\Visualizing;
   use text\Modifying;


   private Output $Output;

   // * Config
   // @ Tick
   public float $ticks;
   public float $throttle;
   // @ Templating
   public string $template;
   // @ Precision
   public int $secondPrecision;
   public int $percentPrecision;
   // ! Bar
   // units
   public int $barUnits;
   // symbols
   // TODO convert to array?
   public array $barSymbols;
   // * Data
   // @ Tick
   public float $ticked;
   // * Meta
   // @ Cursor
   private int $row;
   // @ Timing
   private float $started;
   private float $rendered;
   private bool $finished;
   // @ Display
   private float|string $elapsed;
   private float|string $percent;
   private float|string $eta;
   private float|string $rate;
   // @ Templating
   private array $tokens;
   private array $lines;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;

      // * Config
      // @ Tick
      $this->ticks = 100;
      $this->throttle = 0.1;
      // @ Templating
      $this->template = <<<'TEMPLATE'
      @ticked;/@ticks; [@bar;] @percent;% - Elapsed: @elapsed;s - ETA: @eta;s - Rate: @rate;/s
      TEMPLATE;
      // @ Precision
      $this->secondPrecision = 2;
      $this->percentPrecision = 1;
      // ! Bar
      // units
      $this->barUnits = Terminal::$width / 2;
      // symbols
      $this->barSymbols = [
         'determined'   => ['=', '>', ' '], // determined
         'indetermined' => ['?']            // indetermined
      ];
      // * Data
      // @ Tick
      $this->ticked = 0.0;
      // * Meta
      // @ Cursor
      $this->row = 0;
      // @ Timing
      $this->started = 0.0;
      $this->rendered = 0.0;
      $this->finished = false;
      // @ Display
      $this->elapsed = 0.0;
      $this->percent = 0.0;
      $this->eta = 0.0;
      $this->rate = 0.0;
      // @ Templating
      $this->tokens = ['@ticked;', '@ticks;', '@bar;', '@elapsed;', '@percent;', '@eta;', '@rate;'];
      $this->lines = [];
   }

   private function render ()
   {
      // @ Timing
      $this->rendered = microtime(true);

      // @ Templating
      if (strpos($this->template, '@bar;') !== false) {
         $bar = $this->renderBar();
      }

      // @ Prepare values
      $tokens = $this->tokens;

      $ticked = $this->ticked;
      $ticks = (int) $this->ticks;

      $elapsed = number_format($this->elapsed, $this->secondPrecision, '.', '');
      $percent = number_format($this->percent, $this->percentPrecision, '.', '');
      $eta = number_format($this->eta, $this->secondPrecision, '.', '');
      $rate = number_format($this->rate, 0, '.', '');

      // @ Write each line to output
      $output = str_replace(
         search: $tokens,
         replace: [
            $ticked,
            $ticks,

            $bar ?? '',

            $elapsed,
            $percent,
            $eta,
            $rate
         ],
         subject: $this->template
      );

      // @ Move cursor to line
      $this->Output->Cursor->moveTo(line: $this->row, column: 1);

      // @ Write to output
      $this->Output->write($output);
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
      $symbols = $this->barSymbols['determined'];
      // incomplete
      $incomplete = $symbols[0];

      $symbolsIncomplete = [];

      for ($i = 0; $i < $left; $i++) {
         $symbolsIncomplete[] = $incomplete;
      }
      // current
      // ...
      // complete
      $symbolsComplete = [];

      if ($this->ticks <= 0) {
         for ($i = 0; $i < $done; $i++) {
            $symbolsComplete[] = $incomplete;
         }
      } else {
         for ($i = 0; $i < $done; $i++) {
            $symbolsComplete[] = $symbols[2];
         }
      }

      $bar = implode('', $symbolsComplete) . $symbols[1] . implode('', $symbolsIncomplete);

      return $bar;
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

      // @ Format Template EOL
      $this->template = str_replace("\n", "   \n", $this->template);
      $this->template .= "   \n\n";

      // @ Get/Set the current Cursor position row
      $this->row = ($this->Output->Cursor->position['row'] ?? 0);

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

      $this->Output->Cursor->show();
   }

   public function __destruct()
   {
      $this->finish();
   }
}
