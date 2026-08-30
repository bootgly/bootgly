<?php

namespace Bootgly\CLI\UX\Components;


use function assert;
use function fopen;
use function fwrite;
use function json_encode;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'Tail runs the viewer over an NDJSON source and always restores the terminal',
   test: function () {
      // ! Stable geometry for the frame assertions
      $columns = Terminal::$columns;
      $lines = Terminal::$lines;
      $width = Terminal::$width;
      $height = Terminal::$height;
      Terminal::$columns = Terminal::$width = 80;
      Terminal::$lines = Terminal::$height = 16;

      $record = json_encode([
         'timestamp' => 1750000000.0,
         'level' => 'error',
         'project' => 'Alpha',
         'channel' => 'Server',
         'message' => 'tail-visible-record',
      ]) . "\n";

      // # A finite source ends the run by exhaustion
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Tail = new Tail($Input, $Output);
      $Tail->rate = 1;

      $Source = (static function () use ($record) {
         yield $record;
         yield '';
      })();
      $Tail->run($Source);

      rewind($Output->stream);
      $frame = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($frame, "\e[?1049h") && str_contains($frame, "\e[?1049l"),
         description: 'the alternate screen is entered and always left'
      );
      yield assert(
         assertion: str_contains($frame, 'tail-visible-record'),
         description: 'a fed record renders through the shared LogsViewer'
      );

      // # 'q' quits an endless source through the viewer's own keys
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, 'q'); // @phpstan-ignore-line
      rewind($stream); // @phpstan-ignore-line
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Tail = new Tail($Input, $Output);
      $Tail->rate = 1;

      $cycles = 0;
      $Endless = (static function () use (&$cycles) {
         while (true) {
            $cycles++;
            yield '';
         }
      })();
      $Tail->run($Endless);

      yield assert(
         assertion: $cycles <= 2,
         description: 'the q key ends an endless follow through LogsViewer::control()'
      );

      // ! Restore geometry
      Terminal::$columns = $columns;
      Terminal::$lines = $lines;
      Terminal::$width = $width;
      Terminal::$height = $height;
   }
);
