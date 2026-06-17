<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Filters\Channel;
use Bootgly\ACI\Logs\Handlers\Stream;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Handler enforces severity threshold and filters; Handlers->push() sets level and dispatches',
   test: function () {
      $saved = Display::$mode;
      Display::$mode = Display::MESSAGE;

      // # Severity threshold
      $stream = fopen('php://temp', 'rb+');
      $Handlers = new Handlers;
      $Handlers->push(new Stream($stream), Levels::Warning);

      $Handlers->handle(new Record(Levels::Info, 'c', 'below'));
      $Handlers->handle(new Record(Levels::Error, 'c', 'above'));

      rewind($stream);
      $output = (string) stream_get_contents($stream);
      yield assert(
         assertion: str_contains($output, 'below') === false,
         description: 'a record below the handler threshold is skipped'
      );
      yield assert(
         assertion: str_contains($output, 'above'),
         description: 'a record at/above the handler threshold is written'
      );

      // # push() sets the level
      $Stream = new Stream(fopen('php://temp', 'rb+'));
      (new Handlers)->push($Stream, Levels::Error);
      yield assert(
         assertion: $Stream->Level === Levels::Error,
         description: 'Handlers->push() overrides the handler level'
      );

      // # Per-handler filters
      $filtered = fopen('php://temp', 'rb+');
      $Filtered = new Stream($filtered);
      $Filtered->Filters->push(new Channel(allowed: ['http']));
      $Pipeline = new Handlers;
      $Pipeline->push($Filtered);

      $Pipeline->handle(new Record(Levels::Info, 'db', 'nope'));
      $Pipeline->handle(new Record(Levels::Info, 'http', 'yep'));

      rewind($filtered);
      $result = (string) stream_get_contents($filtered);
      yield assert(
         assertion: str_contains($result, 'nope') === false && str_contains($result, 'yep'),
         description: 'handler filters gate which records are written'
      );

      Display::$mode = $saved;
   }
);
