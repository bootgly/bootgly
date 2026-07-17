<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function rewind;
use function stream_get_contents;
use function substr_count;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Timeline\States;
use Bootgly\CLI\UI\Components\Timeline\Step;
use Bootgly\CLI\UI\Components\Timeline\Steps;


/**
 * Multi-step guided flow with per-step state (pending / active / done / failed).
 * Interactive terminals repaint the vertical timeline in place; non-interactive
 * output appends one plain line per transition (CI-log friendly).
 */
class Timeline extends Component
{
   private Output $Output;

   // * Config
   /** @var array<string,string> State glyphs */
   public array $glyphs;
   /**
    * Append-only transitions (one plain line each) even on interactive terminals —
    * for flows that write output between steps, where in-place repaints would corrupt
    */
   public bool $append;
   /** Render steps only from this index (null = first) — for split-frame consumers */
   public null|int $from;
   /** Render steps only up to this index (null = last) — for split-frame consumers */
   public null|int $until;

   // * Data
   public Steps $Steps;

   // * Metadata
   /** Lines of the last painted frame (0 before the first paint) */
   private int $height;
   public private(set) bool $finished;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->glyphs = [
         'pending' => '○',
         'active'  => '◉',
         'done'    => '✔',
         'failed'  => '✖'
      ];
      $this->append = false;
      $this->from = null;
      $this->until = null;

      // * Data
      $this->Steps = new Steps;

      // * Metadata
      $this->height = 0;
      $this->finished = false;
   }


   /**
    * Adds a step to the timeline.
    *
    * @param string $label The step label.
    *
    * @return Step
    */
   public function add (string $label): Step
   {
      // :
      return $this->Steps->add($label);
   }

   /**
    * Renders the timeline frame.
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string The markup frame when returning — null when writing.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ! Vertical frame — one glyph + label per step, connected by `│`
      $frame = '';
      $count = $this->Steps->count;

      // ? Sliced frames render the [$from, $until] range only (split-frame consumers)
      $start = $this->from ?? 0;
      $limit = $this->until !== null && $this->until < $count - 1
         ? $this->until
         : $count - 1;

      foreach ($this->Steps->Steps as $index => $Step) {
         if ($index < $start) {
            continue;
         }
         if ($index > $limit) {
            break;
         }

         $note = $Step->note !== '' ? " @#Black:({$Step->note})@;" : '';

         $frame .= match ($Step->State) {
            States::Pending => "@#Black:{$this->glyphs['pending']} {$Step->label}@;",
            States::Active  => "@#Cyan:{$this->glyphs['active']} {$Step->label}@;{$note}",
            States::Done    => "@#Green:{$this->glyphs['done']}@; {$Step->label}{$note}",
            States::Failed  => "@#Red:{$this->glyphs['failed']} {$Step->label}@;{$note}"
         };
         $frame .= "\n";

         // ? Connector between steps
         if ($index < $limit) {
            $frame .= "@#Black:│@;\n";
         }
      }

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $frame;
      }

      // @ Repaint relatively over the previous frame
      if ($this->height > 0) {
         $this->Output->Cursor->up($this->height, column: 1);
         $this->Output->Text->clear(lines: $this->height);
      }

      // ! php://memory renders the markup before counting lines
      $Memory = new Output('php://memory');
      $Memory->render($frame);
      rewind($Memory->stream);
      $painted = (string) stream_get_contents($Memory->stream);

      $this->height = substr_count($painted, "\n");

      $this->Output->write($painted);

      return null;
   }

   /**
    * Starts the timeline: activates the first step and paints the frame.
    *
    * @return void
    */
   public function start (): void
   {
      // ?
      if ($this->Steps->current >= 0) {
         return;
      }

      $Step = $this->Steps->activate();

      // ? Non-interactive output appends one line per transition
      if (BOOTGLY_TTY === false || $this->append === true) {
         if ($Step !== null) {
            $this->Output->render("{$this->glyphs['active']} {$Step->label}\n");
         }

         return;
      }

      $this->Output->Cursor->hide();

      $this->render();
   }

   /**
    * Advances the flow: completes the active step and activates the next one.
    *
    * @param string $note A short annotation for the completed step.
    *
    * @return void
    */
   public function advance (string $note = ''): void
   {
      // ?
      if ($this->finished === true || $this->Steps->current < 0) {
         return;
      }

      $Done = $this->Steps->Steps[$this->Steps->current];
      $Done->update(States::Done, $note);

      $Next = $this->Steps->activate();

      // ? Non-interactive output appends one line per transition
      if (BOOTGLY_TTY === false || $this->append === true) {
         $annotated = $note !== '' ? " ({$note})" : '';
         $this->Output->render("{$this->glyphs['done']} {$Done->label}{$annotated}\n");

         if ($Next !== null) {
            $this->Output->render("{$this->glyphs['active']} {$Next->label}\n");
         }
         else {
            $this->finished = true;
         }

         return;
      }

      // ? No steps remain — the flow is complete
      if ($Next === null) {
         $this->finished = true;
      }

      $this->render();

      if ($this->finished === true) {
         $this->Output->Cursor->show();
      }
   }

   /**
    * Fails the active step and stops the flow.
    *
    * @param string $note A short annotation for the failed step.
    *
    * @return void
    */
   public function fail (string $note = ''): void
   {
      // ?
      if ($this->finished === true || $this->Steps->current < 0) {
         return;
      }

      $this->finished = true;

      $Failed = $this->Steps->Steps[$this->Steps->current];
      $Failed->update(States::Failed, $note);

      // ? Non-interactive output appends one line per transition
      if (BOOTGLY_TTY === false || $this->append === true) {
         $annotated = $note !== '' ? " ({$note})" : '';
         $this->Output->render("{$this->glyphs['failed']} {$Failed->label}{$annotated}\n");

         return;
      }

      $this->render();

      $this->Output->Cursor->show();
   }
}
