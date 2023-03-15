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


use Bootgly\CLI;
use Bootgly\CLI\Escaping;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\components\Progress;


class Bar
{
   private Progress $Progress;

   // * Config
   // @ units
   public int $units;
   // @ symbols
   public array $symbols;
   // * Data


   public function __construct (Progress $Progress)
   {
      $this->Progress = $Progress;

      // * Config
      // @ units
      $this->units = Terminal::$width / 2;
      // @ symbols
      $this->symbols = [
         'determined'   => [' ', '>', '='], // determined
         'indetermined' => ['?']            // indetermined
      ];
   }
   public function __get ($name)
   {
      return $this->Progress->$name;
   }

   public function render () : string
   {
      $units = $this->units;

      // @ done
      $done = $units * ($this->percent / 100);
      if ($done > $units) {
         $done = $units;
      }
      // @ left
      $left = $units - $done;

      // @ Construct symbols
      $symbols = $this->symbols['determined'];
      // incomplete
      $incomplete = $symbols[0];

      $symbolsIncomplete = [];

      for ($i = 0; $i < $left; $i++) {
         $symbolsIncomplete[] = $incomplete;
      }
      // current
      // ...
      // complete
      $symbolsComplete = [];

      if ($this->ticks <= 0) {
         for ($i = 0; $i < $done; $i++) {
            $symbolsComplete[] = $incomplete;
         }
      } else {
         for ($i = 0; $i < $done; $i++) {
            $symbolsComplete[] = $symbols[2];
         }
      }

      $bar = implode('', $symbolsComplete) . $symbols[1] . implode('', $symbolsIncomplete);

      return $bar;
   }
}
