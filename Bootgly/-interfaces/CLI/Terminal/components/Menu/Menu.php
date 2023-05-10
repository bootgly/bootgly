<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu;


use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;

use Bootgly\CLI\Terminal\components\Menu\Items\ {
   Option,
   Options,
};
// TODO remove:
use Bootgly\CLI\Terminal\components\Menu\Items\extensions\ {
   Divisors\Divisor,
   Headers\Header,
};


class Menu
{
   public Input $Input;
   public Output $Output;

   // * Config
   public static int $width;
   public string $prompt;

   // * Data
   public Items $Items;

   // * Meta
   public static int $level;


   public function __construct (Input &$Input, Output &$Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      self::$width = 80;
      $this->prompt = '';

      // * Data
      $this->Items = new Items($this);
      $this->Items->Options = new Options($this);
      // ...Items extensions loaded dynamically

      // * Meta
      self::$level = 0;
   }

   // @ Templating
   private function render ()
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
      $rendered = '';
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
               $compiled = $Options->compile($Item);

               break;
         }

         // @ Post compile Item
         // TODO refactor:
         if ($Item->type === Header::class) {
            $compiled .= $Options->Orientation->get() === $Orientation::Horizontal ? ' ' : "\n";
         }

         $rendered .= $compiled;
      }

      // TODO calculate the numbers of items rendered in the screen and render only items visible in viewport

      // @ Post compile Items
      // @ Align items horizontally
      if ($Orientation === $Orientation::Horizontal) {
         $rendered = str_pad($rendered, $this->width, ' ', $Aligment->value);
         $rendered .= "\n";
      }

      $this->Output->render($rendered);
   }

   public function open ()
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

         // @ Render Menu prompt
         $this->Output->render($this->prompt . "\n");

         // @ Render Menu
         $this->render();

         // @ Read 3 characters from Input
         $char = $this->Input->read(3);

         // @ Control Menu Items
         $continue = $Items->Options->control($char);

         if ($continue) {
            usleep(100000);
            #usleep(250000);
            #usleep(500000);

            continue;
         }

         break;
      }

      // Restore Input settings
      $this->Input->configure(
         blocking: true,
         canonical: true,
         echo: true
      );
      // Show Cursor
      $this->Output->Cursor->show();

      return $Items->Options::$selected[self::$level];
   }
}
