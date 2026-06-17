<?php

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Processors\Memory;
use Bootgly\ACI\Logs\Processors\PID;
use Bootgly\ACI\Logs\Processors\RequestID;
use Bootgly\ACI\Logs\Processors;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Processors enrich the record extra (pid, memory, request id) and chain in order',
   test: function () {
      // # PID
      $Record = new Record(Levels::Debug, 'c', 'm');
      (new PID)->process($Record);
      yield assert(
         assertion: $Record->extra['pid'] === getmypid(),
         description: 'PID processor adds the current pid'
      );

      // # Memory
      (new Memory)->process($Record);
      yield assert(
         assertion: isset($Record->extra['memory'], $Record->extra['memory_peak'])
            && is_int($Record->extra['memory']),
         description: 'Memory processor adds usage and peak'
      );

      // # RequestID (set)
      RequestID::$id = 'abc-123';
      (new RequestID)->process($Record);
      yield assert(
         assertion: $Record->extra['request_id'] === 'abc-123',
         description: 'RequestID processor adds the correlation id when set'
      );

      // # RequestID (unset)
      RequestID::$id = null;
      $Bare = new Record(Levels::Debug, 'c', 'm');
      (new RequestID)->process($Bare);
      yield assert(
         assertion: isset($Bare->extra['request_id']) === false,
         description: 'RequestID processor is a no-op when no id is set'
      );

      // # Chain
      $Chained = new Record(Levels::Debug, 'c', 'm');
      $Processors = new Processors;
      $Processors->push(new PID)->push(new Memory);
      $Processors->process($Chained);
      yield assert(
         assertion: isset($Chained->extra['pid'], $Chained->extra['memory']),
         description: 'Processors->process() runs every processor in order'
      );
   }
);
