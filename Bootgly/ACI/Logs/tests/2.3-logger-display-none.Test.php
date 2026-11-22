<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Logs\Handlers\Memory;
use Bootgly\ACI\Logs\Handlers\Stream;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\Processor;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Display::NONE` silences only a logger's `Stream` handlers: a `File` and a
 * `Memory` handler on the same logger keep writing (LOGS-3). A logger whose
 * local handlers are all streams still returns before resolving anything —
 * its processors never run.
 */
return new Test(
   description: 'Logger->log(): Display::NONE mutes only the Stream handlers',
   test: function () {
      // !
      $saved = Display::$segments;
      $savedTap = Logger::$Tap;
      $savedSinks = Logger::$Sinks;
      Logger::$Tap = null;
      Logger::$Sinks = null;
      $directory = sys_get_temp_dir() . '/bootgly-logtest-none-' . uniqid();
      $path = "{$directory}/app.log";
      $Counter = new class extends Processor {
         public int $calls = 0;

         public function process (Record $Record): Record
         {
            $this->calls++;

            return $Record;
         }
      };

      try {
         Display::show(Display::NONE);

         // @ A stream, a file and memory on one logger
         $stream = fopen('php://temp', 'rb+');
         $Logger = new Logger('app');
         $Logger->Handlers = new Handlers;
         $Logger->Handlers->push(new Stream($stream));
         $Logger->Handlers->push(new File($path));
         $Memory = new Memory;
         $Logger->Handlers->push($Memory);
         $Logger->log(critical: 'kept on disk');

         rewind($stream);
         $streamed = (string) stream_get_contents($stream);
         $written = is_file($path) ? (string) file_get_contents($path) : '';

         yield assert(
            assertion: $Logger->Handlers->unmuted === 2,
            description: 'the handler stack counts the two handlers that are not streams'
         );
         yield assert(
            assertion: $streamed === '',
            description: "the Stream handler stays silent: " . json_encode($streamed)
         );
         yield assert(
            assertion: str_contains($written, 'kept on disk'),
            description: "the File handler still writes under Display::NONE: " . json_encode($written)
         );
         yield assert(
            assertion: count($Memory->Records) === 1 && $Memory->Records[0]->message === 'kept on disk',
            description: 'the Memory handler still records under Display::NONE'
         );

         // @ Streams only: nothing is resolved
         $Quiet = new Logger('quiet');
         $Quiet->Processors->push($Counter);
         $Quiet->log(info: 'dropped');
         yield assert(
            assertion: $Quiet->Handlers->unmuted === 0 && $Counter->calls === 0,
            description: "a logger with only Stream handlers returns before its processors run ({$Counter->calls} calls)"
         );

         // @ The display on: every handler writes, the stream included
         Display::show(Display::MESSAGE);
         $Logger->log(info: 'shown');
         rewind($stream);
         yield assert(
            assertion: str_contains((string) stream_get_contents($stream), 'shown') && count($Memory->Records) === 2,
            description: 'with the display on, the Stream handler writes again beside the others'
         );
      }
      finally {
         Display::$segments = $saved;
         Logger::$Tap = $savedTap;
         Logger::$Sinks = $savedSinks;
         @unlink($path);
         @rmdir($directory);
      }
   }
);
