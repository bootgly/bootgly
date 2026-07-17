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
use function max;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function substr_count;
use Closure;
use Throwable;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Output\Region;
use Bootgly\CLI\UI\Components\Timeline;
use Bootgly\CLI\UI\Components\Timeline\States;
use Bootgly\CLI\UI\Components\Timeline\Step;


/**
 * Declarative multi-step guided flow on the Timeline spine.
 *
 * Each step binds a label to a handler. `run()` walks the steps forward-only.
 * Interactive terminals keep the timeline fixed at the top of the screen:
 * each activation clears the screen and repaints the full frame (past ✔ /
 * active ◉ / future ○) with a reserved content area nested INSIDE the
 * timeline — between the active step and the upcoming ones — so the step
 * content (any component the handler renders via the shared Input/Output)
 * always sits right below the step it belongs to, with the whole flow map
 * still visible, no matter how many steps the wizard carries.
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
   /** Guide rows (`│`) reserved for the step content, between the active step and the next */
   public int $reserve;

   // * Data
   /** The state and rendering spine — configure glyphs via Timeline->glyphs */
   public private(set) Timeline $Timeline;
   /** @var array<int,Closure(self): (null|string)> Step handlers, index-aligned with the Timeline steps */
   private array $handlers;

   // * Metadata
   /** The Throwable that failed the flow (null while none) */
   public private(set) null|Throwable $Throwable;
   public private(set) bool $finished;
   /** @var array<int,int> Per-step content rows, index-aligned with the Timeline steps (0 = $reserve) */
   private array $reserves;
   /** Position of the next mid-run insertion (right after the active step, in add order) */
   private int $insertion;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->title = '';
      $this->reserve = 3;

      // * Data
      $this->Timeline = new Timeline($Output);
      $this->handlers = [];

      // * Metadata
      $this->Throwable = null;
      $this->finished = false;
      $this->reserves = [];
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
    * @param int $rows Content rows reserved for this step (0 follows $reserve) —
    *                  size it to the step's tallest editor (e.g. a Menu's frame).
    *
    * @return Step
    */
   public function add (string $label, Closure $handler, int $rows = 0): Step
   {
      $Steps = $this->Timeline->Steps;

      // ? Mid-run: insert right after the active step (in add order)
      if ($this->finished === false && $Steps->current >= 0) {
         array_splice($this->handlers, $this->insertion, 0, [$handler]);
         array_splice($this->reserves, $this->insertion, 0, [$rows]);

         // :
         return $Steps->insert($label, $this->insertion++);
      }

      $this->handlers[] = $handler;
      $this->reserves[] = $rows;

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
    * Resolves Template markup into painted output (SGR).
    *
    * @param string $markup The content with Template markup.
    *
    * @return string
    */
   private function paint (string $markup): string
   {
      $Memory = new Output('php://memory');
      $Memory->render($markup);
      rewind($Memory->stream);

      // :
      return (string) stream_get_contents($Memory->stream);
   }

   /**
    * Presents the activation frame on a fresh screen: title, past + active
    * steps, the content area — `reserve` dim guide rows (`│`) nested inside
    * the timeline — and the upcoming steps below it. The cursor is anchored
    * on the second guide row (after the `│` guide), with relative movements
    * only: absolute rows drift on scrolled or embedded terminals.
    */
   private function present (): void
   {
      $Steps = $this->Timeline->Steps;
      $current = $Steps->current;

      // ! Head — title, past steps and the active one
      $this->Timeline->until = $current;
      $top = (string) $this->Timeline->render(self::RETURN_OUTPUT);

      $title = $this->title !== '' ? "{$this->title}\n" : '';
      $head = $this->paint("{$title}{$top}");

      // ! Content area — `rows` guide rows framed by one breathing guide on
      //   each side; unused rows read as the connector
      $rows = $this->reserves[$current] ?? 0;
      $reserve = max(1, $rows > 0 ? $rows : $this->reserve) + 2;
      $guide = $this->paint('@#Black:│@;');
      $gap = str_repeat("{$guide}\n", $reserve);

      // ! Tail — the upcoming steps (the last guide row connects them)
      $tail = '';
      if ($current < $Steps->count - 1) {
         $this->Timeline->from = $current + 1;
         $this->Timeline->until = null;

         $tail = $this->paint((string) $this->Timeline->render(self::RETURN_OUTPUT));
      }

      $this->Timeline->from = null;
      $this->Timeline->until = null;

      $this->Output->write("{$head}{$gap}{$tail}");

      // @ Anchor the cursor on the second guide row, after the `│` guide —
      //   relative movement only (up from the frame end, then the column)
      $rows = substr_count($tail, "\n") + $reserve - 1;
      $this->Output->Cursor->up($rows, column: 1);
      $this->Output->Cursor->moveTo(column: 4);
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
            // @ Fixed timeline: a fresh screen per activation — the full frame
            //   stays on screen with the content area nested inside it, right
            //   below the active step
            $this->Output->clear();

            $this->present();
         }

         // ! Mid-run additions land right after this step
         $this->insertion = $Steps->current + 1;

         $handler = $this->handlers[$Steps->current] ?? null;

         // ! Nested region — the handler's components render behind the `│`
         //   guide, shifted into the content area (width shrinks accordingly)
         $Host = $this->Output;
         $shrunk = false;

         if (BOOTGLY_TTY === true) {
            $gutter = $this->paint('@#Black:│@;') . '  ';
            $this->Output = new Region($Host->stream, $gutter, 3);

            if (isSet(Terminal::$width) === true) {
               Terminal::$width -= 3;
               $shrunk = true;
            }
         }

         try {
            $note = $handler !== null ? $handler($this) : null;
         }
         catch (Throwable $Throwable) {
            $this->Output = $Host;
            if ($shrunk === true) {
               Terminal::$width += 3;
            }

            // * Metadata
            $this->Throwable = $Throwable;
            $this->finished = true;

            $Step->update(States::Failed, $Throwable->getMessage());

            if (BOOTGLY_TTY === false) {
               $annotated = $Step->note !== '' ? " ({$Step->note})" : '';
               $this->Output->render("{$glyphs['failed']} {$Step->label}{$annotated}\n");
            }
            else {
               // @ Append the final frame at the content cursor — the failed
               //   step's content and Alerts stay on screen
               $this->render();
            }

            // :
            return false;
         }

         $this->Output = $Host;
         if ($shrunk === true) {
            Terminal::$width += 3;
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
