<?php

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'trace() parses the backtrace into chronological indexed frames',
   test: function () {
      $thrower = function (): Exception {
         return new Exception('trace probe');
      };
      $Throwable = $thrower();

      $backtrace = Throwables::trace($Throwable);

      yield assert(
         assertion: count($backtrace) >= 1,
         description: 'backtrace has at least one frame'
      );

      $first = $backtrace[0];
      yield assert(
         assertion: isSet($first['index'], $first['file'], $first['line'], $first['call']),
         description: 'frames expose index/file/line/call keys'
      );
      yield assert(
         assertion: $first['index'] === '1',
         description: 'frames are re-indexed chronologically from 1'
      );
   }
);
