<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Table;

use Bootgly\ABI\Data\__String;
use Bootgly\CLI\Terminal\components\Table\Table;


class Columns
{
   private Table $Table;

   // * Config
   public ? string $section;
   // @ Width
   public Autowiden $Autowiden;

   // * Data
   // ...

   // * Meta
   private int $count;
   // @ Width
   private array $widths; // [... ? section => ...column_index]


   public function __construct ($Table)
   {
      $this->Table = $Table;

      // * Config
      $this->section = null;
      // @ Width
      $this->Autowiden = Autowiden::BASED_ON_ENTIRY_COLUMN->set();

      // * Data
      // ...

      // * Meta
      $this->count = 0;
      // @ Width
      $this->widths = [];
   }
   public function __get ($name)
   {
      switch ($name) {
         case 'widths':
            $widths = ($this->Autowiden->get() === Autowiden::BASED_ON_SECTION && $this->section
               ? ($this->widths[$this->section] ?? [])
               : $this->widths
            );

            return $widths;
         default:
            return null;
      }
   }

   public function autowiden () : bool
   {
      $data = $this->Table->Data->get();

      // @
      // * Meta
      $last_section = null;

      foreach ($data as $section => $rows) {
         // @ Pre
         // ...

         $Autowiden = $this->Autowiden->get();

         foreach ($rows as $row_index => $row_data) {
            foreach ($row_data as $column_index => $column_data) {
               // @ Remove ANSI code characters from the string
               $column_data = preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $column_data);

               // @ Get column data length
               $column_data_length = mb_strlen($column_data);

               // @ Set maximum column width
               switch ($Autowiden) {
                  case Autowiden::BASED_ON_SECTION:
                     if ($last_section !== null && $section !== $last_section) {
                        $this->widths[$section][$column_index] = $column_data_length;
                        break;
                     }

                     $this->widths[$section][$column_index] = max(
                        $column_data_length,
                        $this->widths[$section][$column_index] ?? 0
                     );

                     break;
                  case Autowiden::BASED_ON_ENTIRY_COLUMN:
                     $this->widths[$column_index] = max($column_data_length, $this->widths[$column_index] ?? 0);
               }
            }
         }

         $last_section = $section;

         // @ Post
         // ...
      }

      return true;
   }

   public function count (? string $section = null) : int
   {
      $widths = ($this->Autowiden->get() === Autowiden::BASED_ON_SECTION && $section
         ? $this->widths[$section]
         : $this->widths
      );

      $columns = count($widths);

      return $columns;
   }
}

// * Configs
enum Autowiden
{
   use \Bootgly\ABI\Configs\Set;

   case BASED_ON_ENTIRY_COLUMN;
   case BASED_ON_SECTION;
}
