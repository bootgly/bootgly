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


use Bootgly\ABI\IO\IPC\Pipe as IPCPipe;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Handler;


class Pipe extends Handler
{
   // * Config
   public IPCPipe $Pipe;


   /**
    * Stream records over an IPC pipe as newline-delimited JSON — used to funnel worker logs to the
    * master process for the live viewer.
    *
    * @param IPCPipe $Pipe An opened pipe (the master reads, this handler writes).
    * @param null|Formatter $Formatter Output formatter (defaults to JSON for structured transport).
    * @param Levels $Level Minimum severity this handler accepts.
    */
   public function __construct (IPCPipe $Pipe, null|Formatter $Formatter = null, Levels $Level = Levels::Debug)
   {
      parent::__construct($Formatter ?? new JSON, $Level);

      // * Config
      $this->Pipe = $Pipe;
   }

   /**
    * Write the serialized record to the pipe's write end.
    *
    * @param string $formatted The formatted record (JSON line).
    * @param Record $Record The source record (unused).
    * @return bool True on success (false when the pipe buffer is full — record dropped).
    */
   protected function write (string $formatted, Record $Record): bool
   {
      // :
      return $this->Pipe->write($formatted) !== false;
   }
}
