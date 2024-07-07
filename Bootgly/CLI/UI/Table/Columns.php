<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Table;

use Bootgly\ABI\Data\__String;
use Bootgly\CLI\UI\Table\Table;


class Columns
{
   private Table $Table;

   // * Config
   public ? string $section;
   // @ Width
   public Autowiden $Autowiden;

   // * Data
   // ...

   // * Metadata
   // private int $count;
   // @ Width
   /** @var array<array<int>|int>*/
   private array $widths; // [... ? section => ...column_index]


   public function __construct (Table $Table)
   {
      $this->Table = $Table;

      // * Config
      $this->section = null;
      // @ Width
      // @phpstan-ignore-next-line
      $this->Autowiden = Autowiden::Based_On_Entiry_Column->set();

      // * Data
      // ...

      // * Metadata
      // $this->count = 0;
      // @ Width
      $this->widths = [];
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         case 'widths':
            // @phpstan-ignore-next-line
            $widths = ($this->Autowiden->get() === Autowiden::Based_On_Section && $this->section
               ? ($this->widths[$this->section] ?? [])
               : $this->widths
            );

            return $widths;
         default:
            return null;
      }
   }

   public function autowiden (): bool
   {
      $data = $this->Table->Data->get();

      // @
      // * Metadata
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
                  case Autowiden::Based_On_Section:
                     if ($last_section !== null && $section !== $last_section) {
                        $this->widths[$section][$column_index] = $column_data_length;
                        break;
                     }

                     $this->widths[$section][$column_index] = max(
                        $column_data_length,
                        $this->widths[$section][$column_index] ?? 0
                     );

                     break;
                  case Autowiden::Based_On_Entiry_Column:
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

   public function count (? string $section = null): int
   {
      // @phpstan-ignore-next-line
      $widths = ($this->Autowiden->get() === Autowiden::Based_On_Section && $section
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

   case Based_On_Entiry_Column;
   case Based_On_Section;
}
