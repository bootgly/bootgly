<?php

namespace Bootgly\CLI\UX\Components;


use function array_keys;
use function assert;
use function count;
use function fopen;
use function ftell;
use function rewind;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should create labeled tab frames sharing one bounded rectangle',
   test: function () {
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Host = new Output('php://memory');

      // @ Empty Tabs renders nothing
      $Empty = new Tabs($Input, $Host);

      yield assert(
         assertion: $Empty->render() === null && $Empty->render(Tabs::RETURN_OUTPUT) === ''
            && $Empty->Active === null && $Empty->tab === 0,
         description: 'An empty Tabs renders nothing and exposes no active frame'
      );

      // @ Adding creates ready frames — geometry assigned immediately
      $Tabs = new Tabs($Input, $Host);
      $Tabs->width = 30;
      $Tabs->height = 8;

      $Log = $Tabs->add('Log');

      yield assert(
         assertion: $Tabs->tab === 1 && $Tabs->Active === $Log
            && $Log->row === 1 && $Log->column === 1
            && $Log->width === 30 && $Log->height === 8
            && $Log->columns === 28 && $Log->lines === 6,
         description: 'The first added tab activates with the shared geometry readable'
      );

      $CPU = $Tabs->add('CPU');

      yield assert(
         assertion: array_keys($Tabs->Frames) === ['Log', 'CPU'] && $Tabs->Active === $Log,
         description: 'Tabs register in add order and adding never steals the activation'
      );

      // @ Isolation — tab writes never reach the host before a render
      $Log->Output->render("isolated\n");

      rewind($Host->stream);
      $hosted = (string) stream_get_contents($Host->stream);

      yield assert(
         assertion: $hosted === '',
         description: 'Tab frames buffer into their own isolated Outputs'
      );

      // @ Inactive tabs drain on every render — streams stay bounded
      for ($tick = 0; $tick < 3; $tick++) {
         $CPU->Output->render("tick {$tick}\n");
         $Tabs->render(Tabs::RETURN_OUTPUT);

         yield assert(
            assertion: ftell($CPU->Output->stream) === 0,
            description: "The inactive tab stream stays truncated after render {$tick}"
         );
      }

      $CPU->capacity = 3;
      for ($line = 0; $line < 6; $line++) {
         $CPU->Output->render("line {$line}\n");
      }
      $Tabs->render(Tabs::RETURN_OUTPUT);

      yield assert(
         assertion: count($CPU->buffer) === 3,
         description: 'Inactive tab buffers cap to their capacity while hidden'
      );

      // @ Duplicate labels replace the Frame in place, keeping the position
      $Replaced = $Tabs->add('Log');

      yield assert(
         assertion: array_keys($Tabs->Frames) === ['Log', 'CPU']
            && $Tabs->Frames['Log'] === $Replaced && $Replaced !== $Log,
         description: 'A duplicate label replaces its Frame in place'
      );

      // @ Invalidation + resize — the shared rectangle reflows and repaints
      $Tabs->invalidate();
      $Tabs->resize(24, 6);

      rewind($Host->stream);
      $resized = (string) stream_get_contents($Host->stream);

      yield assert(
         assertion: $Tabs->width === 24 && $Tabs->height === 6
            && $Replaced->width === 24 && $CPU->height === 6
            && $resized !== '',
         description: 'Resizing reflows every tab frame and repaints the active one'
      );
   }
);
