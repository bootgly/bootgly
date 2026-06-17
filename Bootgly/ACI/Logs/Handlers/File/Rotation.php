<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Handlers\File;


use function date;
use function filemtime;
use function filesize;
use function is_file;
use function rename;
use function unlink;


class Rotation
{
   // * Config
   public int $size;
   public bool $daily;
   public int $keep;


   /**
    * File rotation policy: rotates on size cap OR day change, whichever comes first.
    *
    * @param int $size Size cap in bytes that triggers rotation (0 disables size rotation). Default 10MB.
    * @param bool $daily Rotate when the file's last-modified day differs from today.
    * @param int $keep Number of rotated archives to retain.
    */
   public function __construct (int $size = 10485760, bool $daily = true, int $keep = 7)
   {
      // * Config
      $this->size = $size;
      $this->daily = $daily;
      $this->keep = $keep;
   }

   /**
    * Rotate the file when the size cap is exceeded or the day has changed.
    *
    * Archives are numbered `path.1` … `path.{keep}`, oldest dropped.
    *
    * @param string $path The active log file path.
    */
   public function rotate (string $path): void
   {
      // ? Nothing to rotate yet
      if (is_file($path) === false) {
         return;
      }

      // ? Decide whether rotation is due
      $rotate = false;
      if ($this->size > 0 && filesize($path) >= $this->size) {
         $rotate = true;
      }
      if ($this->daily === true && date('Y-m-d', (int) filemtime($path)) !== date('Y-m-d')) {
         $rotate = true;
      }
      if ($rotate === false) {
         return;
      }

      // @ Drop the oldest archive
      $oldest = "$path.$this->keep";
      if (is_file($oldest) === true) {
         unlink($oldest);
      }

      // @ Shift archives up: path.{n} -> path.{n+1}
      for ($index = $this->keep - 1; $index >= 1; $index--) {
         $source = "$path.$index";
         if (is_file($source) === true) {
            rename($source, "$path." . ($index + 1));
         }
      }

      // @ Move the active file to path.1
      rename($path, "$path.1");
   }
}
