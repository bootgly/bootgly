<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Bootgly\CLI\commands;


use Bootgly\CLI;
use Bootgly\CLI\Command;


class DemoCommand extends Command
{
   // * Config
   public int $group = 0;

   // * Data
   // @ Command
   public string $name = 'demo';
   public string $description = 'Run the Bootgly CLI demo';


   public function run (array $arguments, array $options) : bool
   {
      // * Config
      $id = $arguments[0] ?? null;
      if ($id !== null) {
         $id = (int) $id;
      }

      // @
      $Output = CLI::$Terminal->Output;
      $Output->expand(lines: CLI::$Terminal::$lines);

      // @ Reset Output
      if ($id === 0) {
         $Output->reset();
         return true;
      }

      $examples = [
         // ! Terminal
         // ? Input
         1 => 'Terminal/Input/@reading-01.example.php',

         // ? Output
         // Terminal -> Output @ writing
         2 => 'Terminal/Output/@writing-01.example.php',


         // Terminal -> Output -> Cursor Positioning
         3 => 'Terminal/Output/Cursor-positioning-01.example.php',
         // Terminal -> Output -> Cursor Shaping
         4 => 'Terminal/Output/Cursor-shaping-01.example.php',
         // Terminal -> Output -> Cursor Visualizing
         5 => 'Terminal/Output/Cursor-visualizing-01.example.php',


         // Terminal -> Output -> Text Formatting - Coloring
         6 => 'Terminal/Output/Text-formatting-coloring-01.example.php',
         // Terminal -> Output -> Text Formatting - Styling
         7 => 'Terminal/Output/Text-formatting-styling-01.example.php',

         // Terminal -> Output -> Text Modifying
         8 => 'Terminal/Output/Text-modifying-01.example.php',
         // Terminal -> Output -> Text Modifying - In Display
         9 => 'Terminal/Output/Text-modifying-indisplay-01.example.php',
         // Terminal -> Output -> Text Modifying - Inline
         10 => 'Terminal/Output/Text-modifying-inline-01.example.php',
         // Terminal -> Output -> Text Modifying - Line
         11 => 'Terminal/Output/Text-modifying-line-01.example.php',


         // Terminal -> components - Alert component
         12 => 'Terminal/components/Alert-01.example.php',

         // Terminal -> components - Menu component
         13 => 'Terminal/components/Menu-01.example.php',
         14 => 'Terminal/components/Menu-02.example.php',
         15 => 'Terminal/components/Menu-03.example.php',
         16 => 'Terminal/components/Menu-04.example.php',
         17 => 'Terminal/components/Menu-05.example.php',
         18 => 'Terminal/components/Menu-06.example.php',

         // Terminal -> components - Progress component
         19 => 'Terminal/components/Progress-01.example.php',
         20 => 'Terminal/components/Progress-02.example.php',

         // Terminal -> components - Table component
         21 => 'Terminal/components/Table-01.example.php',

         // Terminal -> components - Fieldset component
         22 => 'Terminal/components/Fieldset-01.example.php',
      ];

      foreach ($examples as $index => $example) {
         if ($id && $index !== $id) {
            continue;
         }

         $file = 'Bootgly/CLI/examples/' . $example;

         $wait = 3;

         $location = 'projects/' . $file;
         require BOOTGLY_ROOT_DIR . 'projects/' . $file;

         sleep($wait);

         CLI::$Terminal->clear();
      }

      return true;
   }
}
