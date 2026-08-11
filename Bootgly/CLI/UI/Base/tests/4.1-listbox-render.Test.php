<?php

namespace Bootgly\CLI\UI\Base;


use function assert;
use function preg_replace;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should compose a windowed option list around the aim',
   test: function () {
      $Output = new Output('php://memory');

      $Listbox = new Listbox($Output);

      // @ Closed while there is nothing to offer
      yield assert(
         assertion: $Listbox->render(Listbox::RETURN_OUTPUT) === '' && $Listbox->height === 0,
         description: 'An empty list renders nothing and reports a height of 0'
      );

      // @ Every option renders when the viewport is unlimited
      $Listbox->options = ['/help', '/clear', '/model', '/exit'];
      $frame = (string) $Listbox->render(Listbox::RETURN_OUTPUT);

      yield assert(
         assertion: $Listbox->height === 4
            && str_contains($frame, '/help') === true
            && str_contains($frame, '/exit') === true
            && str_contains($frame, 'more') === false,
         description: 'An unlimited viewport renders every option, with no hidden-count markers'
      );

      // @ The aim marks its row
      $Listbox->aim(1);
      $frame = (string) $Listbox->render(Listbox::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, "\e[96m❯ /clear") === true
            && str_contains($frame, '  /help') === true,
         description: 'The aimed option carries the marker; the others carry the filler'
      );

      // @ Aiming clamps — the list never wraps
      $Listbox->aim(99);

      yield assert(
         assertion: $Listbox->aimed === 3,
         description: 'aim() clamps past the end'
      );

      $Listbox->advance();

      yield assert(
         assertion: $Listbox->aimed === 3,
         description: 'advance() stops at the last option'
      );

      $Listbox->aim(0);
      $Listbox->regress();

      yield assert(
         assertion: $Listbox->aimed === 0,
         description: 'regress() stops at the first option'
      );

      // @ Circular navigation wraps at both edges (opt-in)
      $Listbox->circular = true;
      $Listbox->regress();

      yield assert(
         assertion: $Listbox->aimed === 3,
         description: 'Circular regress() at the top wraps to the last option'
      );

      $Listbox->advance();

      yield assert(
         assertion: $Listbox->aimed === 0,
         description: 'Circular advance() at the last option wraps back to the first'
      );

      $Listbox->circular = false;

      // @ A viewport windows around the aim and announces what it hides
      $Listbox->viewport = 2;
      $Listbox->aim(3);
      $frame = (string) $Listbox->render(Listbox::RETURN_OUTPUT);

      yield assert(
         assertion: $Listbox->height === 3
            && str_contains($frame, '↑ 2 more') === true
            && str_contains($frame, '/model') === true
            && str_contains($frame, '/exit') === true
            && str_contains($frame, '/help') === false,
         description: 'The window slides to the aim and marks the rows it clipped above'
      );

      $Listbox->aim(0);
      $frame = (string) $Listbox->render(Listbox::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '↓ 2 more') === true
            && str_contains($frame, '↑') === false,
         description: 'Aiming back to the top marks the rows clipped below'
      );

      // @ Long options crop instead of wrapping
      $Listbox->viewport = 0;
      $Listbox->width = 8;
      $Listbox->options = ['a-very-long-option-label'];
      $Listbox->aim(0);
      $frame = (string) $Listbox->render(Listbox::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '…') === true
            && str_contains($frame, 'a-very-long-option-label') === false,
         description: 'Options wider than the available columns crop with an ellipsis'
      );

      // @ The query lights up inside each option
      $Listbox = new Listbox($Output);
      $Listbox->options = ['/help', '/hello'];
      $Listbox->query = '/he';
      $frame = (string) $Listbox->render(Listbox::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '@*:@#White:/he' . "\e[0m") === true,
         description: 'The query match paints with the accent inside the option'
      );

      // @ A tint paints the label column of every row
      $Listbox = new Listbox($Output);
      $Listbox->options = ['/help', '/exit'];
      $Listbox->tint = '@#Magenta:';
      $frame = (string) $Listbox->render(Listbox::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, "  \e[95m/exit") === true,
         description: 'The tint paints the label column against the dim details'
      );

      // @ A detail column rides dimmed after the widest label
      $Listbox = new Listbox($Output);
      $Listbox->options = ['/help [cmd]', '/exit'];
      $Listbox->details = ['Lists commands', 'Quits'];
      $frame = (string) $Listbox->render(Listbox::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '/help [cmd]  @#Black:Lists commands') === true
            && str_contains($frame, '/exit        @#Black:Quits') === true,
         description: 'Details align after the widest label and paint dimmed'
      );

      // @ The scrollbar replaces the `N more` markers on clipped lists
      $Listbox = new Listbox($Output);
      $Listbox->options = ['/one', '/two', '/three', '/four', '/five', '/six'];
      $Listbox->viewport = 3;
      $Listbox->width = 8;
      $Listbox->scrollbar = true;
      $Listbox->aim(0);
      $frame = (string) $Listbox->render(Listbox::RETURN_OUTPUT);

      yield assert(
         assertion: $Listbox->height === 3
            && str_contains($frame, 'more') === false
            && str_contains($frame, '█') === true
            && str_contains($frame, '│') === true,
         description: 'A clipped scrollbar list keeps the window height and paints thumb + track'
      );

      // @ A skeleton right after the fully-typed command keeps its space — the
      //   accent reopen resolves to raw SGR (a markup token would eat the space)
      $Output = new Output('php://memory');
      $Listbox = new Listbox($Output);
      $Listbox->options = ['/time [timezone]'];
      $Listbox->query = '/time';
      $Listbox->background = '@!black:';
      $Listbox->render();

      rewind($Output->stream);
      $resolved = (string) preg_replace(
         '/\e\[[0-9;]*m/',
         '',
         (string) stream_get_contents($Output->stream)
      );

      yield assert(
         assertion: str_contains($resolved, '/time [timezone]') === true,
         description: 'The space between an accented command and its skeleton survives'
      );

      // @ WRITE mode resolves the markup into the Output stream
      $Output = new Output('php://memory');
      $Listbox = new Listbox($Output);
      $Listbox->options = ['/help', '/exit'];
      $Listbox->render();

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($written, '/help') === true
            && str_contains($written, '/exit') === true
            && str_contains($written, '@#Cyan:') === false,
         description: 'WRITE mode writes the rows with the markup resolved'
      );
   }
);
