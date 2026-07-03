<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function explode;
use function fopen;
use function json_encode;
use function mb_strlen;
use function preg_replace;
use function rewind;
use function str_contains;
use function str_repeat;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should fit every Logs frame line into the terminal width',
   test: function () {
      // ! Narrow terminal (embedded runtimes / split panes)
      $columns = Terminal::$columns;
      $lines = Terminal::$lines;
      $width = Terminal::$width;
      $height = Terminal::$height;
      Terminal::$columns = Terminal::$width = 60;
      Terminal::$lines = Terminal::$height = 12;

      // ! Logs with in-memory streams
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');
      $Logs = new Logs($Input, $Output);

      $Logs->feed(json_encode([
         'level' => 'error',
         'channel' => 'Server',
         'message' => str_repeat('overflowing record message ', 12),
         'timestamp' => 1750000000.0
      ]) . "\n");

      // @ Render a frame
      $Logs->render();
      rewind($Output->stream);
      $frame = (string) stream_get_contents($Output->stream);

      // @ Measure each frame line without escape sequences
      $overflowed = 0;
      $rows = explode("\n", $frame);
      foreach ($rows as $row) {
         $plain = (string) preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $row);
         if (mb_strlen($plain) > 60) {
            $overflowed++;
         }
      }

      yield assert(
         assertion: $overflowed === 0,
         description: "No frame line may exceed the terminal width (overflowed: $overflowed)"
      );
      yield assert(
         assertion: str_contains($frame, 'BOOTGLY logs'),
         description: 'The status bar is rendered'
      );
      yield assert(
         assertion: str_contains($frame, '[q] quit') === false,
         description: 'The 60-column footer is truncated before the trailing quit key'
      );

      // ! Restore the terminal size
      Terminal::$columns = $columns;
      Terminal::$lines = $lines;
      Terminal::$width = $width;
      Terminal::$height = $height;
   }
);
