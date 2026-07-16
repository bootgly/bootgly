<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function array_splice;
use Closure;
use Throwable;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Timeline;
use Bootgly\CLI\UI\Components\Timeline\States;
use Bootgly\CLI\UI\Components\Timeline\Step;


/**
 * Declarative multi-step guided flow on the Timeline spine.
 *
 * Each step binds a label to a handler. `run()` walks the steps forward-only.
 * Interactive terminals keep the timeline fixed at the top of the screen:
 * each activation clears the screen and repaints the full frame (past ✔ /
 * active ◉ / future ○), so the active step's content — any component the
 * handler renders via the shared Input/Output — always sits right below it.
 * Completion closes on a fresh screen with the final all-done frame; failure
 * appends the final frame instead, preserving the failed step's content and
 * Alerts on screen. Non-interactive output appends one plain line per
 * transition (CI-log friendly).
 *
 * Handler contract: `function (Wizard $Wizard): null|string` — the returned
 * string becomes the step note; throw any Throwable to fail the step and stop
 * the flow (the message becomes the ✖ note — keep it short; render rich
 * Alerts before throwing). Handlers may call `add()` to append further steps
 * once a branch is known.
 */
class Wizard extends Component
{
   public Input $Input;
   public Output $Output;

   // * Config
   /** Heading repainted above the frame (rendered once on non-interactive output) */
   public string $title;

   // * Data
   /** The state and rendering spine — configure glyphs via Timeline->glyphs */
   public private(set) Timeline $Timeline;
   /** @var array<int,Closure(self): (null|string)> Step handlers, index-aligned with the Timeline steps */
   private array $handlers;

   // * Metadata
   /** The Throwable that failed the flow (null while none) */
   public private(set) null|Throwable $Throwable;
   public private(set) bool $finished;
   /** Position of the next mid-run insertion (right after the active step, in add order) */
   private int $insertion;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->title = '';

      // * Data
      $this->Timeline = new Timeline($Output);
      $this->handlers = [];

      // * Metadata
      $this->Throwable = null;
      $this->finished = false;
      $this->insertion = 0;
   }


   /**
    * Adds a step to the wizard — callable before and during `run()`.
    * Mid-run additions insert right after the active step (in add order),
    * so a handler slots the steps of the branch it resolves before the
    * upcoming ones.
    *
    * @param string $label The step label.
    * @param Closure $handler The step handler: `function (Wizard $Wizard): null|string`.
    *
    * @return Step
    */
   public function add (string $label, Closure $handler): Step
   {
      $Steps = $this->Timeline->Steps;

      // ? Mid-run: insert right after the active step (in add order)
      if ($this->finished === false && $Steps->current >= 0) {
         array_splice($this->handlers, $this->insertion, 0, [$handler]);

         // :
         return $Steps->insert($label, $this->insertion++);
      }

      $this->handlers[] = $handler;

      // :
      return $this->Timeline->add($label);
   }

   /**
    * Renders the timeline frame (blank-line separated when writing).
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    *
    * @return null|string The markup frame when returning — null when writing.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      $frame = $this->Timeline->render(self::RETURN_OUTPUT);

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $frame;
      }

      // @ Blank-line separated — handler content flows between frames
      $this->Output->render("\n{$frame}\n");

      return null;
   }

   /**
    * Runs the flow: activates each step, presents the frame and invokes the
    * step handler — forward-only, one-shot.
    *
    * @return bool Whether the flow completed (false on failure or re-run).
    */
   public function run (): bool
   {
      $Steps = $this->Timeline->Steps;

      // ? One-shot: never re-run, never re-enter, never run empty
      if ($this->finished === true || $Steps->current >= 0 || $Steps->count === 0) {
         return false;
      }

      // !
      $glyphs = $this->Timeline->glyphs;

      // ? Non-interactive output renders the title once
      if (BOOTGLY_TTY === false && $this->title !== '') {
         $this->Output->render("{$this->title}\n");
      }

      // @@ Activate → present → handle, until no steps remain (mid-run adds are picked up)
      while (($Step = $Steps->activate()) !== null) {
         // ? Non-interactive output appends one line per transition
         if (BOOTGLY_TTY === false) {
            $this->Output->render("{$glyphs['active']} {$Step->label}\n");
         }
         else {
            // @ Fixed timeline: a fresh screen per activation — the frame stays
            //   at the top and the step content flows right below it
            $this->Output->clear();

            if ($this->title !== '') {
               $this->Output->render("{$this->title}\n");
            }

            $this->render();
         }

         // ! Mid-run additions land right after this step
         $this->insertion = $Steps->current + 1;

         $handler = $this->handlers[$Steps->current] ?? null;

         try {
            $note = $handler !== null ? $handler($this) : null;
         }
         catch (Throwable $Throwable) {
            // * Metadata
            $this->Throwable = $Throwable;
            $this->finished = true;

            $Step->update(States::Failed, $Throwable->getMessage());

            if (BOOTGLY_TTY === false) {
               $annotated = $Step->note !== '' ? " ({$Step->note})" : '';
               $this->Output->render("{$glyphs['failed']} {$Step->label}{$annotated}\n");
            }
            else {
               $this->render();
            }

            // :
            return false;
         }

         $Step->update(States::Done, $note ?? '');

         if (BOOTGLY_TTY === false) {
            $annotated = $Step->note !== '' ? " ({$Step->note})" : '';
            $this->Output->render("{$glyphs['done']} {$Step->label}{$annotated}\n");
         }
      }

      // * Metadata
      $this->finished = true;

      // ? Interactive: a fresh closing screen — one final frame, every step done
      if (BOOTGLY_TTY === true) {
         $this->Output->clear();

         if ($this->title !== '') {
            $this->Output->render("{$this->title}\n");
         }

         $this->render();
      }

      // :
      return true;
   }
}
