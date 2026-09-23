<?php

use Bootgly\ABI\Templates\Template\Escaped;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Escaped::scrub() defuses every directive in text the program did not author',
   test: function () {
      // # Each directive family, scrubbed then rendered, is plain text
      $directives = [
         'newline' => 'a@.;b',
         'newlines' => 'a@...;b',
         'deprecated newline' => 'a@\\;b',
         'foreground' => '@#red:x@;',
         'background' => '@!red:x@;',
         'semantic' => '@:e:x@;',
         'blink' => '@@:x@;',
         'bold' => '@*:x*@',
         'italic' => '@~:x~@',
         'underline' => '@_:x_@',
         'strike' => '@-:x-@',
         'reset' => 'x @;',
      ];
      $live = [];
      foreach ($directives as $name => $text) {
         $rendered = Escaped::render(Escaped::scrub($text));
         if (str_contains($rendered, "\e") || str_contains($rendered, "\n")) {
            $live[] = $name;
         }
      }
      yield assert(
         assertion: $live === [],
         description: 'no directive renders an escape or a line break after scrub(); still live: ' . implode(', ', $live)
      );

      // # A fixed point: one pass would leave a directive its own deletions made
      yield assert(
         assertion: Escaped::scrub('-@@x') === '-x'
            && Escaped::scrub('*@@;') === '*;'
            && Escaped::scrub('@@@.;') === '.;'
            && Escaped::render(Escaped::scrub('-@@x')) === '-x',
         description: 'scrub() runs to a fixed point: one pass would leave the reset its own deletions create'
      );

      // # Ordinary text keeps its `@`
      yield assert(
         assertion: Escaped::scrub('contact admin@example.com @home @2x') === 'contact admin@example.com @home @2x'
            && Escaped::scrub('ação@ś') === 'ação@ś'
            && Escaped::render(Escaped::scrub('admin@example.com')) === 'admin@example.com',
         description: 'an `@` before a letter, a digit or a non-ASCII character stays'
      );

      // # Invalid UTF-8 is handled byte-wise, never by dropping every `@`
      yield assert(
         assertion: Escaped::scrub("a\xFF@b @.;") === "a\xFF@b .;",
         description: 'invalid UTF-8 keeps its inert `@` and loses the live one'
      );

      // # Idempotent
      $once = Escaped::scrub('x-@@#red:@.;y');
      yield assert(
         assertion: Escaped::scrub($once) === $once,
         description: 'scrubbing twice changes nothing'
      );
   }
);
