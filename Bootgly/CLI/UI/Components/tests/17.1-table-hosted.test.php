<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Base\Frame;


return new Specification(
   description: 'It should render every table section into the injected Output',
   test: function () {
      // ! Table hosted inside a Frame — writes must reach the injected Output
      $Host = new Output('php://memory');

      $Frame = new Frame($Host);
      $Frame->width = 40;
      $Frame->height = 12;

      $Table = new Table($Frame->Output);
      $Table->Data->Header->set([['Tab', 'Purpose']]);
      $Table->Data->Body->set([['Log', 'tail view'], ['CPU', 'graph']]);
      $Table->Data->Footer->set([['2 rows', 'total']]);
      $Table->render();

      // @ Isolation — cells and borders land on the injected Output, not the host
      rewind($Host->stream);
      $hosted = (string) stream_get_contents($Host->stream);

      yield assert(
         assertion: $hosted === '',
         description: 'Hosted tables write into the injected Output only'
      );

      $frame = (string) $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '║ Tab') === true
            && str_contains($frame, '║ Log') === true
            && str_contains($frame, '║ 2 rows') === true,
         description: 'Header, body and footer cells render inside the frame'
      );

      // @ Sections — one top, separators between sections, one bottom
      yield assert(
         assertion: substr_count($frame, '╔') === 1
            && substr_count($frame, '╟') === 2
            && substr_count($frame, '╚') === 1,
         description: 'Sections open once, separate with mid borders and close once'
      );

      // @ Empty sections draw nothing — no stray borders without a footer
      $Plain = new Table(new Output('php://memory'));
      $Plain->Data->Header->set([['A', 'B']]);
      $Plain->Data->Body->set([['1', '2']]);
      $Plain->render();

      rewind($Plain->Output->stream);
      $written = (string) stream_get_contents($Plain->Output->stream);

      yield assert(
         assertion: substr_count($written, '╚') === 1 && substr_count($written, '╟') === 1,
         description: 'Footerless tables close once with a single header separator'
      );
   }
);
