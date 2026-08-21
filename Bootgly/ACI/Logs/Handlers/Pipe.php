<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Handlers;


use const STREAM_SOCK_DGRAM;
use function strlen;
use function str_ends_with;
use InvalidArgumentException;

use Bootgly\ABI\IO\IPC\Pipe as IPCPipe;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Handler;


class Pipe extends Handler
{
   // One complete newline-delimited JSON record per Monitor datagram.
   public const int MAX_FRAME_BYTES = 65536;


   // * Config
   public IPCPipe $Pipe;


   /**
    * Stream records over an IPC pipe as newline-delimited JSON — used to funnel worker logs to the
    * master process for the live viewer.
    *
    * @param IPCPipe $Pipe An opened pipe (the master reads, this handler writes).
    * @param null|Formatter $Formatter Output formatter (defaults to JSON for structured transport).
    * The formatter must emit one newline-terminated frame no larger than MAX_FRAME_BYTES.
    * @param Levels $Level Minimum severity this handler accepts.
    * @throws InvalidArgumentException When the pipe is not an atomic datagram transport.
    */
   public function __construct (IPCPipe $Pipe, null|Formatter $Formatter = null, Levels $Level = Levels::Debug)
   {
      if ($Pipe->type !== STREAM_SOCK_DGRAM) {
         throw new InvalidArgumentException('The Monitor log handler requires a datagram IPC pipe.');
      }

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
      $length = strlen($formatted);

      // ? A frame must fit one receive operation and carry its delimiter.
      //   Oversized records are dropped before any byte reaches the channel.
      if (
         $length < 1
         || $length > self::MAX_FRAME_BYTES
         || str_ends_with($formatted, "\n") === false
      ) {
         return false;
      }

      // : Datagram writes are all-or-nothing. `0` is backpressure, not success.
      return $this->Pipe->write($formatted, $length) === $length;
   }
}
