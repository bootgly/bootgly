<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function array_filter;
use function array_values;
use function assert;
use function fopen;
use function fwrite;
use function preg_replace;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should open a trigger context menu over the input (any symbol)',
   test: function () {
      if (BOOTGLY_TTY === true) {
         // ! Interactive: `/he` Tab ⏎ · `@zu` Tab ⏎ · `/e` Esc ⏎ · `/m` Esc `o` Tab ⏎
         //   (typing past a dismissal re-arms the menu) · `/` ↓ Tab ⏎ · `/mo` ⏎
         //   (Enter submits the aimed option, completing it first) · `/echo hey` ⏎
         //   (the argument hint holds while the arguments are typed) · `/` ⌫ `hi` ⏎
         //   (Backspace on the empty input releases the absorbed mode) · `/` ↑ Tab ⏎
         //   (circular: ↑ at the top wraps to the last option) · `/` ⇧⏎ `hi` ⏎
         //   (breaks['/'] = false — Shift+Enter is ignored while `/` is active) ·
         //   `pick` ⏎ ↓ ⏎ · `pick` ⏎ Esc (the consumer opens a bottom sheet on
         //   each `pick` — ↓ ⏎ selects the second option, Esc cancels) · Ctrl+D
         // (Esc rides `\e[27;1;27~` — a bare `\e` would pair with the next byte)
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "/he\t\n@zu\t\n/e\e[27;1;27~\n/m\e[27;1;27~o\t\n/\e[B\t\n/mo\n/echo hey\n/\x7fhi\n/\e[A\t\n/\e[13;2uhi\npick\n\e[B\npick\n\e[27;1;27~\x04");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);
         $Prompt->buffered = true;
         $Prompt->shortcuts = ['Enter' => 'send', 'Tab' => 'complete'];
         $Prompt->styles = ['/' => ['border' => '@#Cyan:', 'prompt' => '$ ']];
         $Prompt->modes = ['/'];
         $Prompt->breaks = ['/' => false];
         $Prompt->triggers = [
            '/' => [
               '/help' => ['skeleton' => '[command]', 'description' => 'Lists commands'],
               '/exit',
               '/model',
               '/echo' => ['skeleton' => '<text>']
            ],
            '@' => static fn (string $query): array => array_values(array_filter(
               ['@zebra', '@zulu'],
               static fn (string $file): bool => str_contains($file, $query)
            ))
         ];

         // ! Fed content sanitizes foreign escapes — colors stay, controls drop
         $Prompt->start();
         $Prompt->feed("kept\e[2Jgone \e[31mred\e[0m");

         $lines = [];
         $picked = 'unset';
         $canceled = 'unset';
         foreach ($Prompt->prompting() as $line) {
            $lines[] = $line;

            // ! Each `pick` line opens a bottom sheet between the yields
            if ($line === 'pick' && $picked === 'unset') {
               $picked = $Prompt->pick(
                  ['/alpha' => ['description' => 'First'], '/beta'],
                  title: 'Sheet',
                  hint: 'Esc cancels'
               );
            }
            elseif ($line === 'pick') {
               $canceled = $Prompt->pick(['/alpha', '/beta']);
            }
         }

         // @ Valid
         yield assert(
            assertion: $lines === ['/help', '@zulu', '/e', '/model', '/exit', '/model', '/echo hey', 'hi', '/echo', '/hi', 'pick', 'pick'],
            description: 'Tab completes; Esc keeps; ⌫ releases; ↑ wraps; a locked trigger stays single-line'
         );
         yield assert(
            assertion: $picked === '/beta',
            description: 'The bottom sheet returns the aimed value on Enter (↓ aimed the second option)'
         );
         yield assert(
            assertion: $canceled === null,
            description: 'Esc cancels the bottom sheet with null'
         );

         rewind($Output->stream);
         $written = (string) stream_get_contents($Output->stream);

         // ! The query accent splits labels with SGR runs — strip them to
         //   assert across paint boundaries
         $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $written);

         yield assert(
            assertion: str_contains($written, '┌') === true
               && str_contains($written, '└') === true,
            description: 'The menu paints inside the bordered Flyout box'
         );
         yield assert(
            assertion: str_contains($stripped, '/model') === true,
            description: 'Listed options paint even when never typed'
         );
         yield assert(
            assertion: str_contains($stripped, '@zebra') === true,
            description: 'A Closure trigger receives the query and its options paint'
         );
         yield assert(
            assertion: str_contains($stripped, 'Lists commands') === true,
            description: 'A structured option paints its description column'
         );
         yield assert(
            assertion: str_contains($stripped, '/help [command]') === true,
            description: 'A resolved command (single match) shows its skeleton'
         );
         yield assert(
            assertion: str_contains($stripped, 'Enter:send') === true
               && str_contains($stripped, 'Tab:complete') === true,
            description: 'Shortcut slots paint below the input (key highlighted, action dim)'
         );
         yield assert(
            assertion: str_contains($stripped, '/echo <text>') === true,
            description: 'The argument hint paints the skeleton while the arguments are typed'
         );
         yield assert(
            assertion: str_contains($stripped, 'Sheet') === true
               && str_contains($stripped, 'Esc cancels') === true
               && str_contains($stripped, 'First') === true,
            description: 'The bottom sheet paints its title, its dim hint row and the details'
         );
         yield assert(
            assertion: str_contains($written, "\e[96m─") === true
               && str_contains($stripped, '$ he') === true
               && str_contains($stripped, '$ /he') === false,
            description: 'An active trigger recolors the frame, swaps the marker and absorbs the symbol'
         );
         yield assert(
            assertion: str_contains($written, "\e[2J") === false
               && str_contains($stripped, 'keptgone') === true
               && str_contains($written, "\e[31mred") === true,
            description: 'feed() drops foreign control escapes and keeps the colors'
         );
         yield assert(
            assertion: $Prompt->Flyout->height === 0,
            description: 'The menu is closed after the last submit'
         );
      }
      else {
         // ! Pipes: triggers never interfere with the plain stdin line loop
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "/help\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);
         $Prompt->triggers = ['/' => ['/help', '/exit']];

         $lines = [];
         foreach ($Prompt->prompting() as $line) {
            $lines[] = $line;
         }

         rewind($Output->stream);
         $written = (string) stream_get_contents($Output->stream);

         // @ Valid
         yield assert(
            assertion: $lines === ['/help'] && $Prompt->finished === true,
            description: 'Non-interactive input yields the line as typed'
         );
         yield assert(
            assertion: str_contains($written, '┌') === false,
            description: 'No menu paints into pipes'
         );
         yield assert(
            assertion: $Prompt->pick(['/alpha', '/beta']) === null,
            description: 'No bottom sheet opens into pipes — pick() degrades to null'
         );
      }
   }
);
