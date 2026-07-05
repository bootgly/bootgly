<?php

namespace Bootgly\CLI\Terminal;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input\Line;


return new Specification(
   description: 'It should edit the line buffer with a virtual cursor (pure state machine)',
   test: function () {
      $Line = new Line;

      // @ Feeding inserts at the cursor (UTF-8 aware)
      $Line->feed('Boot');
      $Line->feed('já'); // multibyte

      yield assert(
         assertion: $Line->value === 'Bootjá' && $Line->cursor === 6,
         description: 'feed() inserts complete UTF-8 characters at the cursor'
      );

      // @ Control bytes never enter the value
      $Line->feed("\x01\x7F");

      yield assert(
         assertion: $Line->value === 'Bootjá',
         description: 'Control characters are ignored by feed()'
      );

      // @ Moving + inserting mid-line
      $Line->control("\e[D"); // Left
      $Line->control("\e[D");
      $Line->feed('X');

      yield assert(
         assertion: $Line->value === 'BootXjá' && $Line->cursor === 5,
         description: 'Arrows move the virtual cursor; feed() inserts mid-line'
      );

      // @ Home/End + kill line ops
      $Line->control("\x01"); // Ctrl+A

      yield assert(
         assertion: $Line->cursor === 0,
         description: 'Ctrl+A moves to the start'
      );

      $Line->control("\x0B"); // Ctrl+K — kill to the end

      yield assert(
         assertion: $Line->value === '' && $Line->cursor === 0,
         description: 'Ctrl+K kills to the end of the line'
      );

      // @ Backspace, Delete and word chop
      $Line->feed('hello world');
      $Line->control("\x7F"); // Backspace

      yield assert(
         assertion: $Line->value === 'hello worl',
         description: 'Backspace erases before the cursor'
      );

      $Line->control("\x17"); // Ctrl+W — chop word

      yield assert(
         assertion: $Line->value === 'hello ',
         description: 'Ctrl+W chops the word before the cursor'
      );

      // @ Enter submits
      yield assert(
         assertion: $Line->control("\n") === false && $Line->control('x') === true,
         description: 'control() returns false on Enter and true otherwise'
      );

      // @ Reset
      $Line->reset();

      yield assert(
         assertion: $Line->value === '' && $Line->cursor === 0,
         description: 'reset() clears the buffer and the cursor'
      );
   }
);
