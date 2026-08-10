<?php

namespace Bootgly\CLI\Terminal;


use function assert;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Lines;


return new Test(
   description: 'It should edit a multiline buffer across rows (pure state machine over Line)',
   test: function () {
      $Lines = new Lines;

      // @ Feeding inserts into the active row
      $Lines->feed('ab');

      yield assert(
         assertion: $Lines->lines === ['ab'] && $Lines->row === 0 && $Lines->column === 2,
         description: 'feed() inserts into the active row'
      );

      // @ Enter splits the active row at the cursor
      $Lines->control(Keystrokes::LEFT->value);
      $Lines->control(Keystrokes::ENTER->value);

      yield assert(
         assertion: $Lines->lines === ['a', 'b'] && $Lines->row === 1 && $Lines->column === 0,
         description: 'Enter splits the row at the cursor and lands on the tail'
      );

      // @ Left at the row start wraps to the end of the previous row
      $Lines->control(Keystrokes::LEFT->value);

      yield assert(
         assertion: $Lines->row === 0 && $Lines->column === 1,
         description: 'Left wraps to the end of the previous row'
      );

      // @ Right at the row end wraps to the start of the next row
      $Lines->control(Keystrokes::RIGHT->value);

      yield assert(
         assertion: $Lines->row === 1 && $Lines->column === 0,
         description: 'Right wraps to the start of the next row'
      );

      // @ Backspace at the row start merges into the previous row, cursor at the seam
      $Lines->control(Keystrokes::BACKSPACE->value);

      yield assert(
         assertion: $Lines->lines === ['ab'] && $Lines->row === 0 && $Lines->column === 1,
         description: 'Backspace at the row start merges into the previous row'
      );

      // @ Delete at the row end pulls the next row up
      $Lines->control(Keystrokes::END->value);
      $Lines->control(Keystrokes::ENTER->value);
      $Lines->feed('cd');
      $Lines->control(Keystrokes::UP->value);
      $Lines->control(Keystrokes::END->value);
      $Lines->control(Keystrokes::DELETE->value);

      yield assert(
         assertion: $Lines->lines === ['abcd'] && $Lines->value === 'abcd',
         description: 'Delete at the row end pulls the next row up'
      );

      // @ Vertical moves clamp the column to the target row length
      $Lines->reset();
      $Lines->feed('long line');
      $Lines->control(Keystrokes::ENTER->value);
      $Lines->feed('xy');
      $Lines->control(Keystrokes::UP->value);

      yield assert(
         assertion: $Lines->row === 0 && $Lines->column === 2,
         description: 'Up keeps the column when the target row is long enough'
      );

      $Lines->control(Keystrokes::END->value);
      $Lines->control(Keystrokes::DOWN->value);

      yield assert(
         assertion: $Lines->row === 1 && $Lines->column === 2,
         description: 'Down clamps the column to the shorter target row'
      );

      // @ Intra-row keys delegate to the active Line
      $Lines->control(Keystrokes::CTRL_U->value);

      yield assert(
         assertion: $Lines->lines === ['long line', ''] && $Lines->column === 0,
         description: 'Ctrl+U kills to the row start through the active Line'
      );

      // @ Escape sequences never enter the value as text
      $Lines->control(Keystrokes::PAGEUP->value);

      yield assert(
         assertion: $Lines->lines === ['long line', ''],
         description: 'Unhandled escape sequences leave the buffer untouched'
      );

      // @ load() splits on line breaks, cursor at the end of the last row
      $Lines->load("one\ntwo\nthree");

      yield assert(
         assertion: $Lines->lines === ['one', 'two', 'three']
            && $Lines->row === 2 && $Lines->column === 5,
         description: 'load() rebuilds the rows and lands at the end of the last one'
      );

      // @ reset() returns to a single empty row
      $Lines->reset();

      yield assert(
         assertion: $Lines->lines === [''] && $Lines->row === 0 && $Lines->column === 0
            && $Lines->value === '',
         description: 'reset() returns to a single empty row'
      );
   }
);
