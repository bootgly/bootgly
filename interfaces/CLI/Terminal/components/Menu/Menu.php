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
   public int $width;
   public string $prompt;

   // * Data
   public Items $Items;

   // * Meta
   public int $level;


   public function __construct (Input &$Input, Output &$Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->width = 80;
      $this->prompt = '';

      // * Data
      $this->Items = new Items($this);

      // * Meta
      $this->level = 0;
   }

   public function open ()
   {
      // > Menu
      $Items = $this->Items;

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

      while (true) {
         $this->Output->Cursor->restore();

         // @ Render Menu prompt
         $this->Output->render($this->prompt . "\n");

         // @ Render Menu Items
         $Items->render();

         // @ Read 3 characters from Input
         $char = $this->Input->read(3);

         // @ Control Menu Items
         $continue = $Items->control($char);

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

      return $Items->selected[$this->level];
   }
}
