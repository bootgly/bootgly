<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Progress;


use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\components\Progress;


class Bar
{
   private Progress $Progress;

   // * Config
   public int $units;

   // * Data
   public Bar\Symbols $Symbols;

   // * Meta
   // ...


   public function __construct (Progress $Progress)
   {
      $this->Progress = $Progress;


      // * Config
      $this->units = Terminal::$width / 2;

      // * Data
      $this->Symbols = new Bar\Symbols;

      // * Meta
      // ...
   }
   public function __get ($name)
   {
      return $this->Progress->$name;
   }

   public function render () : string
   {
      $units = $this->units;

      // done
      $done = $units * ($this->Progress->percent / 100);
      if ($done > $units) {
         $done = $units;
      }
      // left
      $left = $units - $done;

      // @ Construct symbols
      $Symbols = $this->Symbols;
      // incomplete(s)
      $incomplete = $Symbols->incomplete;
      $incompletes = [];
      for ($i = 0; $i < $left; $i++) {
         $incompletes[] = $incomplete;
      }
      // current
      $current = $Symbols->current;
      // complete(s)
      $complete = $Symbols->complete;
      $completes = [];
      for ($i = 0; $i < $done; $i++) {
         $completes[] = $complete;
      }

      $complete = implode('', $completes);
      $incomplete = implode('', $incompletes);

      return $complete . $current . $incomplete;
   }
}



namespace Bootgly\CLI\Terminal\components\Progress\Bar;

class Symbols
{
   public string $incomplete   = ' ';
   public string $current      = '>';
   public string $complete     = '=';

   public string $indetermined = '-';
}
