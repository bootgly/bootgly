<?php

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Record stores level, channel, message, context and a fresh timestamp',
   test: function () {
      $before = microtime(true);
      $Record = new Record(Levels::Notice, 'chan', 'msg', ['a' => 1]);

      yield assert(
         assertion: $Record->Level === Levels::Notice,
         description: 'Level is stored'
      );

      yield assert(
         assertion: $Record->channel === 'chan' && $Record->message === 'msg',
         description: 'channel and message are stored'
      );

      yield assert(
         assertion: $Record->context['a'] === 1,
         description: 'context is stored'
      );

      yield assert(
         assertion: $Record->extra === [],
         description: 'extra starts empty'
      );

      yield assert(
         assertion: $Record->timestamp >= $before,
         description: 'timestamp is stamped at construction'
      );
   }
);
