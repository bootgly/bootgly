<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Handlers\Stream;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\Processors\PID;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Logger->log() resolves the named level, enriches via processors and dispatches to handlers',
   test: function () {
      $saved = Display::$segments;
      Display::show(Display::MESSAGE);

      // # Dispatch to a captured stream
      $stream = fopen('php://temp', 'rb+');
      $Logger = new Logger('app');
      $Logger->Handlers = new Handlers;
      $Logger->Handlers->push(new Stream($stream));

      $Logger->log(info: 'hello world');

      rewind($stream);
      $output = (string) stream_get_contents($stream);

      yield assert(
         assertion: str_contains($output, 'hello world'),
         description: 'a named-level call reaches the handler output'
      );

      // # Context + processor land on the record (verified via JSON)
      $structured = fopen('php://temp', 'rb+');
      $Structured = new Logger('app');
      $Structured->Handlers = new Handlers;
      $Structured->Handlers->push(new Stream($structured, new JSON));
      $Structured->Processors->push(new PID);

      $Structured->log(error: 'boom', context: ['user' => 7]);

      rewind($structured);
      $decoded = json_decode(trim((string) stream_get_contents($structured)), true);

      yield assert(
         assertion: is_array($decoded)
            && $decoded['level'] === 'ERROR'
            && $decoded['message'] === 'boom'
            && $decoded['context']['user'] === 7
            && isset($decoded['extra']['pid']),
         description: 'level, context and processor-enriched extra are carried into the record'
      );

      // # Multiple levels in one call → one record each, in call order
      $multi = fopen('php://temp', 'rb+');
      $Multi = new Logger('app');
      $Multi->Handlers = new Handlers;
      $Multi->Handlers->push(new Stream($multi, new JSON));

      $Multi->log(info: 'first', error: 'second', context: ['shared' => true]);

      rewind($multi);
      $lines = array_values(array_filter(explode(PHP_EOL, trim((string) stream_get_contents($multi)))));
      $first = json_decode($lines[0] ?? '', true);
      $second = json_decode($lines[1] ?? '', true);

      yield assert(
         assertion: count($lines) === 2
            && $first['level'] === 'INFO' && $first['message'] === 'first'
            && $second['level'] === 'ERROR' && $second['message'] === 'second'
            && $first['context']['shared'] === true && $second['context']['shared'] === true,
         description: 'each level emits its own record, in order, sharing the context'
      );

      // # DISPLAY_NONE suppresses output entirely
      Display::show(Display::NONE);
      $silent = fopen('php://temp', 'rb+');
      $Silent = new Logger('x');
      $Silent->Handlers = new Handlers;
      $Silent->Handlers->push(new Stream($silent));

      $Silent->log(info: 'should not appear');

      rewind($silent);

      yield assert(
         assertion: stream_get_contents($silent) === '',
         description: 'DISPLAY_NONE suppresses all output'
      );

      // # Global sinks: opt-in per logger ($global), fan out to all sinks even under DISPLAY_NONE
      $savedSinks = Logger::$Sinks;
      $sinkA = fopen('php://temp', 'rb+');
      $sinkB = fopen('php://temp', 'rb+');
      Logger::$Sinks = new Handlers;
      Logger::$Sinks->push(new Stream($sinkA, new JSON));
      Logger::$Sinks->push(new Stream($sinkB, new JSON));

      Display::show(Display::NONE);                                 // local handlers muted
      new Logger('opted', global: true)->log(warning: 'fan out');  // opts in → reaches the sinks
      new Logger('plain')->log(warning: 'not global');             // default → stays out

      rewind($sinkA);
      rewind($sinkB);
      $sunkA = (string) stream_get_contents($sinkA);
      $sunkB = (string) stream_get_contents($sinkB);
      yield assert(
         assertion: str_contains($sunkA, 'fan out') && str_contains($sunkB, 'fan out'),
         description: 'an opted-in (global) logger fans out to every global sink, even under DISPLAY_NONE'
      );
      yield assert(
         assertion: str_contains($sunkA, 'not global') === false,
         description: 'a logger without opt-in does not reach the global sinks'
      );

      Logger::$Sinks = $savedSinks;

      Display::show($saved);
   }
);
