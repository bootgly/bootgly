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


use const Bootgly\CLI;
use Bootgly\CLI\Command;


class DemoCommand extends Command
{
   // * Config
   public int $group = 0;

   // * Data
   // @ Command
   public string $name = 'demo';
   public string $description = 'Run the Bootgly CLI demo';


   public function run (array $arguments = [], array $options = []): bool
   {
      // * Config
      $id = $arguments[0] ?? null;
      if ($id !== null) {
         $id = (int) $id;
      }

      // @
      $Output = CLI->Terminal->Output;
      $Output->expand(lines: CLI->Terminal::$lines);

      // @ Reset Output
      if ($id === 0) {
         $Output->reset();
         return true;
      }

      $examples = [
         // ! Terminal
         // ? Input
         1 => 'Terminal/Input/@reading-01.demo.php',

         // ? Output
         // Terminal -> Output @ writing
         2 => 'Terminal/Output/@writing-01.demo.php',


         // Terminal -> Output -> Cursor Positioning
         3 => 'Terminal/Output/Cursor-positioning-01.demo.php',
         // Terminal -> Output -> Cursor Shaping
         4 => 'Terminal/Output/Cursor-shaping-01.demo.php',
         // Terminal -> Output -> Cursor Visualizing
         5 => 'Terminal/Output/Cursor-visualizing-01.demo.php',


         // Terminal -> Output -> Text Formatting - Coloring
         6 => 'Terminal/Output/Text-formatting-coloring-01.demo.php',
         // Terminal -> Output -> Text Formatting - Styling
         7 => 'Terminal/Output/Text-formatting-styling-01.demo.php',

         // Terminal -> Output -> Text Modifying
         8 => 'Terminal/Output/Text-modifying-01.demo.php',
         // Terminal -> Output -> Text Modifying - In Display
         9 => 'Terminal/Output/Text-modifying-indisplay-01.demo.php',
         // Terminal -> Output -> Text Modifying - Inline
         10 => 'Terminal/Output/Text-modifying-inline-01.demo.php',
         // Terminal -> Output -> Text Modifying - Line
         11 => 'Terminal/Output/Text-modifying-line-01.demo.php',

         // ! UI
         // UI - Alert component
         12 => 'UI/Alert-01.demo.php',

         // UI - Menu component
         13 => 'UI/Menu-01.demo.php',
         14 => 'UI/Menu-02.demo.php',
         15 => 'UI/Menu-03.demo.php',
         16 => 'UI/Menu-04.demo.php',
         17 => 'UI/Menu-05.demo.php',
         18 => 'UI/Menu-06.demo.php',

         // UI - Progress component
         19 => 'UI/Progress-01.demo.php',
         20 => 'UI/Progress-02.demo.php',

         // UI - Table component
         21 => 'UI/Table-01.demo.php',

         // UI - Fieldset component
         22 => 'UI/Fieldset-01.demo.php',
      ];

      foreach ($examples as $index => $example) {
         if ($id && $index !== $id) {
            continue;
         }

         $file = 'Bootgly/CLI/demo/' . $example;

         $wait = 3;

         $location = 'projects/' . $file;
         require BOOTGLY_ROOT_DIR . 'projects/' . $file;

         sleep($wait);

         CLI->Terminal->clear();
      }

      return true;
   }
}
