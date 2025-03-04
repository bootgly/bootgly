<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Table;


use function mb_strlen;
use function preg_replace;

use Bootgly\ABI\Data\__String;
use Bootgly\CLI\UI\Components\Table;
use Bootgly\CLI\UI\Components\Table\Columns\Width;


class Columns
{
   private Table $Table;

   // * Config
   public null|string $section;
   // @ Width
   public Autowiden $Autowiden;

   // * Data
   // ...

   // * Metadata
   // private int $count;
   // @ Width
   public readonly Width $Width;


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
      $this->Width = new Width;
   }

   public function autowiden (): bool
   {
      $data = $this->Table->Data->rows;

      // @
      // * Metadata
      $last_section = null;

      foreach ($data as $section => $rows) {
         // @ Pre
         // ...

         $Autowiden = $this->Autowiden->get();

         foreach ($rows as $row_data) {
            foreach ($row_data as $column_index => $column_data) {
               // @ Remove ANSI code characters from the string
               $column_data = preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $column_data);

               // @ Get column data length
               $column_data_length = mb_strlen($column_data ?? '');

               // @ Set maximum column width
               switch ($Autowiden) {
                  case Autowiden::Based_On_Section:
                     if ($last_section !== null && $section !== $last_section) {
                        $this->Width->set($column_index, $column_data_length, $section);
                        break;
                     }

                     $this->Width->max($column_index, $column_data_length, $section);
                     break;

                  case Autowiden::Based_On_Entiry_Column:
                     $this->Width->max($column_index, $column_data_length);
               }
            }
         }

         $last_section = $section;

         // @ Post
         // ...
      }

      return true;
   }

   public function count (null|string $section = null): int
   {
      // @phpstan-ignore-next-line
      if ($this->Autowiden->get() === Autowiden::Based_On_Section && $section) {
         return $this->Width->count($section);
      }

      return $this->Width->count();
   }
}

// * Configs
enum Autowiden
{
   use \Bootgly\ABI\Configs\Set;

   case Based_On_Entiry_Column;
   case Based_On_Section;
}
