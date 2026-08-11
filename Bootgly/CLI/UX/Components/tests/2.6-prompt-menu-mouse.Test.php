<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function max;
use function rewind;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should aim, select and wheel the trigger menu with the pointer',
   test: function () {
      // ? Pointer reports only exist on interactive terminals
      if (BOOTGLY_TTY === false) {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "plain\n");
         rewind($stream);

         $Prompt = new Prompt(new Input($stream), new Output('php://memory')); // @phpstan-ignore-line

         $lines = [];
         foreach ($Prompt->prompting() as $line) {
            $lines[] = $line;
         }

         yield assert(
            assertion: $lines === ['plain'],
            description: 'Pipes stay line-driven — no menu, no pointer'
         );

         return;
      }

      $triggers = [
         '/' => [
            '/help' => ['description' => 'List the available commands'],
            '/time' => ['skeleton' => '[timezone]', 'description' => 'Tell the current time'],
            '/date' => ['description' => 'Tell the current date'],
            '/echo' => ['skeleton' => '<text>', 'description' => 'Echo the text back'],
            '/clear' => ['description' => 'Clear the content band'],
            '/history' => ['description' => 'Count the submitted lines'],
            '/random' => ['skeleton' => '[max]', 'description' => 'Roll a random number'],
            '/repeat' => ['skeleton' => '<count>', 'description' => 'Repeat the text'],
            '/version' => ['description' => 'Show the REPL version'],
            '/exit' => ['description' => 'Quit the REPL'],
         ]
      ];

      // ! Menu geometry — `/` opens a 5-row window inside the bordered flyout
      //   (7 block rows); the frame bottom-anchors, so the first option row is
      //   `region + 2` (right after the box's top border)
      $region = max(1, (int) Terminal::$height - 10);
      $top = $region + 2;

      // @ Movement over an option row aims it — and nothing completes
      $line = $top + 2;
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "/\e[<35;10;{$line}M\x04");
      rewind($stream);

      $Prompt = new Prompt(new Input($stream), new Output('php://memory')); // @phpstan-ignore-line
      $Prompt->buffered = true;
      $Prompt->triggers = $triggers;
      $Prompt->start();

      foreach ($Prompt->prompting() as $submitted) {
         // ...no submits — hover only, then Ctrl+D
      }

      yield assert(
         assertion: $Prompt->Listbox->aimed === 2
            && $Prompt->Lines->Lines[0]->value === '/',
         description: 'Pointer movement aims the option row under it without completing'
      );

      // @ A left press on an option row selects it, as Tab does
      $line = $top + 1;
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "/\e[<0;10;{$line}M\x04");
      rewind($stream);

      $Prompt = new Prompt(new Input($stream), new Output('php://memory')); // @phpstan-ignore-line
      $Prompt->buffered = true;
      $Prompt->triggers = $triggers;
      $Prompt->start();

      foreach ($Prompt->prompting() as $submitted) {
         // ...no submits — click only, then Ctrl+D
      }

      yield assert(
         assertion: $Prompt->Lines->Lines[0]->value === '/time',
         description: 'A left press on an option row completes it into the input'
      );

      // @ The wheel aims the open menu under the pointer — and still scrolls
      //   the band elsewhere
      $inside = $top + 1;
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "/\e[<65;10;{$inside}M\e[<64;10;2M\x04");
      rewind($stream);

      $Prompt = new Prompt(new Input($stream), new Output('php://memory')); // @phpstan-ignore-line
      $Prompt->buffered = true;
      $Prompt->triggers = $triggers;
      $Prompt->start();

      for ($index = 0; $index < 200; $index++) {
         $Prompt->feed("row {$index}");
      }

      foreach ($Prompt->prompting() as $submitted) {
         // ...no submits — wheel over the menu, wheel over the band, Ctrl+D
      }

      yield assert(
         assertion: $Prompt->Listbox->aimed === 1
            && $Prompt->Scrollarea->stuck === false,
         description: 'The wheel aims the menu under the pointer and scrolls the band elsewhere'
      );

      // @ The menu bar: a thumb press holds, dragging to the strip bottom
      //   slides the window to the last options — and never completes
      $bar = (int) Terminal::$width - 2;
      $bottom = $top + 4;
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "/\e[<0;{$bar};{$top}M\e[<32;{$bar};{$bottom}M\e[<0;{$bar};{$bottom}m\x04");
      rewind($stream);

      $Prompt = new Prompt(new Input($stream), new Output('php://memory')); // @phpstan-ignore-line
      $Prompt->buffered = true;
      $Prompt->triggers = $triggers;
      $Prompt->start();

      foreach ($Prompt->prompting() as $submitted) {
         // ...no submits — press the thumb, drag to the bottom, release, Ctrl+D
      }

      yield assert(
         assertion: $Prompt->Listbox->aimed === 9
            && $Prompt->Listbox->Window->first === 5
            && $Prompt->Lines->Lines[0]->value === '/',
         description: 'Dragging the menu bar slides the window without completing an option'
      );

      // @ Movement over the menu bar accents its thumb — and never aims a row
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "/\e[<35;{$bar};{$top}M\x04");
      rewind($stream);

      $Prompt = new Prompt(new Input($stream), new Output('php://memory')); // @phpstan-ignore-line
      $Prompt->buffered = true;
      $Prompt->triggers = $triggers;
      $Prompt->start();

      foreach ($Prompt->prompting() as $submitted) {
         // ...no submits — hover the bar thumb, then Ctrl+D
      }

      yield assert(
         assertion: $Prompt->Listbox->Scrollbar->hovered === true
            && $Prompt->Listbox->aimed === 0,
         description: 'Movement over the menu bar accents the thumb instead of aiming a row'
      );
   }
);
