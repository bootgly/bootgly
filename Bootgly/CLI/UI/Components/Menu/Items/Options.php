<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Menu\Items;


use function array_diff;
use function in_array;
use function str_pad;
use function str_repeat;
use function strlen;

use Bootgly\CLI\UI\Components\Menu;
use Bootgly\CLI\UI\Components\Menu\Items;
use Bootgly\CLI\UI\Components\Menu\Orientation;


final class Options extends Items
{
   // * Config
   // @ Selecting
   public Selection $Selection;
   // @ Styling
   public string $divisors;

   // * Data
   // ...

   // * Metadata
   public static int $indexes;
   // @ Selecting
   /** @var array<int,array<int>> */
   public static array $selected;


   public function __construct (Menu $Menu)
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
      // @phpstan-ignore-next-line
      $this->Selection = Selection::Multiple->set();
      // @ Styling
      $this->divisors = '';

      // * Data
      // ...

      // * Metadata
      self::$indexes = 0;
      // @ Selecting
      self::$selected[0] = [];
   }

   /**
    * Add a new option to the list.
    *
    * @param array<int> $aimed
    * @param array<int> $unaimed
    * @param array<int> $marked
    * @param array<int> $unmarked
    * @param string $label
    * @param string $prepend
    * @param string $append
    * @return Option
    */
   public function add (
      array $aimed = [],
      array $unaimed = [],
      array $marked = [],
      array $unmarked = [],
      string $label = '',
      string $prepend = '',
      string $append = '',
   ): Option
   {
      $Option = new Option;
      // * Config
      // @ Aiming
      // Aim
      $Option->aimed = $aimed;
      $Option->unaimed = $unaimed;
      // Marker
      $Option->marked = $marked;
      $Option->unmarked = $unmarked;

      // * Data
      $Option->label = $label;
      $Option->prepend = $prepend;
      $Option->append = $append;

      Items::push($Option);

      return $Option;
   }

   // @ Aiming
   public function regress (): self
   {
      if ($this->aimed > 0) {
         $this->aimed--;
      }
      else {
         $this->aimed = self::$indexes - 1;
      }

      return $this;
   }
   public function advance (): self
   {
      if ($this->aimed < self::$indexes - 1) {
         $this->aimed++;
      }
      else {
         $this->aimed = 0;
      }

      return $this;
   }

   // @ Selecting
   private function select (int $index): void
   {
      if ($this->selectable) {
         self::$selected[Menu::$level][] = $index;
      }
   }
   private function deselect (int $index): void
   {
      if ($this->deselectable) {
         self::$selected[Menu::$level] = array_diff(
            self::$selected[Menu::$level],
            [$index]
         );
      }
   }
   private function toggle (int $index): void
   {
      if ( in_array($index, self::$selected[Menu::$level]) ) {
         $this->deselect($index);
      }
      else {
         $this->select($index);
      }
   }
   private function iterate (): void
   {
      // @ Select / Unselect option(s)
      $index = 0;

      foreach (Items::$data[Menu::$level] as $index => $Item) {
         if ($this->aimed === $index) {
            $this->toggle($index);
         }
         // @phpstan-ignore-next-line
         else if ($this->Selection->get() === $this->Selection::Unique) {
            $this->deselect($index);
         }

         $index++;
      }
   }

   public function control (string $char): bool
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

         default:
            break;
      }

      return true;
   }

   public function compile (Option $Option): string
   {
      // ? Options
      // * Config
      // @ Displaying
      $Orientation = $this->Orientation->get();
      $Aligment = $this->Aligment->get();
      // @ Styling
      $divisors = $this->divisors;
      // * Data
      // ...
      // * Metadata
      // @ Aiming
      $aimed = $this->aimed;
      // @ Selecting
      $selected = self::$selected[Menu::$level];

      // ? Option
      // * Data
      $label = $Option->label;
      $prepend = $Option->prepend ?? '';
      $append = $Option->append ?? '';
      // * Metadata
      $index = $Option->index;

      // @
      $compiled = '';

      $aim = [];
      if ($aimed === $index) {
         $aim[0] = $Option->aimed[0] ?? '=>';
         $aim[1] = $Option->aimed[1] ?? '';
      }
      else {
         $aim[0] = $Option->unaimed[0] ?? '  ';
         $aim[1] = $Option->unaimed[1] ?? '';
      }

      $marker = [];
      if ( in_array($index, $selected) ) {
         $marker[0] = $Option->marked[0] ?? '[X]';
         $marker[1] = $Option->marked[1] ?? '';
      }
      else {
         $marker[0] = $Option->unmarked[0] ?? '[ ]';
         $marker[1] = $Option->unmarked[1] ?? '';
      }

      $compiled = <<<OUTPUT
      {$aim[0]} {$marker[0]} {$prepend}$label{$append} {$marker[1]} {$aim[1]}
      OUTPUT;

      // @ Displaying
      // Aligment
      // @phpstan-ignore-next-line
      if ($Orientation === Orientation::Vertical) {
         // @phpstan-ignore-next-line
         $compiled = str_pad($compiled, Menu::$width, ' ', $Aligment->value);
      }

      // @ Styling
      // Divisor
      $divisor = '';
      switch ($Orientation) {
         case Orientation::Vertical:
            $divisor = "\n";

            if ($index < self::$indexes - 1) {
               $characters = strlen($divisors);

               if ($characters > 0) {
                  $divisors = str_repeat($divisors, Menu::$width / $characters);
                  $divisor .= "{$divisors}\n";
               }
            }

            break;
         case Orientation::Horizontal:
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
   use \Bootgly\ABI\Configs\Set;


   case Unique;
   case Multiple;
}
