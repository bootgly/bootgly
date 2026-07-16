<?php

namespace Bootgly\CLI\UI\Base;


use function assert;
use function count;
use function ftell;
use function rewind;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should drain pending writes into the buffer without painting',
   test: function () {
      // ! Frame over an in-memory host stream
      $Host = new Output('php://memory');

      $Frame = new Frame($Host);
      $Frame->width = 20;
      $Frame->height = 5;

      // @ Drain absorbs writes — buffer grows, stream truncates, nothing paints
      $Frame->Output->render("one\ntwo\n");
      $Frame->drain();

      rewind($Host->stream);
      $hosted = (string) stream_get_contents($Host->stream);

      yield assert(
         assertion: $Frame->buffer === ['one', 'two']
            && ftell($Frame->Output->stream) === 0
            && $hosted === '',
         description: 'Draining absorbs pending writes without painting the host'
      );

      // @ Repeated drains stay bounded — the stream never accumulates
      for ($tick = 0; $tick < 3; $tick++) {
         $Frame->Output->render("tick {$tick}\n");
         $Frame->drain();

         yield assert(
            assertion: ftell($Frame->Output->stream) === 0,
            description: "The isolated stream stays truncated after drain {$tick}"
         );
      }

      // @ Capacity keeps draining bounded when overfed
      $Frame->capacity = 4;
      for ($line = 0; $line < 10; $line++) {
         $Frame->Output->render("line {$line}\n");
      }
      $Frame->drain();

      yield assert(
         assertion: count($Frame->buffer) === 4 && $Frame->buffer[3] === 'line 9',
         description: 'Draining caps the buffer to the capacity keeping the newest lines'
      );
   }
);
