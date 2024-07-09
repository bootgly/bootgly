<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components;


use Bootgly\API\Component;

use Bootgly\CLI\Terminal\Output;


class Header extends Component
{
   private Output $Output;

   // * Config
   // ...

   // * Data
   /** @var array<string> */
   public array $font;

   // * Metadata
   private string $output;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;

      // TODO move to resources fonts
      // * Data
      $this->font = [
         'A' => <<<ASCII_ART
       ██████╗ 
      ██╔═══██╗
      ████████║
      ██║   ██║
      ██║   ██║
      ╚═╝   ╚═╝
      ASCII_ART,
         'B' => <<<ASCII_ART
      ███████╗ 
      ██╔═══██╗
      ███████╔╝
      ██╔═══██╗
      ███████╔╝
      ╚══════╝ 
      ASCII_ART,
         'C' => <<<ASCII_ART
      ████████╗
      ██╔═════╝
      ██║      
      ██║      
      ████████╗
      ╚═══════╝
      ASCII_ART,
         'D' => <<<ASCII_ART
      ███████╗ 
      ██╔═══██╗
      ██║   ██║
      ██║   ██║
      ███████╔╝
      ╚══════╝ 
      ASCII_ART,
         'E' => <<<ASCII_ART
      ████████╗
      ██╔═════╝
      ████████╗
      ██╔═════╝
      ████████╗
      ╚═══════╝
      ASCII_ART,
         'F' => <<<ASCII_ART
      ████████╗
      ██╔═════╝
      ████████╗
      ██╔═════╝
      ██║      
      ╚═╝      
      ASCII_ART,
         'G' => <<<ASCII_ART
      ███████╗ 
      ██╔════╝ 
      ██║  ███╗
      ██║   ██║
      ╚██████╔╝
       ╚═════╝ 
      ASCII_ART,
         'H' => <<<ASCII_ART
      ██╗   ██╗
      ██║   ██║
      ████████║
      ██╔═══██║
      ██║   ██║
      ╚═╝   ╚═╝
      ASCII_ART,
         'I' => <<<ASCII_ART
      ████████╗
      ╚══██╔══╝
         ██║   
         ██║   
      ████████╗
      ╚═══════╝
      ASCII_ART,
         'J' => <<<ASCII_ART
       ███████╗
       ╚════██║
            ██║
      ██   ██║ 
      ╚█████╔╝ 
       ╚════╝  
      ASCII_ART,
         'K' => <<<ASCII_ART
      ██╗   ██╗
      ██║  ██╔╝
      █████╔╝  
      ██╔═██╗  
      ██║   ██╗
      ╚═╝   ╚═╝
      ASCII_ART,
         'L' => <<<ASCII_ART
      ██╗      
      ██║      
      ██║      
      ██║      
      ████████╗
      ╚═══════╝
      ASCII_ART,
         'M' => <<<ASCII_ART
      ███   ███╗
      ██║█ █ ██║
      ██║ █  ██║
      ██║    ██║
      ██║    ██║
      ╚═╝    ╚═╝
      ASCII_ART,
         'N' => <<<ASCII_ART
      ██╗   ██╗
      ██║   ██║
      ██║█╗ ██║
      ██║ █╗██║
      ██║  ███║
      ╚═╝  ╚══╝
      ASCII_ART,
         'O' => <<<ASCII_ART
       ██████╗ 
      ██╔═══██╗
      ██║   ██║
      ██║   ██║
      ╚██████╔╝
       ╚═════╝ 
      ASCII_ART,
         'P' => <<<ASCII_ART
      ███████╗ 
      ██╔═══██╗
      ███████╔╝
      ██╔═══╝  
      ██║      
      ╚═╝      
      ASCII_ART,
         'Q' => <<<ASCII_ART
      ██████╗ 
      ██╔═══██╗
      ██║   ██║
      ██║ ▄ ██║
      ╚██████╔╝
       ╚══▀▀═╝ 
      ASCII_ART,
         'R' => <<<ASCII_ART
      ███████╗ 
      ██╔═══██╗
      ███████╔╝
      ██╔═══██╗
      ██║   ██║
      ╚═╝   ╚═╝
      ASCII_ART,
         'S' => <<<ASCII_ART
       ██████╗
      ██╔════╝
      ╚█████╗ 
       ╚═══██╗
      ██████╔╝
      ╚═════╝ 
      ASCII_ART,
         'T' => <<<ASCII_ART
      ████████╗
      ╚══██╔══╝
         ██║   
         ██║   
         ██║   
         ╚═╝   
      ASCII_ART,
         'U' => <<<ASCII_ART
      ██╗   ██╗
      ██║   ██║
      ██║   ██║
      ██║   ██║
       ██████╔╝
       ╚═════╝ 
      ASCII_ART,
         'V' => <<<ASCII_ART
      ██╗   ██╗
      ██║   ██║
      ██║   ██║
      ╚██  ██╔╝
        ╚██╔═╝ 
         ╚═╝   
      ASCII_ART,
         'W' => <<<ASCII_ART
      ██╗    ██╗
      ██║    ██║
      ██║ █╗ ██║
      ██║███╗██║
      ╚███╔███╔╝
       ╚══╝╚══╝ 
      ASCII_ART,
         'X' => <<<ASCII_ART
      ██╗   ██╗
       ██╗ ██╔╝
        ████╔╝ 
       ██║ ██╗ 
      ██║   ██╗
      ╚═╝   ╚═╝
      ASCII_ART,
         'Y' => <<<ASCII_ART
      ██╗   ██╗
      ╚██╗ ██╔╝
       ╚████╔╝ 
        ╚██╔╝  
         ██║   
         ╚═╝   
      ASCII_ART,
         'Z' => <<<ASCII_ART
      ███████▌╗
            ▄██
         ▄███▀ 
      ▄███▀    
      ████████╗
      ╚═══════╝
      ASCII_ART,
      ];

      // * Metadata
      $this->output = '';
   }

   public function generate (string $word, bool $inline = true): self
   {
      $font = $this->font;

      $word = strtoupper($word); // @ Convert to Uppercase
      $lines = explode(PHP_EOL, $font[$word[0]]);
      $padding = max(array_map('strlen', $lines));

      $combinedLetters = '';

      $chars = str_split($word);

      if ($inline) {
         foreach ($lines as $lineIndex => $line) {
            foreach ($chars as $char) {
               $letter = $font[$char];
               $letterLines = explode(PHP_EOL, $letter);
   
               if ( isSet($letterLines[$lineIndex]) ) {
                  $line = $letterLines[$lineIndex];
               } else {
                  $line = str_pad('', strlen($line), ' ', STR_PAD_RIGHT);
               }
   
               $combinedLetters .= $line;
               $combinedLetters .= ' ';
            }
   
            $combinedLetters .= PHP_EOL;
         }
      } else {
         foreach ($chars as $char) {
            $letter = $font[$char];
            $lines = explode(PHP_EOL, $letter);
   
            foreach ($lines as &$line) {
               $line = str_pad($line, $padding, ' ', STR_PAD_RIGHT);
            }
   
            $combinedLetters .= implode(PHP_EOL, $lines);
            $combinedLetters .= PHP_EOL;
         }
      }

      $this->output = $combinedLetters;

      return $this;
   }

   public function render (int $mode = self::WRITE_OUTPUT): Output|string|null
   {
      return match ($mode) {
         self::WRITE_OUTPUT => $this->Output->write($this->output),
         self::RETURN_OUTPUT => $this->output,
         default => null
      };
   }
}
