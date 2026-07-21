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
use function array_pop;
use function array_slice;
use function count;
use function end;
use function explode;
use function in_array;
use function intdiv;
use function max;
use function mb_strlen;
use function microtime;
use function min;
use function preg_replace;
use function rtrim;
use function spl_object_id;
use function str_repeat;
use function strtoupper;
use function usleep;

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Screen;
use Bootgly\CLI\UI\Atoms\Boxing;
use Bootgly\CLI\UI\Base\Frame;
use Bootgly\CLI\UI\Base\Frame\Borders;
use Bootgly\CLI\UI\Components\Alert\Type;
use Bootgly\CLI\UX\Components\Toasts\Positions;


/**
 * Toasts — transient corner notifications: each toast is an auto-sized
 * bordered box stacked in a screen position, alive until its deadline. The
 * stack is tick-driven and non-modal: `add()` enqueues, the app loop calls
 * `render()` per frame and expired toasts dismiss themselves — blanking
 * their cells and repainting the covered Boxing components (a terminal
 * cannot read its own cells back). The oldest visible toast sits flush at
 * the anchor and new ones grow away from it, so additions never move the
 * standing boxes. `flash()` is the blocking convenience for linear scripts.
 */
class Toasts extends Component
{
   use Formattable;


   /** Outer box height, in rows (border + message line + border) */
   protected const int HEIGHT = 3;


   public Output $Output;

   // * Config
   /** Screen position anchoring the stack */
   public Positions $Positions;
   /** Default toast lifetime, in seconds */
   public float $TTL;
   /** Max visible toasts — older ones hide until the newest expire */
   public int $limit;
   /** Outer box width cap, in columns — null derives half the terminal width */
   public null|int $width;
   /** Blank rows between the stacked boxes */
   public int $gap;
   /** Seconds per flash() tick */
   public float $throttle;

   // * Data
   /** @var array<int,Boxing> The covered components, repainted on reflow (painter order) */
   public private(set) array $Covered;
   /** @var array<int,array{message: string, Type: Type, until: float, Frame: Frame}> The queued toasts, oldest first */
   public protected(set) array $queue;

   // * Metadata
   /** @var array<int,array{0: int, 1: int, 2: int, 3: int}> Painted rects of the last blit ([row, column, width, height]) */
   private array $rects;
   /** @var array<int,int> The Frame object ids painted on the last blit, in slot order */
   private array $Painted;
   /** @var array{}|array{0: int, 1: int} Terminal size as [columns, lines] — set by resize() (authoritative over the Terminal statics) */
   private array $size;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->Positions = Positions::TopRight;
      $this->TTL = 3.0;
      $this->limit = 3;
      $this->width = null;
      $this->gap = 0;
      $this->throttle = 0.05;

      // * Data
      $this->Covered = [];
      $this->queue = [];

      // * Metadata
      $this->rects = [];
      $this->Painted = [];
      $this->size = [];
   }


   /**
    * Registers the components covered by the stack anchor — each one
    * repaints when a reflow vacates cells (in painter order). Cover
    * everything the anchor overlaps; covering more only costs diff-blit
    * comparisons.
    *
    * @param Boxing ...$Boxes The covered components.
    *
    * @return self
    */
   public function cover (Boxing ...$Boxes): self
   {
      foreach ($Boxes as $Box) {
         $this->Covered[] = $Box;
      }

      // :
      return $this;
   }

   /**
    * Enqueues a toast with its dismiss deadline — painting happens on the
    * next `render()` tick. Non-interactive output streams a plain classified
    * line immediately instead (no box is ever positioned on pipes).
    *
    * @param string $message The toast message (single line — control characters are stripped).
    * @param Type $Type The message severity.
    * @param null|float $TTL The lifetime, in seconds — null keeps the configured default.
    * @param null|float $at The clock (microtime) — null reads the real clock.
    *
    * @return self
    */
   public function add (
      string $message, Type $Type = Type::Default,
      null|float $TTL = null, null|float $at = null
   ): self
   {
      $now = $at ?? microtime(true);

      // ! Single line — control characters (newlines, escapes) never enter the box
      $message = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);

      // ? Pipes stream a plain classified line
      if (BOOTGLY_TTY === false) {
         $name = strtoupper($Type->name);

         $this->Output->write("[{$name}] {$message}\n");
      }

      // @ Bound the queue in every mode — a pipe process that only adds never leaks
      $this->expire($now);

      // * Data
      $this->queue[] = [
         'message' => $message,
         'Type' => $Type,
         'until' => $now + ($TTL ?? $this->TTL),
         'Frame' => $this->compose($message, $Type)
      ];

      // :
      return $this;
   }

   /**
    * Expires the toasts whose deadline passed — pure queue mutation, no
    * painting (the next `render()` blanks and restores).
    *
    * @param null|float $at The clock (microtime) — null reads the real clock.
    *
    * @return void
    */
   public function expire (null|float $at = null): void
   {
      $now = $at ?? microtime(true);

      // @@ Drop the dead toasts
      $queue = [];
      foreach ($this->queue as $entry) {
         if ($entry['until'] > $now) {
            $queue[] = $entry;
         }
      }

      // * Data
      $this->queue = $queue;
   }

   /**
    * Renders the stack — the tick: expires dead toasts, blanks the vacated
    * cells, repaints the covered components once per reflow and diff-blits
    * the standing boxes (an idle tick writes zero bytes).
    *
    * @param int $mode self::WRITE_OUTPUT to write, self::RETURN_OUTPUT to return the output.
    * @param null|float $at The clock (microtime) — null reads the real clock.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT, null|float $at = null): null|string
   {
      // ! Resolved mode — the inherited $render property pins RETURN_OUTPUT
      if ($this->render === self::RETURN_OUTPUT) {
         $mode = self::RETURN_OUTPUT;
      }

      // @ Expired toasts leave the queue before painting
      $this->expire($at);

      // ! Geometry — the visible slice at the anchored position
      [$visible, $targets] = $this->anchor();

      // ? RETURN mode concatenates the visible boxes — pure, no screen bookkeeping
      if ($mode === self::RETURN_OUTPUT) {
         $output = '';
         foreach ($visible as $entry) {
            $output .= (string) $entry['Frame']->render(self::RETURN_OUTPUT);
         }

         // :
         return $output;
      }

      // ? Pipes stream plain lines at add() — nothing is positioned-painted
      if (BOOTGLY_TTY === false) {
         return null;
      }

      // ! The Frame identities painted this tick — a changed composition means a
      //   box may now sit over cells another box left behind, so its own diff-blit
      //   front cache (which only tracks its own last paint) is no longer enough
      $Painted = [];
      foreach ($visible as $entry) {
         $Painted[] = spl_object_id($entry['Frame']);
      }
      $reflowed = $Painted !== $this->Painted;

      // ! Stale rects — cells vacated since the last blit (no box targets them now)
      $stale = [];
      foreach ($this->rects as $rect) {
         if (in_array($rect, $targets, true) === false) {
            $stale[] = $rect;
         }
      }

      // ? A reflow blanks the vacated cells and repaints the covered components once
      if ($stale !== []) {
         $this->blank($stale);
         $this->restore();
      }

      // ? Any composition change forces a full repaint — the covered restore may
      //   have crossed the boxes, and re-entering boxes must overwrite stale cells
      if ($stale !== [] || $reflowed === true) {
         foreach ($visible as $entry) {
            $entry['Frame']->invalidate();
         }
      }

      // @ Paint — moved boxes also full-repaint via the Frame rect-change detection
      foreach ($visible as $entry) {
         $entry['Frame']->render();
      }

      // * Metadata
      $this->rects = $targets;
      $this->Painted = $Painted;

      // :
      return null;
   }

   /**
    * Composes the visible stack as absolute screen rows — the seam for hosts
    * that rebuild their whole frame every tick (e.g. the Console App shell)
    * instead of letting the stack self-blit. Pure: expires, anchors and
    * returns; nothing touches the screen. Each row is left-padded to its box
    * column — the host overlays it at its 1-based screen row.
    *
    * @param null|float $at The clock (microtime) — null reads the real clock.
    *
    * @return array<int,string> The overlay rows — screen row (1-based) => padded row content.
    */
   public function overlay (null|float $at = null): array
   {
      // @ Expired toasts leave the queue before composing
      $this->expire($at);

      // ! Geometry — the visible slice at the anchored position
      [$visible, ] = $this->anchor();

      // @@ Boxes → absolute rows
      $rows = [];
      foreach ($visible as $entry) {
         $Frame = $entry['Frame'];
         $content = (string) $Frame->render(self::RETURN_OUTPUT);
         $pad = str_repeat(' ', max(0, $Frame->column - 1));

         $offset = 0;
         foreach (explode("\n", rtrim($content, "\n")) as $line) {
            $rows[$Frame->row + $offset] = "{$pad}{$line}";
            $offset++;
         }
      }

      // :
      return $rows;
   }

   /**
    * Shows a toast and blocks until it expires — the convenience for linear
    * scripts (paint → pace → dismiss). Other queued toasts keep expiring on
    * schedule during the wait. Non-interactive output streams the plain line
    * and returns immediately.
    *
    * @param string $message The toast message (single line).
    * @param Type $Type The message severity.
    * @param null|float $TTL The lifetime, in seconds — null keeps the configured default.
    *
    * @return void
    */
   public function flash (string $message, Type $Type = Type::Default, null|float $TTL = null): void
   {
      $this->add($message, $Type, $TTL);

      // ? Pipes already streamed the plain line — the flash toast is consumed
      //   immediately (it would never be painted) and the call never sleeps
      if (BOOTGLY_TTY === false) {
         array_pop($this->queue);

         return;
      }

      // ? A RETURN_OUTPUT pin hands painting to a coordinator — blocking here would
      //   stall the deadline with nothing on screen; the toast stays queued for the
      //   coordinator's own ticks
      if ($this->render === self::RETURN_OUTPUT) {
         return;
      }

      // ! The flash deadline
      $entry = end($this->queue);

      // ? add() just enqueued — an empty queue is unreachable here
      if ($entry === false) {
         return;
      }

      $until = $entry['until'];

      // @@ Paint → pace → tick until the flash toast dies
      $this->render();

      while (microtime(true) < $until) {
         usleep((int) ($this->throttle * 1000000));

         $this->render();
      }

      // @ The final tick expires the toast — blank + covered repaint
      $this->render();
   }

   /**
    * Dismisses every toast — empties the queue, blanks the painted cells and
    * repaints the covered components.
    *
    * @return void
    */
   public function clear (): void
   {
      // * Data
      $this->queue = [];

      // ? Nothing painted
      if (BOOTGLY_TTY === false || $this->rects === []) {
         return;
      }

      // @ Blank the stack, then the covered components repaint over it
      $this->blank($this->rects);
      $this->restore();

      // * Metadata
      $this->rects = [];
      $this->Painted = [];
   }

   /**
    * Invalidates every box — the next render repaints the full rectangles
    * (screen cleared externally, overlapped, ...).
    *
    * @return void
    */
   public function invalidate (): void
   {
      foreach ($this->queue as $entry) {
         $entry['Frame']->invalidate();
      }
   }

   /**
    * Resizes against a new terminal size — wipes the screen, repaints the
    * covered components and re-anchors the stack. The signature matches the
    * `Screen::watch` resize handler.
    *
    * @param int $columns The new terminal width, in columns.
    * @param int $lines The new terminal height, in rows.
    *
    * @return void
    */
   public function resize (int $columns, int $lines): void
   {
      // * Metadata
      $this->size = [$columns, $lines];

      // ? Nothing painted — pipes never position-paint
      if (BOOTGLY_TTY === false || $this->rects === []) {
         return;
      }

      // @ Wipe the screen and repaint: covered components first, the stack on top
      $this->Output->clear();
      $this->restore();

      // * Metadata
      $this->rects = [];
      $this->Painted = [];

      $this->invalidate();
      $this->render();
   }

   /**
    * Composes one toast box — a Frame colored by the severity, its body a
    * pre-rendered glyph + message line (Frame buffers never resolve Template
    * markup, so the paint resolves here).
    *
    * @param string $message The toast message.
    * @param Type $Type The message severity.
    *
    * @return Frame
    */
   private function compose (string $message, Type $Type): Frame
   {
      // ! Type visuals — border color + severity glyph
      [$color, $glyph] = match ($Type) {
         Type::Success => ['@#Green:', '✔'],
         Type::Attention => ['@#Yellow:', '▲'],
         Type::Failure => ['@#Red:', '✖'],
         default => ['@#Blue:', '●']
      };

      $Frame = new Frame($this->Output);
      $Frame->Borders = Borders::Round;
      $Frame->follow = false;
      $Frame->wrap = false;
      $Frame->color = $color;

      // ! Body — complete line, resolved SGR only
      $paint = TemplateEscaped::render($color);
      $reset = self::_RESET_FORMAT;
      $Frame->Output->write(" {$paint}{$glyph}{$reset} {$message}\n");

      // :
      return $Frame;
   }

   /**
    * Anchors the visible stack to the position — resolves the terminal size,
    * clamps the slots (short terminals shrink the visible count instead of
    * overlapping), sizes each box to its message and assigns the geometry.
    *
    * @return array{0: array<int,array{message: string, Type: Type, until: float, Frame: Frame}>, 1: array<int,array{0: int, 1: int, 2: int, 3: int}>} The visible entries and their rects.
    */
   private function anchor (): array
   {
      // ! Terminal size — a resize() size is authoritative (SIGWINCH-supplied);
      //   otherwise the live Terminal statics self-heal once the CLI boots, with
      //   the measure() probe as the pre-boot fallback
      [$columns, $lines] = match (true) {
         $this->size !== [] => $this->size,
         isSet(Terminal::$columns) === true => [Terminal::$columns, Terminal::$lines],
         default => Screen::measure()
      };

      // ! Slots — short terminals shrink the visible count instead of overlapping
      $step = self::HEIGHT + $this->gap;
      $slots = max(1, min($this->limit, intdiv($lines + $this->gap, $step)));

      // ! Visible stack — the newest toasts; the oldest visible sits flush at the anchor
      $visible = array_slice($this->queue, -$slots);

      $cap = max(8, $this->width ?? intdiv($columns, 2));

      // ! Center blocks measure the whole visible stack to center it vertically
      $count = count($visible);
      $block = $count > 0 ? $count * $step - $this->gap : 0;

      // @@ Slot geometry — the stack grows away from the anchor edge
      $rects = [];
      foreach ($visible as $slot => $entry) {
         // # Box — auto-sized to the message (borders + padding + glyph)
         $width = min(max(mb_strlen($entry['message']) + 6, 8), $cap, $columns);

         // # Anchor
         $row = match ($this->Positions) {
            Positions::TopLeft, Positions::TopCenter, Positions::TopRight
               => 1 + $slot * $step,
            Positions::Center
               => max(1, intdiv($lines - $block, 2) + 1 + $slot * $step),
            Positions::BottomLeft, Positions::BottomCenter, Positions::BottomRight
               => max(1, $lines - self::HEIGHT + 1 - $slot * $step)
         };
         $column = match ($this->Positions) {
            Positions::TopLeft, Positions::BottomLeft
               => 1,
            Positions::TopCenter, Positions::Center, Positions::BottomCenter
               => max(1, intdiv($columns - $width, 2) + 1),
            Positions::TopRight, Positions::BottomRight
               => max(1, $columns - $width + 1)
         };

         $Frame = $entry['Frame'];
         $Frame->row = $row;
         $Frame->column = $column;
         $Frame->width = $width;
         $Frame->height = self::HEIGHT;

         $rects[] = [$row, $column, $width, self::HEIGHT];
      }

      // :
      return [$visible, $rects];
   }

   /**
    * Blanks rects with literal spaces — no erase escapes (the Frame blit
    * convention), so untouched surroundings stay intact.
    *
    * @param array<int,array{0: int, 1: int, 2: int, 3: int}> $rects The rects to blank.
    *
    * @return void
    */
   private function blank (array $rects): void
   {
      foreach ($rects as [$row, $column, $width, $height]) {
         $spaces = str_repeat(' ', $width);

         // @@
         for ($offset = 0; $offset < $height; $offset++) {
            $this->Output->Cursor->moveTo(line: $row + $offset, column: $column);
            $this->Output->write($spaces);
         }
      }
   }

   /**
    * Repaints the covered components — once per reflow, in painter order.
    *
    * @return void
    */
   private function restore (): void
   {
      foreach ($this->Covered as $Box) {
         $Box->invalidate();
         $Box->render();
      }
   }
}
