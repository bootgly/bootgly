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
   Items
};


class Menu
{
   public Input $Input;
   public Output $Output;

   // * Config
   #public int $width;
   public string $title;
   // * Data
   public Items $Items;
   // * Meta
   public array $items;


   public function __construct (Input &$Input, Output &$Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      #$this->width = 80;
      // * Data
      $this->Items = new Items($this);
      // * Meta
      $this->items = &$this->Items->items;
   }

   public function open ()
   {
      // > Menu
      $Items = $this->Items;
      // * Config
      // @ Selection
      $Selection = $Items->Selection;
      // * Meta
      $aimed = &$Items->aimed;
      $selected = &$Items->selected;

      $this->Output->Cursor->save();

      // Set the Terminal Input Non-Canonical Processing and Disable echo
      system('stty -icanon -echo');
      // Set Input Stream Non-Blocking
      stream_set_blocking($this->Input->stream, false);
      // Hide Cursor
      $this->Output->Cursor->hide();

      while (true) {
         $this->Output->Cursor->restore();

         // @ Write Menu title
         $this->Output->write($this->title);

         // @ Render Menu Items
         $Items->render();

         // @ Read 3 characters from Input
         $char = $this->Input->read(3);

         switch ($char) {
            // \x1b \e \033
            case "\e[D": // Left Key
            case "\e[A": // Up Key
               $Items->regress();

               break;
            case "\e[C": // Right Key
            case "\e[B": // Down Key
               $Items->advance();

               break;
            case ' ': // Space Key
               // @ Select / Unselect current item
               $index = 0;
               foreach ($this->items as $key => $value) {
                  if ($aimed === $index) {
                     $Items->toggle($index);
                  } else if ($Selection->get() === $Selection::Unique) {
                     $Items->deselect($index);
                  }

                  $index++;
               }

               break;
            case PHP_EOL: // Enter Key
               break 2;
            default:
               break;
         }

         usleep(125000);
         #usleep(250000);
         #usleep(500000);
      }

      system('stty icanon echo');
      stream_set_blocking($this->Input->stream, true);
      $this->Output->Cursor->show();

      return $selected;
   }
}
