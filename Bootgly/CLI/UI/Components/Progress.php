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


use const STR_PAD_RIGHT;
use function microtime;
use function number_format;
use function str_pad;
use function str_replace;
use function strlen;
use function strpos;
use function strtr;
use function substr_count;

use Bootgly\ABI\Data\__String\Escapeable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Positionable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Visualizable;
use Bootgly\ABI\Data\__String\Escapeable\Text\Modifiable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Progress\Bar;
use Bootgly\CLI\UI\Components\Progress\Precision;


class Progress extends Component
{
   use Escapeable;
   use Positionable;
   use Visualizable;
   use Modifiable;


   private Output $Output;

   // * Config
   public float $throttle;
   // ---
   public Precision $Precision;

   // * Data
   public float $current;
   public float $total;
   public float $percent;
   // # Templating
   public string $template;

   // * Metadata
   public private(set) string $output;
   // # State
   public private(set) bool $indetermined;
   public private(set) bool $determined;
   // # Display
   public private(set) string $description;
   public private(set) float $elapsed;
   public private(set) float $eta;
   public private(set) float $rate;
   // # Templating
   /** @var array<string> */
   public private(set) array $tokens;
   // @ render
   /** @var array<int> */
   public private(set) array $cursor;
   // time
   public private(set) float $started;
   public private(set) float $rendered;
   public private(set) bool $finished;

   public Bar $Bar;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;


      // * Config
      $this->throttle = 0.1;
      // ---
      $this->Precision = new Precision;

      // * Data
      $this->current = 0.0;
      $this->total = 100;
      // ! Templating
      $this->template = <<<'TEMPLATE'
      @described;
      @current;/@total; [@bar;] @percent;%
      ⏱️ @elapsed;s - 🏁 @eta;s - 📈 @rate; loops/s
      TEMPLATE;

      // * Metadata
      $this->output = '';
      // @ State
      $this->indetermined = false;
      $this->determined = true;
      // @ Display
      $this->description = '';
      $this->percent = 0.0;
      $this->elapsed = 0.0;
      $this->eta = 0.0;
      $this->rate = 0.0;
      // ! Templating
      $this->tokens = ['@description;', '@current;', '@total;', '@bar;', '@percent;', '@elapsed;', '@eta;', '@rate;'];
      // @ render
      $this->cursor = [0, 1];
      // time
      $this->started = 0.0;
      $this->rendered = 0.0;
      $this->finished = false;


      $this->Bar = new Bar($this);
   }
   public function __clone ()
   {
      $this->current = 0;
      $this->output = '';

      $this->percent = 0.0;
      $this->elapsed = 0.0;
      $this->eta = 0.0;
      $this->rate = 0.0;

      // ! Bar
      // * Config
      $units = $this->Bar->units;
      // * Data
      $Symbols = $this->Bar->Symbols;
      // @ new
      $this->Bar = new Bar($this);
      $this->Bar->units = $units;
      $this->Bar->Symbols = $Symbols;
   }

   protected function render (int $mode = self::WRITE_OUTPUT): void
   {
      $this->rendered = microtime(true);

      // ! Templating
      // @ Prepare values
      // description
      if (strpos($this->template, '@description;') !== false) {
         $description = $this->description;
      }
      // current
      $current = $this->current;
      // total
      if ($this->determined) {
         $total = (int) $this->total;
      }
      // bar
      if (strpos($this->template, '@bar;') !== false) {
         $bar = $this->Bar->render();
      }
      // ---
      $Precision = $this->Precision;
      // percent
      if ($this->determined) {
         $percent = number_format($this->percent, $Precision->percent, '.', '');
      }
      // elapsed
      $elapsed = number_format($this->elapsed, $Precision->seconds, '.', '');
      // eta
      $eta = number_format($this->eta, $Precision->seconds, '.', '');
      // rate
      $rate = number_format($this->rate, $Precision->rate, '.', '');

      // @ Replace tokens by strings
      $output = strtr($this->template, [
         '@description;' => $description ?? '',
         '@current;' => $current,
         '@total;' => $total ?? '?',
         '@bar;' => $bar ?? '',
         '@percent;' => $percent ?? '?',
         '@elapsed;' => $elapsed,
         '@eta;' => $eta,
         '@rate;' => $rate
      ]);

      switch ($this->render) {
         case self::RETURN_OUTPUT:
            $this->output = $output;
            break;
         default:
            // @ Reset cursor position to initial line
            $this->Output->Cursor->moveTo(line: $this->cursor[0], column: $this->cursor[1]);

            // @ Write to output
            $this->Output->write($output);
      }
   }

   public function start (): void
   {
      if ($this->started) {
         return;
      }

      $this->started = microtime(true);

      // ---
      if ($this->render === self::WRITE_OUTPUT) {
         // @ Make vertical space for writing
         $lines = substr_count($this->template, "\n") + 2;
         $this->Output->expand($lines);

         // @ Hide cursor
         $this->Output->Cursor->hide();

         // @ Format Template
         // TODO add support to render in multi columns
         // EOL
         $this->template = str_replace("\n", "   \n", $this->template);
         $this->template .= "   \n\n";

         // @ Set the current Cursor position
         $this->cursor = $this->Output->Cursor->position;
      }

      // @ Parse indetermined
      if ($this->total <= 0) {
         $this->indetermined = true;
         $this->determined = false;
      }
      // ---

      $this->render();
   }

   public function advance (int $amount = 1): void
   {
      $current = $this->current += $amount;

      // ! Templating
      // @ render
      // time
      $last = microtime(true) - $this->rendered;
      if ($last < $this->throttle) {
         return;
      }

      $total = $this->total;

      // @ calculate
      // elapsed
      $elapsed = microtime(true) - $this->started;
      // percent
      if ($total > 0) {
         $this->percent = ($current / $total) * 100;
      }
      else {
         $this->percent++;
      }
      // eta
      if ($current > 0) {
         $eta = (($elapsed / $current) * $total) - $elapsed;
      }
      else {
         $eta = 0.0;
      }
      // rate
      if ($current > 0) {
         $rate = $current / $elapsed;
      }
      else {
         $rate = 0.0;
      }

      // @ set
      $this->elapsed = $elapsed;
      $this->eta = $eta;
      $this->rate = $rate;

      $this->render();
   }

   public function describe (string $description): void
   {
      if ($this->description === $description) {
         return;
      }

      $describedLength = strlen($this->description);

      if (strlen($description) < $describedLength) {
         $description = str_pad($description, $describedLength, ' ', STR_PAD_RIGHT);
      }

      $this->description = TemplateEscaped::render($description);
   }

   public function finish (): void
   {
      if ($this->finished) {
         return;
      }

      $this->finished = true;

      // @ Complete progress (when using throttle and the progress can be determined)
      if ($this->throttle > 0.0 && $this->percent < 100 && $this->determined) {
         $this->elapsed = microtime(true) - $this->started;
         $this->percent = 100;
         $this->eta = 0.0;

         $this->render();
      }

      $this->Output->Cursor->show();
   }

   public function __destruct ()
   {
      $this->finish();
   }
}
