<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\components;


class Header
{
   // * Data
   public array $letters;


   public function __construct ()
   {
      // TODO move to resources fonts
      // * Data
      $this->letters['A'] = <<<ASCII_ART
       ██████╗ 
      ██╔═══██╗
      ████████║
      ██║   ██║
      ██║   ██║
      ╚═╝   ╚═╝
      ASCII_ART;
      $this->letters['B'] = <<<ASCII_ART
      ███████╗ 
      ██╔═══██╗
      ███████╔╝
      ██╔═══██╗
      ███████╔╝
      ╚══════╝ 
      ASCII_ART;
      $this->letters['C'] = <<<ASCII_ART
      ████████╗
      ██╔═════╝
      ██║      
      ██║      
      ████████╗
      ╚═══════╝
      ASCII_ART;
      $this->letters['D'] = <<<ASCII_ART
      ███████╗ 
      ██╔═══██╗
      ██║   ██║
      ██║   ██║
      ███████╔╝
      ╚══════╝ 
      ASCII_ART;
      $this->letters['E'] = <<<ASCII_ART
      ████████╗
      ██╔═════╝
      ████████╗
      ██╔═════╝
      ████████╗
      ╚═══════╝
      ASCII_ART;
      $this->letters['F'] = <<<ASCII_ART
      ████████╗
      ██╔═════╝
      ████████╗
      ██╔═════╝
      ██║      
      ╚═╝      
      ASCII_ART;
      $this->letters['G'] = <<<ASCII_ART
      ███████╗ 
      ██╔════╝ 
      ██║  ███╗
      ██║   ██║
      ╚██████╔╝
       ╚═════╝ 
      ASCII_ART;
      $this->letters['H'] = <<<ASCII_ART
      ██╗   ██╗
      ██║   ██║
      ████████║
      ██╔═══██║
      ██║   ██║
      ╚═╝   ╚═╝
      ASCII_ART;
      $this->letters['I'] = <<<ASCII_ART
      ████████╗
      ╚══██╔══╝
         ██║   
         ██║   
      ████████╗
      ╚═══════╝
      ASCII_ART;
      $this->letters['J'] = <<<ASCII_ART
       ███████╗
       ╚════██║
            ██║
      ██   ██║ 
      ╚█████╔╝ 
       ╚════╝  
      ASCII_ART;
      $this->letters['K'] = <<<ASCII_ART
      ██╗   ██╗
      ██║  ██╔╝
      █████╔╝  
      ██╔═██╗  
      ██║   ██╗
      ╚═╝   ╚═╝
      ASCII_ART;
      $this->letters['L'] = <<<ASCII_ART
      ██╗      
      ██║      
      ██║      
      ██║      
      ████████╗
      ╚═══════╝
      ASCII_ART;
      $this->letters['M'] = <<<ASCII_ART
      ███   ███╗
      ██║█ █ ██║
      ██║ █  ██║
      ██║    ██║
      ██║    ██║
      ╚═╝    ╚═╝
      ASCII_ART;
      $this->letters['N'] = <<<ASCII_ART
      ██╗   ██╗
      ██║   ██║
      ██║█╗ ██║
      ██║ █╗██║
      ██║  ███║
      ╚═╝  ╚══╝
     ASCII_ART;
      $this->letters['O'] = <<<ASCII_ART
       ██████╗ 
      ██╔═══██╗
      ██║   ██║
      ██║   ██║
      ╚██████╔╝
       ╚═════╝ 
      ASCII_ART;
      $this->letters['P'] = <<<ASCII_ART
      ███████╗ 
      ██╔═══██╗
      ███████╔╝
      ██╔═══╝  
      ██║      
      ╚═╝      
      ASCII_ART;
      $this->letters['Q'] = <<<ASCII_ART
      ██████╗ 
      ██╔═══██╗
      ██║   ██║
      ██║ ▄ ██║
      ╚██████╔╝
       ╚══▀▀═╝ 
      ASCII_ART;
      $this->letters['R'] = <<<ASCII_ART
      ███████╗ 
      ██╔═══██╗
      ███████╔╝
      ██╔═══██╗
      ██║   ██║
      ╚═╝   ╚═╝
      ASCII_ART;
      $this->letters['S'] = <<<ASCII_ART
       ██████╗
      ██╔════╝
      ╚█████╗ 
       ╚═══██╗
      ██████╔╝
      ╚═════╝ 
      ASCII_ART;
      $this->letters['T'] = <<<ASCII_ART
      ████████╗
      ╚══██╔══╝
         ██║   
         ██║   
         ██║   
         ╚═╝   
      ASCII_ART;
      $this->letters['U'] = <<<ASCII_ART
      ██╗   ██╗
      ██║   ██║
      ██║   ██║
      ██║   ██║
       ██████╔╝
       ╚═════╝ 
      ASCII_ART;
      $this->letters['V'] = <<<ASCII_ART
      ██╗   ██╗
      ██║   ██║
      ██║   ██║
      ╚██  ██╔╝
        ╚██╔═╝ 
         ╚═╝   
      ASCII_ART;
      $this->letters['W'] = <<<ASCII_ART
      ██╗    ██╗
      ██║    ██║
      ██║ █╗ ██║
      ██║███╗██║
      ╚███╔███╔╝
       ╚══╝╚══╝ 
      ASCII_ART;
      $this->letters['X'] = <<<ASCII_ART
      ██╗   ██╗
       ██╗ ██╔╝
        ████╔╝ 
       ██║ ██╗ 
      ██║   ██╗
      ╚═╝   ╚═╝
      ASCII_ART;
      $this->letters['Y'] = <<<ASCII_ART
      ██╗   ██╗
      ╚██╗ ██╔╝
       ╚████╔╝ 
        ╚██╔╝  
         ██║   
         ╚═╝   
      ASCII_ART;
      $this->letters['Z'] = <<<ASCII_ART
      ███████▌╗
            ▄██
         ▄███▀ 
      ▄███▀    
      ████████╗
      ╚═══════╝
      ASCII_ART;
   }

   public function generate (string $word, bool $inline = true) : string
   {
      $letters = $this->letters;

      $word = strtoupper($word); // @ Convert to Uppercase
      $lines = explode(PHP_EOL, $letters[$word[0]]);
      $padding = max(array_map('strlen', $lines));

      $combinedLetters = '';

      $chars = str_split($word);

      if ($inline) {
         foreach ($lines as $lineIndex => $line) {
            foreach ($chars as $char) {
               $letter = $letters[$char];
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
            $letter = $letters[$char];
            $lines = explode(PHP_EOL, $letter);
   
            foreach ($lines as &$line) {
               $line = str_pad($line, $padding, ' ', STR_PAD_RIGHT);
            }
   
            $combinedLetters .= implode(PHP_EOL, $lines);
            $combinedLetters .= PHP_EOL;
         }
      }

      return $combinedLetters;
   }
}
