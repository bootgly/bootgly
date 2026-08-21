<?php

use Bootgly\ABI\IO\IPC\Pipe as IPCPipe;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers\Pipe as PipeHandler;
use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test;

return new Test(
   description: 'Pipe handler streams JSON records through an IPC pipe; the reader reconstructs them',
   test: function () {
      $saved = Display::$segments;
      Display::show(Display::MESSAGE);

      $streamRejected = false;
      try {
         new PipeHandler(new IPCPipe);
      }
      catch (InvalidArgumentException) {
         $streamRejected = true;
      }
      yield assert(
         assertion: $streamRejected,
         description: 'the log handler rejects non-atomic stream transport'
      );

      $Pipe = new IPCPipe(STREAM_SOCK_DGRAM);

      yield assert(
         assertion: $Pipe->open() === true,
         description: 'the IPC pipe opens'
      );

      $Logger = new Logger('worker');
      $Logger->Handlers = new Handlers;
      $Logger->Handlers->push(new PipeHandler($Pipe));

      $Logger->log(error: 'boom', context: ['user' => 7]);

      // @ Read the serialized record back off the pipe
      $raw = (string) $Pipe->read(65536);
      $decoded = json_decode(trim($raw), true);
      $Record = Record::import($decoded);

      yield assert(
         assertion: $Record->Level === Levels::Error
            && $Record->channel === 'worker'
            && $Record->message === 'boom'
            && $Record->context['user'] === 7,
         description: 'a record written to the pipe round-trips back to a Record'
      );

      $Formatter = new class implements Formatter {
         public string $formatted = '';

         public function format (Record $Record): string
         {
            return $this->formatted;
         }
      };
      $Boundary = new PipeHandler($Pipe, $Formatter);
      $Source = new Record(Levels::Info, 'worker', 'boundary');

      $Formatter->formatted = str_repeat('F', PipeHandler::MAX_FRAME_BYTES - 1) . "\n";
      $accepted = $Boundary->handle($Source);
      $frame = $Pipe->read(PipeHandler::MAX_FRAME_BYTES);
      yield assert(
         assertion: $accepted === true && $frame === $Formatter->formatted,
         description: 'the maximum complete datagram frame is accepted atomically'
      );

      $Formatter->formatted = str_repeat('F', PipeHandler::MAX_FRAME_BYTES) . "\n";
      $oversized = $Boundary->handle($Source);
      $Formatter->formatted = str_repeat('F', 32);
      $unterminated = $Boundary->handle($Source);
      $rejected = $Pipe->read(PipeHandler::MAX_FRAME_BYTES);
      yield assert(
         assertion: $oversized === false
            && $unterminated === false
            && ($rejected === false || $rejected === ''),
         description: 'oversized and unterminated frames are rejected before the pipe write'
      );

      // @ Nonblocking datagram pressure returns 0 in the target runtime. It
      // must be reported as whole-record loss, and queued frames stay intact.
      $Formatter->formatted = str_repeat('P', PipeHandler::MAX_FRAME_BYTES - 1) . "\n";
      $acceptedFrames = 0;
      $pressured = false;
      for ($attempt = 0; $attempt < 64; $attempt++) {
         if ($Boundary->handle($Source) === false) {
            $pressured = true;
            break;
         }
         $acceptedFrames++;
      }

      $receivedFrames = 0;
      $corrupted = false;
      while (true) {
         $queued = $Pipe->read(PipeHandler::MAX_FRAME_BYTES);
         if ($queued === false || $queued === '') {
            break;
         }
         $receivedFrames++;
         $corrupted = $corrupted || $queued !== $Formatter->formatted;
      }

      yield assert(
         assertion: $pressured
            && $acceptedFrames > 0
            && $receivedFrames === $acceptedFrames
            && $corrupted === false,
         description: 'backpressure rejects one whole frame and never corrupts queued datagrams'
      );

      $Pipe->close();
      Display::show($saved);
   }
);
