<?php

use Bootgly\ABI\Differ\Diff\Line;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Diff\Line: type, content, predicate property hooks',
   test: function () {
      // @ Default
      $Line = new Line;
      yield assert(
         assertion: $Line->type === Line::UNCHANGED,
         description: 'default type === UNCHANGED'
      );
      yield assert(
         assertion: $Line->content === '',
         description: 'default content === ""'
      );
      yield assert(
         assertion: $Line->unchanged === true && $Line->added === false && $Line->removed === false,
         description: 'default predicate hooks reflect UNCHANGED'
      );

      // @ Added
      $Added = new Line(Line::ADDED, '+foo');
      yield assert(
         assertion: $Added->added === true && $Added->removed === false && $Added->unchanged === false,
         description: 'ADDED hooks correct'
      );
      yield assert(
         assertion: $Added->content === '+foo',
         description: 'ADDED content preserved'
      );

      // @ Removed
      $Removed = new Line(Line::REMOVED, 'bar');
      yield assert(
         assertion: $Removed->removed === true && $Removed->added === false,
         description: 'REMOVED hooks correct'
      );
   }
);
