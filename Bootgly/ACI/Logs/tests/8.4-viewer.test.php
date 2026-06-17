<?php

use Bootgly\ABI\IO\IPC\Pipe;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers\Pipe as PipeHandler;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Logger;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Logs as Viewer;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Live log viewer ingests piped records (via the global sink) and tracks filter state',
   test: function () {
      $savedDisplay = Display::$mode;
      $savedSink = Logger::$Sink;

      // @ Route every logger into a pipe (as Monitor mode does)
      $Pipe = new Pipe;
      $Pipe->open();
      Display::$mode = Display::NONE;
      Logger::$Sink = new PipeHandler($Pipe);

      new Logger(channel: 'Demo.App')->log(info: 'server healthy');
      new Logger(channel: 'Demo.Auth')->log(notice: 'session refreshed');
      new Logger(channel: 'Demo.App')->log(error: 'boom happened');

      $chunk = (string) $Pipe->read(65536);

      // @ Feed the viewer (no terminal render needed)
      $Input = new Input;
      $Output = new Output;
      $Viewer = new Viewer($Input, $Output);
      $Viewer->feed($chunk);

      yield assert(
         assertion: count($Viewer->Records) === 3,
         description: 'three records are ingested from the pipe'
      );

      yield assert(
         assertion: ($Viewer->channels['Demo.App'] ?? false) === true
            && isset($Viewer->channels['Demo.Auth']),
         description: 'channels are discovered and tracked'
      );

      // @ Level threshold cycles stricter (Debug → Info)
      $Viewer->control('l');
      yield assert(
         assertion: $Viewer->level === Levels::Info,
         description: 'pressing "l" cycles the severity threshold'
      );

      // @ Incremental search captures typed characters
      $Viewer->control('/');
      foreach (['b', 'o', 'o', 'm'] as $character) {
         $Viewer->control($character);
      }
      yield assert(
         assertion: $Viewer->searching === true && $Viewer->search === 'boom',
         description: 'search sub-mode captures the typed term'
      );

      // @ Pause freezes a snapshot; the buffer still ingests new records behind it
      $Viewer->searching = false;
      $Viewer->search = '';
      $Viewer->level = Levels::Debug;
      $Viewer->control(' ');                       // pause
      yield assert(
         assertion: $Viewer->paused === true,
         description: 'space pauses (freezes a snapshot)'
      );

      $frozen = count($Viewer->Records);
      new Logger(channel: 'Demo.App')->log(info: 'hidden while paused');
      $Viewer->feed((string) $Pipe->read(65536));
      yield assert(
         assertion: count($Viewer->Records) > $frozen,
         description: 'paused: the buffer keeps ingesting new records behind the frozen view'
      );

      // @ Selection cursor moves through the frozen snapshot
      $cursor = $Viewer->cursor;                   // starts at the newest
      $Viewer->control("\e[A");                    // UP
      yield assert(
         assertion: $Viewer->cursor === $cursor - 1,
         description: 'arrow up moves the selection cursor'
      );

      // @ Enter expands the selected record; Esc closes the detail view
      $Viewer->control("\n");                      // ENTER
      yield assert(
         assertion: $Viewer->Detail !== null,
         description: 'Enter opens the detail view of the selected record'
      );
      $Viewer->control("\e");                      // ESC
      yield assert(
         assertion: $Viewer->Detail === null,
         description: 'Esc closes the detail view'
      );

      // @ Resume returns to the live tail
      $Viewer->control(' ');
      yield assert(
         assertion: $Viewer->paused === false,
         description: 'space resumes live tailing'
      );

      // @ A multiline message (e.g. an exception) arrives as ONE intact record
      $before = count($Viewer->Records);
      new Logger(channel: 'Demo.App')->log(error: "boom\nstack line 1\nstack line 2");
      $Viewer->feed((string) $Pipe->read(65536));
      yield assert(
         assertion: count($Viewer->Records) === $before + 1
            && str_contains($Viewer->Records[count($Viewer->Records) - 1]->message, "\n"),
         description: 'a multiline message is one record (not split by the pipe); collapsed only at render'
      );

      $Pipe->close();
      Display::$mode = $savedDisplay;
      Logger::$Sink = $savedSink;
   }
);
