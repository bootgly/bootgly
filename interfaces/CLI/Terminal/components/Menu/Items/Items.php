<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu\Items;


use Bootgly\CLI\Terminal\components\Menu\ {
   Menu
};
use Bootgly\CLI\Terminal\components\Menu\Items\ {
   Options\Options
};

class Items
{
   private Menu $Menu;

   // * Config
   // @ Selecting
   /**
    * Items Selection is Unique or Multiple?
    */
   public Selection $Selection;
   /**
    * Items are selectable?
    */
   public bool $selectable;
   /**
    * Items are deselectable?
    */
   public bool $deselectable;
   // @ Displaying
   /**
    * Items Orientation is Vertical or Horizontal?
    */
   public Orientation $Orientation;
   /**
    * Items Aligment is Left, Center or Right?
    */
   public Aligment $Aligment;
   // @ Styling
   /**
    * Separator between each Item
    */
   public string $separator;

   // * Data
   public array $options;

   // * Meta
   // @ Aiming
   public int $aimed;
   // @ Selecting
   public array $selected;

   public Options $Options;


   public function __construct (Menu &$Menu)
   {
      $this->Menu = $Menu;

      // * Config
      // @ Selecting
      $this->selectable = true;
      $this->deselectable = true;
      $this->Selection = Selection::Multiple->set();
      // @ Displaying
      $this->Orientation = Orientation::Vertical->set();
      $this->Aligment = Aligment::Left->set();
      // @ Styling
      $this->separator = '';

      // * Data
      $this->Options = new Options;

      // * Meta
      // @ Aiming
      $this->aimed = 0;
      // @ Selecting
      $this->selected[0] = [];
   }

   // @ Aiming
   public function regress ()
   {
      if ($this->aimed > 0) {
         $this->aimed--;
      }
   }
   public function advance ()
   {
      if ($this->aimed < $this->Options->count() - 1) {
         $this->aimed++;
      }
   }

   // @ Selecting
   private function select ($index)
   {
      if ($this->selectable) {
         $this->selected[$this->Menu->level][] = $index;
      }
   }
   private function deselect ($index)
   {
      if ($this->deselectable) {
         $this->selected[0] = array_diff(
            $this->selected[$this->Menu->level],
            [$index]
         );
      }
   }
   private function toggle ($index)
   {
      if ( in_array($index, $this->selected[$this->Menu->level]) ) {
         $this->deselect($index);
      } else {
         $this->select($index);
      }
   }
   private function iterate ()
   {
      // @ Select / Unselect option(s)
      $index = 0;
      foreach ($this->Options[$this->Menu->level] as $key => $value) {
         if ($this->aimed === $index) {
            $this->toggle($index);
         } else if ($this->Selection->get() === $this->Selection::Unique) {
            $this->deselect($index);
         }

         $index++;
      }
   }

   public function control (string $char) : bool
   {
      switch ($char) {
         // \x1b \e \033
         // @ Aiming
         case "\e[D": // Left Key
         case "\e[A": // Up Key
            $this->regress();
            break;
         case "\e[C": // Right Key
         case "\e[B": // Down Key
            $this->advance();
            break;

         // @ Selecting
         case ' ': // Space Key
            $this->iterate();
            break;

         case PHP_EOL: // Enter Key
            return false;
            break;

         default:
            break;
      }

      return true;
   }

   public function render ()
   {
      $Menu = $this->Menu;

      // * Config
      // @ Displaying
      $Orientation = $this->Orientation->get();
      $Aligment = $this->Aligment->get();
      // @ Styling
      $separator = $this->separator;

      $index = 0;
      $count = $this->Options->count();
      $options = '';

      // @ Write each Menu option
      foreach ($this->Options[$Menu->level] as $key => $value) {
         // * Config
         // @ Displaying
         switch ($Orientation) {
            case $Orientation::Vertical:
               $divisor = "\n";

               // @ Styling
               // Separator
               if ($key < $count - 1) {
                  $characters = strlen($separator);
   
                  if ($characters > 0) {
                     $separator = str_repeat($separator, $Menu->width / $characters);
                     $divisor .= "{$separator}\n";
                  }
               }

               break;
            case $Orientation::Horizontal:
               $divisor = ' ';

               // @ Styling
               // Separator
               if ($key < $count - 1) {
                  $divisor .= "{$separator}";
               }
         }

         // * Data
         // option
         $prepend = '';
         $append = '';

         $option = $value['label'];
         $prepend = $value['prepend'] ?? '';
         $append = $value['append'] ?? '';

         // * Meta
         // @ Aiming
         // Aimed prepend / append
         $aimed = [];
         if ($this->aimed === $index) {
            $aimed[0] = '=>';
            $aimed[1] = '';
         } else {
            $aimed[0] = '  ';
            $aimed[1] = '';
         }
         // @ Selecting
         // Selected prepend / append
         $selected = [];
         if ( in_array($index, $this->selected[$this->Menu->level]) ) {
            $selected[0] = '[X]';
            $selected[1] = '';
         } else {
            $selected[0] = '[ ]';
            $selected[1] = '';
         }

         $option = <<<OUTPUT
         {$aimed[0]} {$selected[0]} {$prepend}$option{$append}{$selected[1]} {$aimed[1]}
         OUTPUT;

         // @ Displaying
         // Aligment
         if ($Orientation === $Orientation::Vertical) {
            $option = str_pad($option, $Menu->width, ' ', $Aligment->value);
         }

         // @ Add option divisor
         $option .= $divisor;

         // @
         $options .= $option;

         // ...
         $index++;
      }

      if ($Orientation === $Orientation::Horizontal) {
         $options = str_pad($options, $Menu->width, ' ', $Aligment->value);
      }

      $this->Menu->Output->render($options);
   }
}


// * Configs
// @ Selecting
enum Selection
{
   use \Bootgly\Set;


   case Unique;
   case Multiple;
}
// @ Displaying
enum Orientation
{
   use \Bootgly\Set;


   case Vertical;
   case Horizontal;
}
enum Aligment : int
{
   use \Bootgly\Set;


   case Left = 1;
   case Center = 2;
   case Right = 0;
}
