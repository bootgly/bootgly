<?php

use Bootgly\ABI\IO\IPC\Pipe as IPCPipe;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers\Pipe as PipeHandler;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Pipe handler streams JSON records through an IPC pipe; the reader reconstructs them',
   test: function () {
      $saved = Display::$segments;
      Display::show(Display::MESSAGE);

      $Pipe = new IPCPipe;

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

      $Pipe->close();
      Display::show($saved);
   }
);
