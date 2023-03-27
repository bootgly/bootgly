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

use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\components\Progress\Bar;


class Progress
{
   use Escaping;
   use cursor\Positioning;
   use cursor\Visualizing;
   use text\Modifying;


   private Output $Output;

   // * Config
   // @
   public float $throttle;
   // ---
   public object $Precision;

   // * Data
   // @
   public float $current;
   public float $total;
   // ! Templating
   public string $template;

   // * Meta
   // @ State
   private bool $indetermined;
   private bool $determined;
   // @ Display
   private string $description;
   private float|string $percent;
   private float|string $elapsed;
   private float|string $eta;
   private float|string $rate;
   // ! Templating
   private array $tokens;
   // @ render
   private array $cursor;
   // time
   private float $started;
   private float $rendered;
   private bool $finished;

   public Bar $Bar;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;


      // * Config
      // @
      $this->throttle = 0.1;
      // ---
      $this->Precision = new class {
         public int $seconds = 2;
         public int $percent = 1;
         public int $rate = 0;
      };

      // * Data
      // @
      $this->current = 0.0;
      $this->total = 100;
      // ! Templating
      $this->template = <<<'TEMPLATE'
      @described;
      @current;/@total; [@bar;] @percent;%
      ⏱️ @elapsed;s - 🏁 @eta;s - 📈 @rate; loops/s
      TEMPLATE;

      // * Meta
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
   public function __get ($name)
   {
      return $this->$name;
   }
   public function __set ($name, $value)
   {
      switch ($name) {
         case 'percent':
            $this->percent = $value;
            break;
      }
   }

   private function render ()
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

      // @ Reset cursor position to initial line
      $this->Output->Cursor->moveTo(...$this->cursor);

      // @ Write to output
      $this->Output->write($output);
   }

   public function start ()
   {
      if ($this->started) {
         return;
      }

      $this->started = microtime(true);

      // ---
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

      // @ Parse indetermined
      if ($this->total <= 0) {
         $this->indetermined = true;
         $this->determined = false;
      }
      // ---

      $this->render();
   }

   public function advance (int $amount = 1)
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
      } else {
         $this->percent++;
      }
      // eta
      if ($current > 0) {
         $eta = (($elapsed / $current) * $total) - $elapsed;
      } else {
         $eta = 0.0;
      }
      // rate
      if ($current > 0) {
         $rate = $current / $elapsed;
      } else {
         $rate = 0.0;
      }

      // @ set
      $this->elapsed = $elapsed;
      $this->eta = $eta;
      $this->rate = $rate;

      $this->render();
   }

   public function describe (string $description)
   {
      if ($this->description === $description) {
         return;
      }

      $describedLength = strlen($this->description);

      if (strlen($description) < $describedLength) {
         $description = str_pad($description, $describedLength, ' ', STR_PAD_RIGHT);
      }

      $this->description = CLI\Template::render($description);
   }

   public function finish ()
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
