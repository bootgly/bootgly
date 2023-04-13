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


use Bootgly\CLI\Terminal\components\Menu\Items\Items;
use Bootgly\CLI\Terminal\components\Menu\Menu;


final class Options extends Items
{
   // * Config
   // @ Selecting
   /**
    * Items Selection is Unique or Multiple?
    */
   public Selection $Selection;
   // @ Styling
   /**
    * Separator between each Option
    */
   public string $separator;

   // * Data
   // ...

   // * Meta
   public int $indexes;
   // @ Selecting
   public array $selected;


   public function __construct ($Menu)
   {
      // @ Parent
      parent::__construct($Menu);

      // * Config
      // @ Selecting
      $this->selectable = true;
      $this->deselectable = true;

      // ---

      // @ Child
      // * Config
      // @ Selecting
      $this->Selection = Selection::Multiple->set();
      $this->separator = '';

      // * Data
      // ...

      // * Meta
      $this->indexes = 0;
      // @ Selecting
      $this->selected[0] = [];
   }

   public function add (string $label, ? string $id = null) : Option
   {
      $Option = new Option($this->Menu);

      // * Data
      $Option->id = $id;
      $Option->label = $label;
      // * Meta
      $Option->index = $this->indexes++;

      Items::push($Option);

      return $Option;
   }

   // @ Aiming
   public function regress () : self
   {
      if ($this->aimed > 0) {
         $this->aimed--;
      }

      return $this;
   }
   public function advance () : self
   {
      $options = Items::$data[Menu::$level];

      if ($this->aimed < count($options) - 1) {
         $this->aimed++;
      }

      return $this;
   }

   // @ Selecting
   private function select ($index)
   {
      if ($this->selectable) {
         $this->selected[Menu::$level][] = $index;
      }
   }
   private function deselect ($index)
   {
      if ($this->deselectable) {
         $this->selected[Menu::$level] = array_diff(
            $this->selected[Menu::$level],
            [$index]
         );
      }
   }
   private function toggle ($index)
   {
      if ( in_array($index, $this->selected[Menu::$level]) ) {
         $this->deselect($index);
      } else {
         $this->select($index);
      }
   }
   private function iterate ()
   {
      // @ Select / Unselect option(s)
      $index = 0;

      foreach (Items::$data[Menu::$level] as $index => $Item) {
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

   public function compile (Option $Option)
   {
      $Menu = $this->Menu;

      // ! Global
      // * Config
      // @ Displaying
      $Orientation = $this->Orientation->get();
      $Aligment = $this->Aligment->get();
      // @ Styling
      $separator = $this->separator;

      // * Meta
      // @ Aiming
      $aimed = $this->aimed;
      // @ Selecting
      $selected = $this->selected[Menu::$level];

      $index = $Option->index;
      #$count = count(Items::$data[Menu::$level]);
      $options = '';


      // * Config
      // @ Displaying
      switch ($Orientation) {
         case $Orientation::Vertical:
            $divisor = "\n";

            // @ Styling
            // Separator
            #if ($key < $count - 1) {
            $characters = strlen($separator);

            if ($characters > 0) {
               $separator = str_repeat($separator, $Menu->width / $characters);
               $divisor .= "{$separator}\n";
            }
            #}

            break;
         case $Orientation::Horizontal:
            $divisor = ' ';

            // @ Styling
            // Separator
            #if ($key < $count - 1) {
            $divisor .= "{$separator}";
            #}
      }

      // * Data
      // Option
      $label = $Option->label;
      $prepend = $Option->prepend ?? '';
      $append = $Option->append ?? '';

      // * Meta
      // @ Aiming
      // Aimed prepend / append
      $aim = [];
      if ($aimed === $index) {
         $aim[0] = $Option->aimed[0] ?? '=>';
         $aim[1] = $Option->aimed[1] ?? '';
      } else {
         $aim[0] = $Option->unaimed[0] ?? '  ';
         $aim[1] = $Option->unaimed[1] ?? '';
      }
      // @ Selecting
      // Selected prepend / append
      $marker = [];
      if ( in_array($index, $selected) ) {
         $marker[0] = $Option->marked[0] ?? '[X]';
         $marker[1] = $Option->marked[1] ?? '';
      } else {
         $marker[0] = $Option->unmarked[0] ?? '[ ]';
         $marker[1] = $Option->unmarked[1] ?? '';
      }

      $option = <<<OUTPUT
      {$aim[0]} {$marker[0]} {$prepend}$label{$append} {$marker[1]} {$aim[1]}
      OUTPUT;

      // @ Displaying
      // Aligment
      if ($Orientation === $Orientation::Vertical) {
         $option = str_pad($option, $Menu->width, ' ', $Aligment->value);
      }

      // @ Add option divisor
      $option .= $divisor;

      return $option;
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
