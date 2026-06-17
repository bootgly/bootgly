<?php

use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'JSON formatter emits one valid object per line with ANSI stripped from the message',
   test: function () {
      $JSON = new JSON;

      $Record = new Record(Levels::Info, 'chan', '@#red:hi', ['k' => 'v']);
      $Record->extra['pid'] = 123;

      $line = $JSON->format($Record);
      $decoded = json_decode(trim($line), true);

      yield assert(
         assertion: is_array($decoded),
         description: 'output is valid JSON'
      );

      yield assert(
         assertion: $decoded['level'] === 'INFO' && $decoded['channel'] === 'chan',
         description: 'level label and channel are present'
      );

      yield assert(
         assertion: $decoded['context']['k'] === 'v' && $decoded['extra']['pid'] === 123,
         description: 'context and extra are serialized'
      );

      yield assert(
         assertion: is_string($decoded['message']) && str_contains($decoded['message'], "\x1b") === false,
         description: 'ANSI styling is stripped from the message'
      );

      yield assert(
         assertion: str_ends_with($line, PHP_EOL),
         description: 'each record is newline-terminated'
      );
   }
);
