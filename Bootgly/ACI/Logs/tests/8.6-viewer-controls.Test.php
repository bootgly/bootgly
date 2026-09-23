<?php

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatters\JSON as JSONFormatter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Logs as Viewer;


/**
 * M9 — the live viewer (`bootgly logs`, Monitor) draws text that arrived over a pipe or from a
 * log file. The frame keeps its own CSI (cursor, erase-line, SGR) and nothing else: the list
 * row's channel and message, the legend, the detail body and the detail context show every
 * other control character — C1 included — as a visible escape.
 */
return new Test(
   description: 'Live log viewer shows every control sequence a record carries as visible text',
   test: function () {
      $geometry = [Terminal::$columns, Terminal::$lines, Terminal::$width, Terminal::$height];
      $qualifier = Record::$qualifier;

      $channel = "Evil\xC2\x9B2J";
      $message = "m\xC2\x9D0;t\xC2\x9C \e]52;c;x\x07 end\na\x07\tb";
      $context = ['k' => "v\xC2\x9Bz\x7F"];

      // ! One frame of the viewer, raw
      $frame = static function (Viewer $Viewer): string {
         $Viewer->Output = new Output('php://memory');
         $Viewer->render();
         rewind($Viewer->Output->stream);
         return (string) stream_get_contents($Viewer->Output->stream);
      };
      // ! Anything a terminal acts on beyond the frame's own CSI
      $live = static fn (string $raw): bool => preg_match('/\e(?!\[[0-9;]*[mHK])|\xC2[\x80-\x9F]|[\x00-\x09\x0B-\x1A\x1C-\x1F\x7F]/', $raw) === 1;
      // ! The one row that carries a needle
      $row = static function (string $raw, string $needle): string {
         foreach (explode("\n", $raw) as $line) {
            if (str_contains($line, $needle)) {
               return $line;
            }
         }
         return '';
      };

      try {
         Record::$qualifier = '';
         Terminal::$columns = Terminal::$width = 120;
         Terminal::$lines = Terminal::$height = 12;

         $Viewer = new Viewer(new Input, new Output('php://memory'));
         $Viewer->feed((new JSONFormatter)->format(new Record(Levels::Info, $channel, $message, $context)));

         // # The list
         $list = $frame($Viewer);
         $record = $row($list, 'm\u009d0;t');
         $legend = $row($list, 'BOOTGLY logs');
         yield assert(
            assertion: $live($list) === false,
            description: 'the list frame carries no control sequence but its own CSI'
         );
         yield assert(
            assertion: str_contains($record, 'Evil\u009b2J') && str_contains($record, 'm\u009d0;t\u009c \u001b]52;c;x\u0007 end'),
            description: 'the list row shows the channel and the message escapes'
         );
         yield assert(
            assertion: str_contains($legend, '1:Evil\u009b2J'),
            description: 'the channel legend shows the channel escapes'
         );

         // # The detail
         $Viewer->control(' ');
         $Viewer->control("\n");
         $detail = $frame($Viewer);
         yield assert(
            assertion: $live($detail) === false,
            description: 'the detail frame carries no control sequence but its own CSI'
         );
         yield assert(
            assertion: $row($detail, 'm\u009d0;t\u009c \u001b]52;c;x\u0007 end') !== ''
               && $row($detail, '"k": "v\u009bz\u007f"') !== '',
            description: 'the detail body and the pretty-printed context show the escapes'
         );
         yield assert(
            assertion: $row($detail, 'a\u0007 b') !== '',
            description: 'a tab after an escape stops at the column the escape really ends on'
         );
      }
      finally {
         [Terminal::$columns, Terminal::$lines, Terminal::$width, Terminal::$height] = $geometry;
         Record::$qualifier = $qualifier;
      }
   }
);
