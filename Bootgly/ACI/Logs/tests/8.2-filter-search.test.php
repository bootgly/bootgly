<?php

use Bootgly\ACI\Logs\Filters\Search;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Search filter matches a case-insensitive substring of the message; empty term passes all',
   test: function () {
      $Search = new Search('boom');

      yield assert(
         assertion: $Search->check(new Record(Levels::Info, 'c', 'kaboom!')) === true,
         description: 'passes a record whose message contains the term'
      );

      yield assert(
         assertion: $Search->check(new Record(Levels::Info, 'c', 'quiet')) === false,
         description: 'blocks a record whose message lacks the term'
      );

      yield assert(
         assertion: (new Search('BOOM'))->check(new Record(Levels::Info, 'c', 'kaboom')) === true,
         description: 'matching is case-insensitive'
      );

      yield assert(
         assertion: (new Search(''))->check(new Record(Levels::Info, 'c', 'anything')) === true,
         description: 'an empty term passes everything'
      );
   }
);
