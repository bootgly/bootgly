<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should navigate interactively and dump statically on pipes',
   test: function () {
      // ! Tree with in-memory streams — keys pre-fed for the interactive branch
      $stream = fopen('php://memory', 'r+');
      if (BOOTGLY_TTY === true) {
         // Down (aim src) → Right (expand src) → Down (aim a.php) → Enter
         fwrite($stream, "\e[B\e[C\e[B\n");
         rewind($stream);
      }

      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Tree = new Tree($Input, $Output);
      $Tree->blink = true;
      $Root = $Tree->add('app');
      $Src = $Root->add('src');
      $File = $Src->add('a.php', value: 'src/a.php');
      $Root->add('README');
      $Src->collapse();

      $Selected = $Tree->navigate();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      if (BOOTGLY_TTY === true) {
         // @ Valid — interactive session
         yield assert(
            assertion: $Selected === $File && $Selected?->value === 'src/a.php',
            description: 'Enter returns the aimed node with its payload'
         );
         yield assert(
            assertion: $Tree->selected === $File,
            description: 'The confirmed node stays exposed on $selected'
         );
         yield assert(
            assertion: str_contains($output, "\e[?25l") === true && str_contains($output, "\e[?25h") === true,
            description: 'The session hides the cursor and restores it'
         );
         yield assert(
            assertion: str_contains($output, '=>') === true,
            description: 'Interactive frames carry the aim column'
         );
         yield assert(
            assertion: str_contains($output, "\e[5m") === true,
            description: 'blink = true wraps the aim marker in the blink style'
         );
         yield assert(
            assertion: str_contains($output, 'a.php') === true,
            description: 'The expanded child renders after →'
         );

         // @ EOF without a confirm cancels the session and restores the terminal
         $drained = fopen('php://memory', 'r+');
         fwrite($drained, "\e[B");
         rewind($drained);

         $Canceled = new Tree(new Input($drained), $Output); // @phpstan-ignore-line
         $Canceled->add('lonely');

         yield assert(
            assertion: $Canceled->navigate() === null && $Canceled->selected === null,
            description: 'EOF before a confirm cancels: navigate() returns null'
         );

         rewind($Output->stream);
         $all = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: substr_count($all, "\e[?25h") >= 2,
            description: 'The canceled session still restores the cursor (finally path)'
         );
      }
      else {
         // @ Valid — non-interactive degradation
         yield assert(
            assertion: $Selected === null,
            description: 'Pipes cannot confirm a node: navigate() returns null'
         );
         yield assert(
            assertion: str_contains($output, '▾ app') === true && substr_count($output, '▾ app') === 1,
            description: 'The tree dumps statically exactly once'
         );
         yield assert(
            assertion: str_contains($output, '=>') === false,
            description: 'The static dump carries no aim column'
         );
         yield assert(
            assertion: str_contains($output, "\e[?25l") === false,
            description: 'Pipes never receive cursor escapes'
         );
      }
   }
);
