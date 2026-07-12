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


use const STDOUT;
use function fopen;
use function fwrite;
use function is_resource;
use function is_string;

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Handler;


class Stream extends Handler
{
   // * Config
   /** @var resource|false */
   public $stream;


   /**
    * Write records to a stream (terminal/console by default).
    *
    * @param resource|string $stream A stream resource or a writable stream URI (e.g. `php://stderr`).
    * @param null|Formatter $Formatter Output formatter (defaults to a Line formatter).
    * @param Levels $Level Minimum severity this handler accepts.
    */
   public function __construct ($stream = STDOUT, null|Formatter $Formatter = null, Levels $Level = Levels::Debug)
   {
      parent::__construct($Formatter, $Level);

      // * Config
      $this->stream = is_string($stream) === true
         ? fopen($stream, 'wb')
         : $stream;
   }

   /**
    * Append the formatted record to the stream.
    *
    * @param string $formatted The formatted record.
    * @param Record $Record The source record (unused).
    * @return bool True on success.
    */
   protected function write (string $formatted, Record $Record): bool
   {
      // ? Invalid stream
      if (is_resource($this->stream) === false) {
         return false;
      }

      // :
      return fwrite($this->stream, $formatted) !== false;
   }
}
