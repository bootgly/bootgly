<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use function array_key_last;
use function ctype_digit;
use function sleep;

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
      // ! Ids stay strings so sub-examples can float (50, 50.1, 50.2, ...); whole
      //   numbers normalize (leading zeros collapse) to keep `demo 05` == `demo 5`
      $id = $arguments[0] ?? null;
      if ($id !== null && ctype_digit($id) === true) {
         $id = (string) (int) $id;
      }

      // @
      $Output = CLI->Terminal->Output;
      $Output->expand(lines: CLI->Terminal::$lines);

      // @ Reset Output
      if ($id === '0') {
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

         // Terminal Reporting - Mouse
         23 => 'Terminal/Reporting/Mouse-01.demo.php',

         // Terminal -> Output -> Viewport Panning
         24 => 'Terminal/Output/Viewport-01.demo.php',

         // UI - Logs component
         25 => 'UI/Logs-01.demo.php',

         // UI - Question component (yes/no confirmation)
         26 => 'UI/Question-04.demo.php',

         // UI - Question component
         27 => 'UI/Question-01.demo.php',

         // UX - Form component
         28 => 'UX/Form-01.demo.php',

         // UI - Question component (masked input)
         29 => 'UI/Question-02.demo.php',

         // UI - Menu component (viewport + type-ahead)
         30 => 'UI/Menu-07.demo.php',

         // UI - Menu component (grid columns)
         31 => 'UI/Menu-08.demo.php',

         // UI - Spinner component
         32 => 'UI/Spinner-01.demo.php',

         // UI - Timer component
         33 => 'UI/Timer-01.demo.php',

         // UI - Timeline component
         34 => 'UI/Timeline-01.demo.php',

         // UI - Progress component (multi-bar grid)
         35 => 'UI/Progress-03.demo.php',

         // UI - Chart component
         36 => 'UI/Chart-01.demo.php',

         // UI - Text component
         37 => 'UI/Text-01.demo.php',

         // UI - Question component (autocomplete suggestions)
         38 => 'UI/Question-03.demo.php',

         // UI - Textarea component
         39 => 'UI/Textarea-01.demo.php',

         // UX - Prompt component
         40 => 'UX/Prompt-01.demo.php',

         // UI - Scrollarea component
         41 => 'UI/Scrollarea-01.demo.php',

         // UI - Chart components (Bars)
         42 => 'UI/Chart-02.demo.php',

         // UI - Chart components (Meter)
         43 => 'UI/Chart-03.demo.php',

         // UI - Chart components (live Graph)
         44 => 'UI/Chart-04.demo.php',

         // UI - Frame component
         45 => 'UI/Frame-01.demo.php',

         // UI - Grid component (btop-like dashboard)
         46 => 'UI/Grid-01.demo.php',

         // UX - Tabs component (btop-like tabbed dashboard)
         47 => 'UX/Tabs-01.demo.php',

         // UX - Wizard component (declarative multi-step flow)
         48 => 'UX/Wizard-01.demo.php',

         // UX - Dialog component (modal over covered frames)
         49 => 'UX/Dialog-01.demo.php',

         // UX - Toasts component (corner toast notifications)
         50 => 'UX/Toasts-01.demo.php',
         // UX - Toasts component (position variations)
         '50.1' => 'UX/Toasts-02.demo.php', // TopLeft
         '50.2' => 'UX/Toasts-03.demo.php', // Center
         '50.3' => 'UX/Toasts-04.demo.php', // BottomRight

         // UI - Tree component (hierarchical picker with lazy children)
         51 => 'UI/Tree-01.demo.php',

         // UX - Filepicker component (filesystem browser with lazy scans)
         52 => 'UX/Filepicker-01.demo.php',

         // UX - Finder component (live search selector)
         53 => 'UX/Finder-01.demo.php',
      ];

      $last = array_key_last($examples);
      foreach ($examples as $index => $example) {
         if ($id !== null && (string) $index !== $id) {
            continue;
         }

         $file = "Demo/CLI/$example";
         $location = "projects/$file";
         require BOOTGLY_ROOT_DIR . $location;

         // ? Pause and clear only between chained demos: single runs keep their output
         if ($id === null && $index !== $last) {
            sleep(3);

            CLI->Terminal->clear();
         }
      }

      return true;
   }
}
