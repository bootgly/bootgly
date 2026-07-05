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
use function intdiv;
use function ord;
use function str_pad;
use function str_repeat;
use function stripos;
use function strlen;
use function substr;

use Bootgly\CLI\Terminal\Output\Window;
use Bootgly\CLI\UI\Components\Menu;
use Bootgly\CLI\UI\Components\Menu\Items;
use Bootgly\CLI\UI\Components\Menu\Orientation;


final class Options extends Items
{
   // * Config
   // @ Selecting
   public Selection $Selection;
   // @ Displaying
   /** Max visible options — vertical lists only (null renders all) */
   public null|int $viewport;
   /** Options per visual line — vertical grid layout (null = one per line) */
   public null|int $columns;
   // @ Styling
   public string $divisors;

   // * Data
   // ...

   // * Metadata
   public static int $indexes;
   // @ Selecting
   /** @var array<int,array<int>> */
   public static array $selected;
   // @ Displaying
   public private(set) Window $Window;
   // @ Filtering
   /** Incremental type-ahead filter (printable keys; Backspace pops; `Esc` clears) */
   public private(set) string $filter;


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
      // @ Displaying
      $this->viewport = null;
      $this->columns = null;
      // @ Styling
      $this->divisors = '';

      // * Data
      // ...

      // * Metadata
      self::$indexes = 0;
      // @ Selecting
      self::$selected[0] = [];
      // @ Displaying
      $this->Window = new Window;
      // @ Filtering
      $this->filter = '';
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
    * @param bool $locked
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
      bool $locked = false,
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
      // @ Selecting
      $Option->locked = $locked;

      // * Data
      $Option->label = $label;
      $Option->prepend = $prepend;
      $Option->append = $append;

      Items::push($Option);

      // ? Locked options never hold the aim — push the initial aim forward
      if ($locked === true && $this->aimed === $Option->index) {
         $this->aimed = $Option->index + 1;
      }

      return $Option;
   }

   /**
    * Check whether an option is locked (display-only).
    *
    * @param int $index
    *
    * @return bool
    */
   private function check (int $index): bool
   {
      $Item = Items::$data[Menu::$level][$index] ?? null;

      return $Item instanceof Option && $Item->locked === true;
   }

   // @ Aiming
   /**
    * Aim an option by index (initial aim / default option).
    *
    * @param int $index
    *
    * @return self
    */
   public function aim (int $index): self
   {
      // ? Locked options never hold the aim; out-of-range indexes keep the current aim
      if ($index >= 0 && $index < self::$indexes && $this->check($index) === false) {
         $this->aimed = $index;

         $this->slide();
      }

      return $this;
   }
   public function regress (): self
   {
      // @@ Skip locked options (guard: all-locked lists stop after a full cycle)
      for ($moves = 0; $moves < self::$indexes; $moves++) {
         if ($this->aimed > 0) {
            $this->aimed--;
         }
         else {
            $this->aimed = self::$indexes - 1;
         }

         if ($this->check($this->aimed) === false) {
            break;
         }
      }

      $this->slide();

      return $this;
   }
   public function advance (): self
   {
      // @@ Skip locked options (guard: all-locked lists stop after a full cycle)
      for ($moves = 0; $moves < self::$indexes; $moves++) {
         if ($this->aimed < self::$indexes - 1) {
            $this->aimed++;
         }
         else {
            $this->aimed = 0;
         }

         if ($this->check($this->aimed) === false) {
            break;
         }
      }

      $this->slide();

      return $this;
   }
   /**
    * Jumps the aim by a row delta (vertical grids: ↑/↓ move one visual line).
    *
    * @param int $delta The aim delta (± columns).
    *
    * @return self
    */
   private function jump (int $delta): self
   {
      // ! Clamped target (grids never wrap vertically)
      $target = $this->aimed + $delta;
      if ($target < 0) {
         $target = 0;
      }
      if ($target > self::$indexes - 1) {
         $target = self::$indexes - 1;
      }

      $this->aimed = $target;

      // ? Locked landing nudges to the nearest unlocked option
      if ($this->check($this->aimed) === true) {
         $delta > 0 ? $this->advance() : $this->regress();
      }

      $this->slide();

      return $this;
   }
   /**
    * Slides the visible window to keep the aimed option visible (viewport only).
    */
   public function slide (): void
   {
      // ? No viewport — no windowing
      if ($this->viewport === null) {
         return;
      }

      $this->Window->size = $this->viewport;
      $this->Window->total = self::$indexes;
      $this->Window->slide($this->aimed);
   }
   /**
    * Seeks the aim to the first unlocked option matching the type-ahead filter.
    */
   private function seek (): void
   {
      // ?
      if ($this->filter === '') {
         return;
      }

      // @@
      foreach (Items::$data[Menu::$level] as $Item) {
         if (
            $Item instanceof Option
            && $Item->locked === false
            && stripos($Item->label, $this->filter) !== false
         ) {
            $this->aimed = $Item->index;

            $this->slide();

            return;
         }
      }
   }

   // @ Selecting
   private function select (int $index): void
   {
      // ? Locked options are display-only — never enter the selection
      if ($this->check($index) === true) {
         return;
      }

      if ($this->selectable) {
         self::$selected[Menu::$level][] = $index;
      }
   }
   private function deselect (int $index): void
   {
      // ? Locked options are display-only — nothing to deselect
      if ($this->check($index) === true) {
         return;
      }

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
            $this->regress();
            break;
         case "\e[A": // Up Key
            // ? Vertical grids move one visual line up
            $this->columns !== null
               ? $this->jump(-$this->columns)
               : $this->regress();
            break;
         case "\e[C": // Right Key
            $this->advance();
            break;
         case "\e[B": // Down Key
            // ? Vertical grids move one visual line down
            $this->columns !== null
               ? $this->jump($this->columns)
               : $this->advance();
            break;

         // @ Selecting
         case ' ': // Space Key
            $this->iterate();

            break;

         case "\r": // Enter Key (raw terminals without icrnl — e.g. terminal emulators feeding stdin directly)
         case PHP_EOL: // Enter Key
            // ? Enter with an empty selection confirms the aimed option
            if (self::$selected[Menu::$level] === []) {
               // @@ Match by Option index — Items data positions include headers/divisors
               foreach (Items::$data[Menu::$level] as $Item) {
                  if ($Item instanceof Option && $Item->index === $this->aimed) {
                     $this->select($this->aimed);

                     break;
                  }
               }
            }

            return false;

         // @ Filtering (incremental type-ahead)
         case "\x7F": // Backspace Key
         case "\x08": // Backspace Key (Ctrl+H)
            // ? Backspace pops the last filter byte
            if ($this->filter !== '') {
               $this->filter = substr($this->filter, 0, -1);

               $this->seek();
            }
            break;
         case "\e": // Escape Key (bare — no trailing sequence bytes)
            $this->filter = '';
            break;

         default:
            // ? Printable bytes accumulate in the filter (Space stays selection)
            if (strlen($char) === 1 && ord($char) >= 33 && ord($char) !== 127) {
               $this->filter .= $char;

               $this->seek();
            }
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
      if ($Option->locked === true || in_array($index, $selected) ) {
         $marker[0] = $Option->marked[0] ?? '[X]';
         $marker[1] = $Option->marked[1] ?? '';
      }
      else {
         $marker[0] = $Option->unmarked[0] ?? '[ ]';
         $marker[1] = $Option->unmarked[1] ?? '';
      }

      // ? Locked options render dimmed — always marked, deselection blocked
      if ($Option->locked === true) {
         $compiled = <<<OUTPUT
         {$aim[0]} @#Black:{$marker[0]} {$prepend}$label{$append}@; {$marker[1]} {$aim[1]}
         OUTPUT;
      }
      else {
         $compiled = <<<OUTPUT
         {$aim[0]} {$marker[0]} {$prepend}$label{$append} {$marker[1]} {$aim[1]}
         OUTPUT;
      }

      // @ Displaying
      // ? Vertical grid: cells padded to the column width; line break every N options
      // @phpstan-ignore-next-line
      if ($this->columns !== null && $Orientation === Orientation::Vertical) {
         // @phpstan-ignore-next-line
         $compiled = str_pad($compiled, intdiv(Menu::$width, $this->columns), ' ', $Aligment->value);

         if (($index + 1) % $this->columns === 0 || $index === self::$indexes - 1) {
            $compiled .= "\n";
         }

         // :
         return $compiled;
      }

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
