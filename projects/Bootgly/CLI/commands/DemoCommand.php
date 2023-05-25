<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Bootgly\CLI\commands;


use Bootgly\CLI;
use Bootgly\CLI\ { Command, Commanding };


class DemoCommand extends Command implements Commanding
{
   public string $name = 'demo';
   public string $description = 'Run the CLI demo';


   public function run (array $arguments, array $options) : bool
   {
      $Output = CLI::$Terminal->Output;
      $Output->expand(lines: CLI::$Terminal::$lines);

      $examples = [
         // ! Terminal
         // ? Input
         'Terminal/Input/@reading-01.example.php',
      
         // ? Output
         // Terminal -> Output @ writing
         'Terminal/Output/@writing-01.example.php',
      
      
         // Terminal -> Output -> Cursor Positioning
         'Terminal/Output/Cursor-positioning-01.example.php',
         // Terminal -> Output -> Cursor Shaping
         'Terminal/Output/Cursor-shaping-01.example.php',
         // Terminal -> Output -> Cursor Visualizing
         'Terminal/Output/Cursor-visualizing-01.example.php',
      
      
         // Terminal -> Output -> Text Formatting - Coloring
         'Terminal/Output/Text-formatting-coloring-01.example.php',
         // Terminal -> Output -> Text Formatting - Styling
         'Terminal/Output/Text-formatting-styling-01.example.php',
      
         // Terminal -> Output -> Text Modifying
         'Terminal/Output/Text-modifying-01.example.php',
         // Terminal -> Output -> Text Modifying - In Display
         'Terminal/Output/Text-modifying-indisplay-01.example.php',
         // Terminal -> Output -> Text Modifying - Inline
         'Terminal/Output/Text-modifying-inline-01.example.php',
         // Terminal -> Output -> Text Modifying - Line
         'Terminal/Output/Text-modifying-line-01.example.php',
      
      
         // Terminal -> components - Alert component
         'Terminal/components/Alert-01.example.php',
      
         // Terminal -> components - Menu component
         'Terminal/components/Menu-01.example.php',
         'Terminal/components/Menu-02.example.php',
         'Terminal/components/Menu-03.example.php',
         'Terminal/components/Menu-04.example.php',
         'Terminal/components/Menu-05.example.php',
         'Terminal/components/Menu-06.example.php',
      
         // Terminal -> components - Progress component
         'Terminal/components/Progress-01.example.php',
         'Terminal/components/Progress-02.example.php',
      
         // Terminal -> components - Table component
         'Terminal/components/Table-01.example.php'
      ];
      
      foreach ($examples as $index => $example) {
         $file = 'Bootgly/CLI/examples/' . $example;
      
         $wait = 3;
         $location = 'projects/' . $file;
      
         require BOOTGLY_DIR . $location;
      
         sleep($wait);
      
         CLI::$Terminal->clear();
      }

      return true;
   }
}
