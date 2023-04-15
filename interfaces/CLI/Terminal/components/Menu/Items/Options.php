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


use Bootgly\CLI\Terminal\components\Menu\Items;
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
   public string $divisors;

   // * Data
   // ...

   // * Meta
   public static int $indexes;
   // @ Selecting
   public static array $selected;


   public function __construct ($Menu)
   {
      // --- Parent --- \\
      parent::__construct($Menu);

      // * Config
      // @ Selecting
      $this->selectable = true;
      $this->deselectable = true;

      // --- Child --- \\
      // * Config
      // @ Selecting
      $this->Selection = Selection::Multiple->set();
      // @ Styling
      $this->divisors = '';

      // * Data
      // ...

      // * Meta
      self::$indexes = 0;
      // @ Selecting
      self::$selected[0] = [];
   }

   public function add (string $label, ? string $id = null) : Option
   {
      $Option = new Option;

      // * Data
      $Option->id = $id;
      $Option->label = $label;

      Items::push($Option);

      return $Option;
   }

   // @ Aiming
   public function regress () : self
   {
      if ($this->aimed > 0) {
         $this->aimed--;
      } else {
         $this->aimed = self::$indexes - 1;
      }

      return $this;
   }
   public function advance () : self
   {
      if ($this->aimed < self::$indexes - 1) {
         $this->aimed++;
      } else {
         $this->aimed = 0;
      }

      return $this;
   }

   // @ Selecting
   private function select ($index)
   {
      if ($this->selectable) {
         self::$selected[Menu::$level][] = $index;
      }
   }
   private function deselect ($index)
   {
      if ($this->deselectable) {
         self::$selected[Menu::$level] = array_diff(
            self::$selected[Menu::$level],
            [$index]
         );
      }
   }
   private function toggle ($index)
   {
      if ( in_array($index, self::$selected[Menu::$level]) ) {
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
      // @ Options
      // * Config
      // @ Displaying
      $Orientation = $this->Orientation->get();
      $Aligment = $this->Aligment->get();
      // @ Styling
      $divisors = $this->divisors;
      // * Data
      // ...
      // * Meta
      // @ Aiming
      $aimed = $this->aimed;
      // @ Selecting
      $selected = self::$selected[Menu::$level];

      // @ Option
      // * Data
      $label = $Option->label;
      $prepend = $Option->prepend ?? '';
      $append = $Option->append ?? '';
      // * Meta
      $index = $Option->index;

      // @
      $compiled = '';

      $aim = [];
      if ($aimed === $index) {
         $aim[0] = $Option->aimed[0] ?? '=>';
         $aim[1] = $Option->aimed[1] ?? '';
      } else {
         $aim[0] = $Option->unaimed[0] ?? '  ';
         $aim[1] = $Option->unaimed[1] ?? '';
      }

      $marker = [];
      if ( in_array($index, $selected) ) {
         $marker[0] = $Option->marked[0] ?? '[X]';
         $marker[1] = $Option->marked[1] ?? '';
      } else {
         $marker[0] = $Option->unmarked[0] ?? '[ ]';
         $marker[1] = $Option->unmarked[1] ?? '';
      }

      $compiled = <<<OUTPUT
      {$aim[0]} {$marker[0]} {$prepend}$label{$append} {$marker[1]} {$aim[1]}
      OUTPUT;

      // @ Displaying
      // Aligment
      if ($Orientation === $Orientation::Vertical) {
         $compiled = str_pad($compiled, Menu::$width, ' ', $Aligment->value);
      }

      // @ Styling
      // Divisor
      switch ($Orientation) {
         case $Orientation::Vertical:
            $divisor = "\n";

            if ($index < self::$indexes - 1) {
               $characters = strlen($divisors);

               if ($characters > 0) {
                  $divisors = str_repeat($divisors, Menu::$width / $characters);
                  $divisor .= "{$divisors}\n";
               }
            }

            break;
         case $Orientation::Horizontal:
            $divisor = ' ';

            if ($index < self::$indexes - 1) {
               $divisor .= "{$divisors}";
            }
      }
      $compiled .= $divisor;

      return $compiled;
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
