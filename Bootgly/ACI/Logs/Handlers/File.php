<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
use function preg_replace;
use function str_contains;
use function str_replace;

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Handler;
use Bootgly\ACI\Logs\Handlers\File\Rotation;


class File extends Handler
{
   // * Config
   public string $path;
   public Rotation $Rotation;


   /**
    * Write records to a file, rotating it per the rotation policy.
    *
    * @param string $path Destination log file path. A `{channel}` placeholder is replaced per record
    *                     with the record's channel, writing one file per module (e.g. `logs/{channel}.log`).
    * @param null|Formatter $Formatter Output formatter (defaults to JSON — structured, ANSI-free lines).
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
      // ? Files default to JSON: no ANSI, and independent of the terminal Display segments
      parent::__construct($Formatter ?? new JSON, $Level);

      // * Config
      $this->path = $path;
      $this->Rotation = $Rotation ?? new Rotation;
   }

   /**
    * Ensure the directory, rotate if due, then append the formatted record.
    *
    * @param string $formatted The formatted record.
    * @param Record $Record The source record (supplies the channel for the `{channel}` placeholder).
    * @return bool True on success.
    */
   protected function write (string $formatted, Record $Record): bool
   {
      // ? Resolve the {channel} placeholder per record (sanitized — no path traversal)
      $path = $this->path;
      if (str_contains($path, '{channel}') === true) {
         $channel = preg_replace('/[^A-Za-z0-9._-]/', '_', $Record->channel) ?? '';
         $path = str_replace('{channel}', $channel !== '' ? $channel : 'default', $path);
      }

      // ? Ensure destination directory
      $directory = dirname($path);
      if (is_dir($directory) === false) {
         mkdir($directory, 0o775, true);
      }

      // @ Rotate when due
      $this->Rotation->rotate($path);

      // :
      return file_put_contents($path, $formatted, FILE_APPEND | LOCK_EX) !== false;
   }
}
