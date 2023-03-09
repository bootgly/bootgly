<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal;


use Bootgly\CLI;

use Bootgly\CLI\Terminal\Output\Cursor;


class Output
{
   // * Config
   // @ Delay
   public int $wait;
   public int $waiting;
   // * Data
   public $stream;
   // * Meta
   // @ Stats
   public int|false $written;


   public Cursor $Cursor;


   public function __construct ($stream = STDOUT)
   {
      // * Config
      // @ Delay
      $this->wait = -1;
      $this->waiting = 50000;
      // * Data
      $this->stream = $stream;
      // * Meta
      // @ Stats
      $this->written = 0;

      $this->Cursor = new Cursor($this);
   }

   public function write (string $data) : self
   {
      $this->written = fwrite($this->stream, $data);

      if ($this->wait > 0) {
         sleep($this->wait);
      }

      return $this;
   }
   public function writing (string $text) : self
   {
      $stream = $this->stream;
      $waiting = $this->waiting;
      $parts = str_split($text);
      $written = 0;

      foreach ($parts as $part) {
         $written += fwrite($stream, $part);

         if ($waiting > 0) {
            usleep($waiting);
         }
      }

      $this->written += $written;

      return $this;
   }
   public function append (string $text) : self
   {
      $this->written = fwrite($this->stream, $text . PHP_EOL);

      return $this;
   }
}
