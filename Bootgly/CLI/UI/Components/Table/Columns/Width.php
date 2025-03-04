<?php

namespace Bootgly\CLI\UI\Components\Table\Columns;


use function count;
use function max;


/**
 * Column width management for CLI tables.
 *
 * Handles storage, retrieval and calculation of column widths,
 * with support for different table sections.
 */
class Width
{
   /**
    * Storage for column widths.
    *
    * Structure is either:
    * - For non-sectioned widths: [column_index => width]
    * - For sectioned widths: [section_name => [column_index => width]]
    *
    * @var array<string|int, int|array<string|int, int>>
    */
   private array $widths = [];


   /**
    * Sets the width for a specific column.
    *
    * @param string|int  $index   The column index or identifier
    * @param int         $width   The width to set for the column
    * @param string|null $section Optional section name
    *
    * @return void
    */
   public function set (
      string|int $index,
      int $width,
      null|string $section = null
   ): void
   {
      if ($section !== null) {
         // @phpstan-ignore-next-line
         $this->widths[$section][$index] = $width;
      }
      else {
         $this->widths[$index] = $width;
      }
   }

   /**
    * Gets the column widths.
    *
    * @param string|null $section Optional section name to retrieve widths from
    *
    * @return array<string|int, int|array<string|int, int>> Array of column widths
    */
   public function get (null|string $section = null): array
   {
      if ($section !== null) {
         // @phpstan-ignore-next-line
         return $this->widths[$section] ?? [];
      }
      
      return $this->widths;
   }

   /**
    * Sets width for a column only if greater than current width.
    *
    * @param string|int  $index   The column index or identifier
    * @param int         $width   The width to compare and potentially set
    * @param string|null $section Optional section name
    *
    * @return void
    */
   public function max (
      string|int $index,
      int $width,
      null|string $section = null
   ): void
   {
      if ($section !== null) {
         // @phpstan-ignore-next-line
         $this->widths[$section][$index] = max(
            $width,
            $this->widths[$section][$index] ?? 0
         );
      }
      else {
         $this->widths[$index] = max(
            $width,
            $this->widths[$index] ?? 0
         );
      }
   }

   /**
    * Counts the number of columns.
    *
    * @param string|null $section Optional section name to count columns from
    *
    * @return int The number of columns
    */
   public function count (null|string $section = null): int
   {
      if ($section !== null) {
         /** @var array<string|int,int> */
         $widths = $this->widths[$section] ?? [];

         return count($widths);
      }

      return count($this->widths);
   }
}
