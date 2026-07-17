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
use const PHP_EOL;
use function array_search;
use function count;
use function max;
use function mb_strimwidth;
use function mb_strwidth;
use function min;
use function str_repeat;
use function substr_count;
use function usleep;
use Closure;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Output\Window;
use Bootgly\CLI\UI\Components\Tree\Node;


/**
 * Hierarchical view with expand/collapse and lazy children.
 * Renders statically (reports, pipes) and navigates interactively:
 * ↑/↓ aim, → expand, ← collapse, Space toggle, Enter selects (or runs the
 * node action), Esc cancels.
 */
class Tree extends Component
{
   public Input $Input;
   public Output $Output;

   // * Config
   /** Header line above the tree — rendered only when non-empty */
   public string $prompt;
   /** Render `├─ └─ │` connector guides (false = plain indent) */
   public bool $guides;
   /** Blink the aim marker during navigation (cursor visibility aid) */
   public bool $blink;
   /** Max visible rows (null renders all) */
   public null|int $viewport;
   /** @var array<string,string> Marker glyphs by node state */
   public array $glyphs;

   // * Data
   /** @var array<int,Node> Root nodes */
   public private(set) array $Nodes;

   // * Metadata
   /** Aimed row — index into the flattened visible list */
   public private(set) int $aimed;
   public private(set) Window $Window;
   /** Node confirmed by Enter — null after Esc / cancel */
   public private(set) null|Node $selected;
   /** @var array<int,Node> Visible rows in pre-order (rebuilt per frame) */
   private array $flattened;
   /** @var array<int,string> Connector prefix per visible row */
   private array $prefixes;
   /** Interactive session active? (adds the aim column) */
   private bool $navigating;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->prompt = '';
      $this->guides = true;
      $this->blink = false;
      $this->viewport = null;
      $this->glyphs = [
         'expanded'  => '▾',
         'collapsed' => '▸',
         'leaf'      => '·'
      ];

      // * Data
      $this->Nodes = [];

      // * Metadata
      $this->aimed = 0;
      $this->Window = new Window;
      $this->selected = null;
      $this->flattened = [];
      $this->prefixes = [];
      $this->navigating = false;
   }

   /**
    * Adds a root node.
    *
    * @param string $label The node label — Template markup allowed.
    * @param mixed $value The consumer payload carried by the node.
    * @param null|Closure $resolver A lazy children provider for the node.
    *
    * @return Node The new root node.
    */
   public function add (string $label, mixed $value = null, null|Closure $resolver = null): Node
   {
      $Node = new Node($label, $value);
      $Node->resolver = $resolver;

      $this->Nodes[] = $Node;

      // :
      return $Node;
   }

   /**
    * Renders the visible tree — expanded nodes only.
    *
    * @param int $mode The render mode (WRITE_OUTPUT or RETURN_OUTPUT).
    *
    * @return null|string The rendered frame when returning output.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ! Visible rows
      $this->flatten();
      $this->slide();

      // ? Header line only when set — reports must not start blank
      $rendered = $this->prompt !== '' ? "{$this->prompt}\n" : '';

      // ! Viewport window
      $Window = $this->Window;
      $windowed = $this->viewport !== null;
      $total = count($this->flattened);

      $first = $windowed === true ? $Window->first : 0;
      $last = $windowed === true ? $Window->last : $total - 1;

      // ? Viewport `↑ N more` indicator — before the first visible row
      if ($windowed === true && $first > 0) {
         $rendered .= "@#Black:↑ {$first} more@;\n";
      }

      // @@ Rows
      for ($row = $first; $row <= $last; $row++) {
         $rendered .= $this->compile($row);
      }

      // ? Viewport `↓ N more` indicator — after the last visible row
      if ($windowed === true && $last < $total - 1) {
         $below = $total - 1 - $last;
         $rendered .= "@#Black:↓ {$below} more@;\n";
      }

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $rendered;
      }

      $this->Output->render($rendered);

      // :
      return null;
   }

   /**
    * Controls the tree with one keystroke — a pure state machine: no I/O.
    *
    * @param string $key The assembled key bytes (see Input::listen).
    *
    * @return bool false when the interaction finishes (Enter / Esc).
    */
   public function control (string $key): bool
   {
      // ! Visible rows refresh (covers direct calls outside the navigate loop)
      $this->flatten();

      $total = count($this->flattened);
      // ? External mutations may shrink the tree between calls — a stale aim
      //   clamps back into the visible range
      $this->aimed = max(0, min($this->aimed, $total - 1));
      $Node = $this->flattened[$this->aimed] ?? null;

      switch ($key) {
         // @ Aiming — clamped, no wrap (trees are spatial)
         case "\e[A":
            if ($this->aimed > 0) {
               $this->aimed--;
            }

            break;
         case "\e[B":
            if ($this->aimed < $total - 1) {
               $this->aimed++;
            }

            break;
         // @ Expanding
         case "\e[C":
            // ? Leaves have nothing to expand
            if ($Node === null || $Node->leaf === true) {
               break;
            }

            if ($Node->open === true) {
               // ? Open branch — pre-order: the first child is the next row
               if ($this->aimed < $total - 1) {
                  $this->aimed++;
               }
            }
            else {
               $Node->expand();

               $this->flatten();
               $this->seek($Node);
            }

            break;
         // @ Collapsing
         case "\e[D":
            // ?
            if ($Node === null) {
               break;
            }

            if ($Node->open === true) {
               $Node->collapse();

               $this->flatten();
               $this->seek($Node);
            }
            else if ($Node->Parent !== null) {
               // ? Leaf or folded — aim jumps to the parent (always visible)
               $parent = array_search($Node->Parent, $this->flattened, true);
               if ($parent !== false) {
                  $this->aimed = $parent;
               }
            }

            break;
         // @ Toggling
         case ' ':
            // ? Leaves have nothing to toggle
            if ($Node === null || $Node->leaf === true) {
               break;
            }

            $Node->toggle();

            $this->flatten();
            $this->seek($Node);

            break;
         // @ Selecting
         case "\r":
         case PHP_EOL:
            // ? Empty tree — nothing to confirm
            if ($Node === null) {
               $this->selected = null;

               // :
               return false;
            }
            // ? Programmable action — its return decides: false confirms
            //   (even on unselectable nodes: the explicit runtime decision
            //   wins), anything else keeps navigating (the tree may have
            //   mutated)
            if ($Node->action !== null) {
               $result = ($Node->action)($Node);

               if ($result !== false) {
                  $this->flatten();
                  $this->seek($Node);

                  break;
               }

               $this->selected = $Node;

               // :
               return false;
            }
            // ? Unselectable nodes ignore Enter
            if ($Node->selectable === false) {
               break;
            }

            $this->selected = $Node;

            // :
            return false;
         // @ Canceling
         case "\e":
            $this->selected = null;

            // :
            return false;
      }

      $this->slide();

      // :
      return true;
   }

   /**
    * Navigates the tree interactively until a node is confirmed or the
    * session is canceled. Non-interactive terminals dump the tree once.
    *
    * @return null|Node The confirmed node — null on Esc, EOF or pipes.
    */
   public function navigate (): null|Node
   {
      // ? Non-interactive: static dump once — no input can arrive
      if (BOOTGLY_TTY === false) {
         $this->render();

         // :
         return null;
      }
      // ? Empty tree: nothing to navigate
      if ($this->Nodes === []) {
         // :
         return null;
      }

      // ! Session
      $this->selected = null;
      $this->navigating = true;
      // ! Render frames as strings: repositioning is relative to the frame height
      $this->render = self::RETURN_OUTPUT;
      // ! Height (lines) of the last rendered frame
      $height = 0;

      $this->Input->configure(blocking: false, canonical: false, echo: false);
      $this->Output->Cursor->hide();

      try {
         while (true) {
            // ? Reposition to the first line of the previous frame and erase it — relative
            //   movement: absolute save/restore drifts when rendering scrolls the screen
            if ($height > 0) {
               $this->Output->Cursor->up($height, column: 1);
               $this->Output->Text->clear(lines: $height);
            }

            // @ Render frame
            $frame = (string) $this->render();
            $height = substr_count($frame, "\n");
            $this->Output->render($frame);

            // @@ Wait for a key (listen() assembles full sequences)
            while (true) {
               $key = $this->Input->listen();

               // ? EOF: interactive input will never arrive — cancel
               if ($key === false) {
                  break 2;
               }
               // ? Key available
               if ($key !== '') {
                  break;
               }

               usleep(50000);
            }

            // @ Control the tree
            if ($this->control($key) === false) {
               break;
            }
         }
      }
      finally {
         // ! Restore — runs even when a lazy resolver throws mid-loop
         $this->navigating = false;
         $this->render = self::WRITE_OUTPUT;
         $this->Input->configure(blocking: true, canonical: true, echo: true);
         $this->Output->Cursor->show();
      }

      // : Confirmed node — null on Esc / EOF
      return $this->selected;
   }

   /**
    * Flattens the expanded nodes into the visible row list with their
    * connector prefixes.
    *
    * @return void
    */
   private function flatten (): void
   {
      $this->flattened = [];
      $this->prefixes = [];

      $this->walk($this->Nodes, '');
   }

   /**
    * Walks one sibling group in pre-order, accumulating the connector stem.
    *
    * @param array<int,Node> $Nodes The sibling group.
    * @param string $stem The accumulated ancestor stem (`│  ` / `   `).
    *
    * @return void
    */
   private function walk (array $Nodes, string $stem): void
   {
      $count = count($Nodes);

      // @@
      foreach ($Nodes as $position => $Node) {
         $last = $position === $count - 1;

         $row = count($this->flattened);
         $this->flattened[$row] = $Node;
         $this->prefixes[$row] = match (true) {
            $this->guides === false => str_repeat('  ', $Node->depth),
            $Node->depth === 0 => '',
            default => $last === true ? "{$stem}└─ " : "{$stem}├─ "
         };

         // ? Children of fully open branches only — a pending resolver keeps
         //   the branch folded even when eager children already exist
         if ($Node->open === true) {
            $this->walk(
               $Node->Nodes,
               $Node->depth === 0 ? '' : ($last === true ? "{$stem}   " : "{$stem}│  ")
            );
         }
      }
   }

   /**
    * Compiles one visible row — aim column, dimmed guides, marker and label.
    *
    * @param int $row The visible row index.
    *
    * @return string
    */
   private function compile (int $row): string
   {
      $Node = $this->flattened[$row];
      $prefix = $this->prefixes[$row];

      // ! Marker — node override, else state glyph
      $marker = match (true) {
         $Node->glyph !== '' => $Node->glyph,
         $Node->leaf === true => $this->glyphs['leaf'],
         $Node->open === true => $this->glyphs['expanded'],
         default => $this->glyphs['collapsed']
      };

      // ! Aim column — interactive sessions only (reports carry no aim marker)
      $aim = '';
      if ($this->navigating === true) {
         $aim = match (true) {
            $row !== $this->aimed => '   ',
            $this->blink === true => '@@:=>@; ',
            default => '=> '
         };
      }

      // ? Guides render dimmed; the label stays as-authored (markup-safe).
      //   Sacrificial spaces feed the Template delimiters, which consume one
      //   adjacent whitespace on each side — the prefix alignment survives.
      if ($prefix !== '') {
         $prefix = "@#Black: {$prefix} @;";
      }

      // :
      // ? Rows never exceed the terminal — wrapped physical rows would break
      //   the block-repaint height bookkeeping (navigate() counts logical lines)
      $row = "{$aim}{$prefix}{$marker} {$Node->label}";
      $width = isSet(Terminal::$width) === true ? Terminal::$width - 1 : 79;

      if (mb_strwidth($row) > $width) {
         $row = mb_strimwidth($row, 0, $width, '…');
      }

      // :
      return "{$row}\n";
   }

   /**
    * Slides the viewport window so the aimed row stays visible.
    *
    * @return void
    */
   private function slide (): void
   {
      $this->Window->size = $this->viewport ?? 0;
      $this->Window->total = count($this->flattened);
      $this->Window->slide($this->aimed);
   }

   /**
    * Re-aims after a rebuild — by identity first, clamped as fallback.
    *
    * @param Node $Aimed The node the aim should stay on.
    *
    * @return void
    */
   private function seek (Node $Aimed): void
   {
      $row = array_search($Aimed, $this->flattened, true);

      $this->aimed = $row !== false
         ? $row
         : max(0, min($this->aimed, count($this->flattened) - 1));
   }
}
