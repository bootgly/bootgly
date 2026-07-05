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


use const BOOTGLY_TTY;
use function feof;
use function is_string;
use function str_pad;
use function stripos;
use function substr_count;
use function usleep;
use Generator;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Menu\Items;
use Bootgly\CLI\UI\Components\Menu\Items\extensions\Divisors\Divisor;
use Bootgly\CLI\UI\Components\Menu\Items\extensions\Headers\Header;
use Bootgly\CLI\UI\Components\Menu\Items\Option;
use Bootgly\CLI\UI\Components\Menu\Items\Options;
use Bootgly\CLI\UI\Components\Menu\Orientation;


class Menu extends Component
{
   public Input $Input;
   public Output $Output;

   // * Config
   public static int $width;
   public string $prompt;
   public static int $level;

   // * Data
   public Items $Items;

   // * Metadata
   /** @var array<int> */
   public array $selected;


   public function __construct (Input &$Input, Output &$Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      self::$width = 80;
      $this->prompt = '';
      self::$level = 0;

      // * Data
      $this->Items = new Items($this);
      $this->Items->Options = new Options($this);
      // ...Items extensions loaded dynamically

      // * Metadata
      $this->selected = [];
   }

   // @ Templating
   protected function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      $Items = &$this->Items;
      // * Config
      // @ Displaying
      $Orientation = $Items->Orientation->get();
      $Aligment = $Items->Aligment->get();

      // TODO remove:
      $Divisors = $Items->Divisors ?? null;
      $Headers = $Items->Headers ?? null;

      // @
      $rendered = $this->prompt . "\n";
      // ---
      $Options = $Items->Options;

      // ? Type-ahead filter hint
      if ($Options->filter !== '') {
         $rendered .= "@#Black:/{$Options->filter}@;\n";
      }

      // ! Viewport window (vertical lists only)
      $Options->slide();
      $Window = $Options->Window;
      $windowed = $Options->viewport !== null;
      // ! Body (items) — horizontal alignment pads the body, never the prompt
      $body = '';
      // ! Viewport `↑ N more` indicator — emitted once, before the first visible option
      $above = false;

      foreach (Items::$data[self::$level] as $key => $Item) {
         $compiled = '';

         // @ Compile
         // TODO refactor:
         switch ($Item->type) {
            case Divisor::class:
               // @ Compile Divisor
               $compiled = $Divisors->compile($Item);

               break;
            case Header::class:
               // @ Compile Header
               $compiled = $Headers->compile($Item);

               break;
            case Option::class:
               // ! Runtime narrowing (Items data mixes Items and Options)
               if ($Item instanceof Option === false) {
                  break;
               }
               // ? Type-ahead: non-matching, unlocked options are hidden
               if (
                  $Options->filter !== ''
                  && $Item->locked === false
                  && stripos($Item->label, $Options->filter) === false
               ) {
                  continue 2;
               }
               // ? Viewport: options outside the window are hidden
               if ($windowed === true) {
                  if ($Item->index < $Window->first || $Item->index > $Window->last) {
                     continue 2;
                  }

                  if ($above === false && $Window->first > 0) {
                     $body .= "@#Black:↑ {$Window->first} more@;\n";
                     $above = true;
                  }
               }

               // @ Compile Option
               $compiled = $Options->compile($Item);

               break;
         }

         // @ Post compile Item
         // TODO refactor:
         if ($Item->type === Header::class) {
            // @phpstan-ignore-next-line
            $compiled .= $Options->Orientation->get() === Orientation::Horizontal ? ' ' : "\n";
         }

         $body .= $compiled;
      }

      // ? Viewport `↓ N more` indicator — after the last visible option
      if ($windowed === true && $Window->last < $Window->total - 1) {
         $below = $Window->total - 1 - $Window->last;
         $body .= "@#Black:↓ {$below} more@;\n";
      }

      // @ Post compile Items
      // @ Align items horizontally
      // @phpstan-ignore-next-line
      if ($Orientation === Orientation::Horizontal) {
         // @phpstan-ignore-next-line
         $body = str_pad($body, self::$width, ' ', $Aligment->value);
         $body .= "\n";
      }

      $rendered .= $body;

      return match ($this->render) {
         self::WRITE_OUTPUT => $this->Output->render($rendered),
         self::RETURN_OUTPUT => $rendered,
         default => null
      };
   }

   public function rendering (): Generator
   {
      // ? Render once and finish when no interactive terminal is attached (pipes, embedded runtimes)
      if (BOOTGLY_TTY === false) {
         yield $this->render();

         $this->selected = (array) $this->Items->Options::$selected[self::$level];

         // :
         return false;
      }

      // Set Input settings
      $this->Input->configure(
         blocking: false,
         canonical: false,
         echo: false
      );
      // Hide Cursor
      $this->Output->Cursor->hide();

      // ! Render frames as strings: repositioning is relative to the frame height
      $this->render = self::RETURN_OUTPUT;
      // ! Height (lines) of the last rendered frame
      $height = 0;

      // > Items
      $Items = $this->Items;

      while (true) {
         // ? Reposition to the first line of the previous frame and erase it — relative
         //   movement: absolute save/restore drifts when rendering scrolls the screen
         if ($height > 0) {
            $this->Output->Cursor->up($height, column: 1);
            $this->Output->Text->clear(down: true);
         }

         // @ Render Menu
         $frame = $this->render();
         $frame = is_string($frame) === true ? $frame : '';
         $height = substr_count($frame, "\n");

         yield $this->Output->render($frame);

         // @@ Wait for input without re-rendering (non-blocking reads keep signals dispatched)
         while (true) {
            $char = $this->Input->read(1);

            // ? Input available — read one key at a time (bursts and pipes never desync)
            if ($char !== false && $char !== '') {
               // ? Escape sequences arrive as up to 3 bytes (e.g. arrows: ESC [ A)
               if ($char === "\e") {
                  $char .= (string) $this->Input->read(2);
               }

               break;
            }
            // ? EOF: interactive input will never arrive — finish with the current selection
            if (feof($this->Input->stream) === true) {
               break 2;
            }

            usleep(50000);
         }

         // ? The wait loop only breaks here with a non-empty key
         if ($char === false) {
            break;
         }

         // @ Control Menu Items
         $continue = $Items->Options->control($char);

         if ($continue) {
            // @ Return selected in real time
            yield $Items->Options::$selected[self::$level];

            continue;
         }

         break;
      }

      $this->selected = (array) $Items->Options::$selected[self::$level];

      // Restore render mode
      $this->render = self::WRITE_OUTPUT;
      // Restore Input settings
      $this->Input->configure(
         blocking: true,
         canonical: true,
         echo: true
      );
      // Show Cursor
      $this->Output->Cursor->show();

      return false;
   }
}
