<?php

use Bootgly\ACI\Logs\Filters\Callback;
use Bootgly\ACI\Logs\Filters\Channel;
use Bootgly\ACI\Logs\Filters\Level as LevelFilter;
use Bootgly\ACI\Logs\Filters\Tags;
use Bootgly\ACI\Logs\Filters;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Level, Channel, Callback and Tags filters gate records; Filters requires all to pass',
   test: function () {
      // # Level filter — warning and more severe only
      $Level = new LevelFilter(Min: Levels::Warning, Max: Levels::Emergency);
      yield assert(
         assertion: $Level->check(new Record(Levels::Warning, 'c', 'm')) === true,
         description: 'Level filter passes a record at the floor severity'
      );
      yield assert(
         assertion: $Level->check(new Record(Levels::Info, 'c', 'm')) === false,
         description: 'Level filter blocks a record below the floor severity'
      );

      // # Channel filter
      $Channel = new Channel(allowed: ['http']);
      yield assert(
         assertion: $Channel->check(new Record(Levels::Info, 'http', 'm')) === true
            && $Channel->check(new Record(Levels::Info, 'db', 'm')) === false,
         description: 'Channel allow-list passes only listed channels'
      );
      $Denied = new Channel(denied: ['db']);
      yield assert(
         assertion: $Denied->check(new Record(Levels::Info, 'db', 'm')) === false,
         description: 'Channel deny-list blocks listed channels'
      );

      // # Callback filter
      $Callback = new Callback(fn (Record $Record): bool => $Record->message === 'ok');
      yield assert(
         assertion: $Callback->check(new Record(Levels::Info, 'c', 'ok')) === true
            && $Callback->check(new Record(Levels::Info, 'c', 'no')) === false,
         description: 'Callback filter delegates to the predicate'
      );

      // # Tags filter
      $Tagged = new Record(Levels::Info, 'c', 'm', ['tags' => ['a', 'b']]);
      yield assert(
         assertion: (new Tags(['b', 'z']))->check($Tagged) === true,
         description: 'Tags filter (any) passes when one tag matches'
      );
      yield assert(
         assertion: (new Tags(['a', 'z'], all: true))->check($Tagged) === false
            && (new Tags(['a', 'b'], all: true))->check($Tagged) === true,
         description: 'Tags filter (all) requires every tag'
      );

      // # Filters collection — AND semantics
      $Filters = new Filters;
      $Filters
         ->push(new Channel(allowed: ['http']))
         ->push(new LevelFilter(Min: Levels::Warning, Max: Levels::Emergency));
      yield assert(
         assertion: $Filters->check(new Record(Levels::Error, 'http', 'm')) === true,
         description: 'Filters->check() passes when all filters pass'
      );
      yield assert(
         assertion: $Filters->check(new Record(Levels::Info, 'http', 'm')) === false,
         description: 'Filters->check() fails when any filter fails'
      );
   }
);
