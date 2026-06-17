<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Handlers;


use const FILE_APPEND;
use const LOCK_EX;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;

use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Handler;
use Bootgly\ACI\Logs\Handlers\File\Rotation;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;


class File extends Handler
{
   // * Config
   public string $path;
   public Rotation $Rotation;


   /**
    * Write records to a file, rotating it per the rotation policy.
    *
    * @param string $path Destination log file path.
    * @param null|Formatter $Formatter Output formatter (defaults to a Line formatter).
    * @param Levels $Level Minimum severity this handler accepts.
    * @param null|Rotation $Rotation Rotation policy (defaults to daily + 10MB, keep 7).
    */
   public function __construct (
      string $path,
      null|Formatter $Formatter = null,
      Levels $Level = Levels::Debug,
      null|Rotation $Rotation = null
   )
   {
      parent::__construct($Formatter, $Level);

      // * Config
      $this->path = $path;
      $this->Rotation = $Rotation ?? new Rotation;
   }

   /**
    * Ensure the directory, rotate if due, then append the formatted record.
    *
    * @param string $formatted The formatted record.
    * @param Record $Record The source record (unused).
    * @return bool True on success.
    */
   protected function write (string $formatted, Record $Record): bool
   {
      // ? Ensure destination directory
      $directory = dirname($this->path);
      if (is_dir($directory) === false) {
         mkdir($directory, 0o775, true);
      }

      // @ Rotate when due
      $this->Rotation->rotate($this->path);

      // :
      return file_put_contents($this->path, $formatted, FILE_APPEND | LOCK_EX) !== false;
   }
}
