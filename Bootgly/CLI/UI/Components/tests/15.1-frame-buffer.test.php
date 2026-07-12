<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function count;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should drain the isolated Output into a sanitized, capped line buffer',
   test: function () {
      // ! Frame over an in-memory host stream
      $Host = new Output('php://memory');

      $Frame = new Frame($Host);
      $Frame->width = 20;
      $Frame->height = 6;

      // @ Isolation — writes into the isolated Output never reach the host stream
      $Frame->Output->render("isolated\n");

      rewind($Host->stream);
      $hosted = (string) stream_get_contents($Host->stream);

      yield assert(
         assertion: $hosted === '',
         description: 'The isolated Output is its own stream — the host stays untouched'
      );

      // @ Drain — rendering pulls the written lines into the buffer
      $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: $Frame->buffer === ['isolated'],
         description: 'Rendering drains the isolated stream into logical lines'
      );

      // @ Sanitize — only SGR escapes survive (erase/cursor/OSC are stripped)
      $Frame->Output->write("\e[2Jbad\e[5;5Hrow \e[31mred\e[0m\n");
      $Frame->Output->write("\e]0;window title\x07text\n");
      $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: $Frame->buffer[1] === "badrow \e[31mred\e[0m",
         description: 'Erase and cursor escapes are stripped; SGR styling passes through'
      );
      yield assert(
         assertion: $Frame->buffer[2] === 'text',
         description: 'OSC sequences are stripped without eating the following text'
      );

      // @ Carriage return — the latest state wins (progress-style writes degrade)
      $Frame->Output->write("10%\r55%\r99%\n");
      $Frame->Output->write("crlf\r\n");
      $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: $Frame->buffer[3] === '99%',
         description: 'A carriage return overwrites the pending line'
      );
      yield assert(
         assertion: $Frame->buffer[4] === 'crlf',
         description: 'CRLF line breaks behave as plain line breaks'
      );

      // @ Partial tail — an unterminated line carries over to the next drain
      $Frame->Output->write('par');
      $Frame->render(Frame::RETURN_OUTPUT);

      $held = count($Frame->buffer);

      $Frame->Output->write("tial\n");
      $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: $held === 5 && $Frame->buffer[5] === 'partial',
         description: 'An unterminated tail is held apart and completed by the next write'
      );

      // @ Capacity — the oldest lines drop first
      $Frame->capacity = 3;
      $Frame->Output->render("six\nseven\n");
      $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: count($Frame->buffer) === 3 && $Frame->buffer[0] === 'partial',
         description: 'The buffer caps to the capacity keeping the most recent lines'
      );

      // @ Clear — the buffer empties and the same isolated stream keeps working
      $stream = $Frame->Output->stream;
      $Frame->clear();

      yield assert(
         assertion: $Frame->buffer === [] && $Frame->Output->stream === $stream,
         description: 'Clearing empties the content preserving the isolated stream resource'
      );

      $Frame->Output->render("again\n");
      $frame = (string) $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: $Frame->buffer === ['again'] && str_contains($frame, 'again') === true,
         description: 'Hosted writers keep rendering into the frame after a clear'
      );

      // @ CRLF split across two drains — the line break still normalizes
      $Frame->clear();
      $Frame->capacity = 1000;
      $Frame->Output->write("hello\r");
      $Frame->render(Frame::RETURN_OUTPUT);
      $Frame->Output->write("\nworld\n");
      $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: $Frame->buffer === ['hello', 'world'],
         description: 'A CRLF split across two drains never destroys the line'
      );

      // @ CR-only writers — everything before the last CR drops from the tail
      $Frame->clear();
      $Frame->Output->write("10%\r55%\r");
      $Frame->render(Frame::RETURN_OUTPUT);
      $Frame->Output->write("99%\n");
      $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: $Frame->buffer === ['99%'],
         description: 'Carriage-return-only writers stay bounded — the latest state wins'
      );

      // @ Sanitizer coverage — the tokenizer and the sanitizer agree on what an SGR is
      $Frame->clear();
      $Frame->Output->write("\e[38:5:196mX\n");            // colon-form SGR (unsupported)
      $Frame->Output->write("\e[>4;2mY\n");                // private SGR (unsupported)
      $Frame->Output->write("\e]8;;http://x\e\\link\n");   // OSC terminated by ST
      $Frame->Output->write("\e]0;t\e[31mR\e[0m\n");       // OSC interrupted by an SGR
      $Frame->Output->write("\e[31\nx\n");                 // CSI interrupted by a line break
      $Frame->Output->write("\eMtext\n");                  // bare ESC pair
      $Frame->Output->write("a\e\nb\n");                   // lone ESC before a line break
      $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: $Frame->buffer === [
            'X', 'Y', 'link', "\e[31mR\e[0m", '', 'x', 'text', 'a', 'b'
         ],
         description: 'Unsupported escape forms are stripped without eating visible text'
      );
   }
);
