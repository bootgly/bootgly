<?php

use Bootgly\ABI\IO\IPC\Pipe;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers\Pipe as PipeHandler;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatters\JSON as JSONFormatter;
use Bootgly\ACI\Logs\Logger;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Logs as Viewer;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Live log viewer ingests piped records (via the live tap) and tracks filter state',
   test: function () {
      $savedDisplay = Display::$segments;
      $savedTap = Logger::$Tap;

      // @ Route every logger into a pipe (as Monitor mode does)
      $Pipe = new Pipe(STREAM_SOCK_DGRAM);
      $Pipe->open();
      Display::show(Display::NONE);
      Logger::$Tap = new PipeHandler($Pipe);

      new Logger(channel: 'Demo.App')->log(info: 'server healthy');
      new Logger(channel: 'Demo.Auth')->log(notice: 'session refreshed');
      new Logger(channel: 'Demo.App')->log(error: 'boom happened');

      // @ Feed the viewer (no terminal render needed)
      $Input = new Input;
      $Output = new Output;
      $Viewer = new Viewer($Input, $Output);
      $Drain = static function (Pipe $Pipe, Viewer $Viewer): void {
         while (true) {
            $chunk = $Pipe->read(PipeHandler::MAX_FRAME_BYTES);
            if ($chunk === false || $chunk === '') {
               return;
            }
            $Viewer->feed($chunk);
         }
      };
      $Drain($Pipe, $Viewer);

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
      $Drain($Pipe, $Viewer);
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
      $Drain($Pipe, $Viewer);
      yield assert(
         assertion: count($Viewer->Records) === $before + 1
            && str_contains($Viewer->Records[count($Viewer->Records) - 1]->message, "\n"),
         description: 'a multiline message is one record (not split by the pipe); collapsed only at render'
      );

      // @ The master-side parser must bound incomplete input independently of
      // the datagram transport and recover at the next record delimiter.
      $Guarded = new Viewer(new Input, new Output);
      $JSON = new JSONFormatter;
      $Boundary = new Record(Levels::Info, 'Demo.Guard', '');
      $base = $JSON->format($Boundary);
      $Boundary->message = str_repeat(
         'B',
         PipeHandler::MAX_FRAME_BYTES - strlen($base),
      );
      $maxFrame = $JSON->format($Boundary);
      $split = intdiv(strlen($maxFrame), 2);
      $Guarded->feed(substr($maxFrame, 0, $split));
      $Guarded->feed(substr($maxFrame, $split));

      yield assert(
         assertion: strlen($maxFrame) === PipeHandler::MAX_FRAME_BYTES
            && count($Guarded->Records) === 1
            && $Guarded->Records[0]->message === $Boundary->message,
         description: 'a maximum-size frame survives split delivery and decodes once'
      );

      $Guarded->feed(str_repeat('X', PipeHandler::MAX_FRAME_BYTES));
      $Partial = new ReflectionProperty($Guarded, 'partial');
      $Dropping = new ReflectionProperty($Guarded, 'discarding');
      $recovery = $JSON->format(new Record(Levels::Notice, 'Demo.Guard', 'recovered'));
      $Guarded->feed("\n" . $recovery);

      yield assert(
         assertion: $Partial->getValue($Guarded) === ''
            && $Dropping->getValue($Guarded) === false
            && count($Guarded->Records) === 2
            && $Guarded->Records[1]->message === 'recovered',
         description: 'unterminated overflow retains no bytes and resynchronizes at the next delimiter'
      );

      $afterRecovery = count($Guarded->Records);
      $next = $JSON->format(new Record(Levels::Warning, 'Demo.Guard', 'after oversized line'));
      $Guarded->feed(str_repeat('Y', PipeHandler::MAX_FRAME_BYTES) . "\n" . $next);
      yield assert(
         assertion: count($Guarded->Records) === $afterRecovery + 1
            && $Guarded->Records[$afterRecovery]->message === 'after oversized line',
         description: 'a complete oversized line is dropped without hiding the valid frame behind it'
      );

      $Pipe->close();
      Display::show($savedDisplay);
      Logger::$Tap = $savedTap;
   }
);
