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


use function str_pad;
use function usleep;
use Generator;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Menu\Items;
use Bootgly\CLI\UI\Components\Menu\Orientation;
use Bootgly\CLI\UI\Components\Menu\Items\Option;
use Bootgly\CLI\UI\Components\Menu\Items\Options;
// TODO: remove
use Bootgly\CLI\UI\Components\Menu\Items\extensions\Divisors\Divisor;
use Bootgly\CLI\UI\Components\Menu\Items\extensions\Headers\Header;


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
               // @ Compile Option
               $compiled = $Options->compile($Item); // @phpstan-ignore-line

               break;
         }

         // @ Post compile Item
         // TODO refactor:
         if ($Item->type === Header::class) {
            // @phpstan-ignore-next-line
            $compiled .= $Options->Orientation->get() === Orientation::Horizontal ? ' ' : "\n";
         }

         $rendered .= $compiled;
      }

      // TODO calculate the numbers of items rendered in the screen and render only items visible in viewport

      // @ Post compile Items
      // @ Align items horizontally
      // @phpstan-ignore-next-line
      if ($Orientation === Orientation::Horizontal) {
         // @phpstan-ignore-next-line
         $rendered = str_pad($rendered, $this->width, ' ', $Aligment->value);
         $rendered .= "\n";
      }

      return match ($this->render) {
         self::WRITE_OUTPUT => $this->Output->render($rendered),
         self::RETURN_OUTPUT => $rendered,
         default => null
      };
   }

   public function rendering (): Generator
   {
      // Save Cursor position
      $this->Output->Cursor->save();
      // Set Input settings
      $this->Input->configure(
         blocking: false,
         canonical: false,
         echo: false
      );
      // Hide Cursor
      $this->Output->Cursor->hide();

      // > Items
      $Items = $this->Items;

      while (true) {
         $this->Output->Cursor->restore();

         // @ Render Menu
         yield $this->render();

         // @ Read 3 characters from Input
         $char = $this->Input->read(3);
         if ($char === false) {
            continue;
         }

         // @ Control Menu Items
         $continue = $Items->Options->control($char);

         if ($continue) {
            usleep(100000);

            // @ Return selected in real time
            yield $Items->Options::$selected[self::$level];

            continue;
         }

         break;
      }

      $this->selected = (array) $Items->Options::$selected[self::$level];

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
