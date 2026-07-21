<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function explode;
use function mb_strwidth;
use function preg_replace;
use function str_contains;
use function substr_count;

use Bootgly\ABI\Code\__String;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should wrap to narrow widths and neutralize injected escapes',
   test: function () {
      // ! Factory
      $make = static function (string $source, null|bool $decoration, null|int $width): Markdown {
         $Markdown = new Markdown(new Output('php://memory'));
         $Markdown->width = $width;
         $Markdown->decoration = $decoration;
         $Markdown->source = $source;

         // :
         return $Markdown;
      };

      // @ Paragraphs wrap to the width
      $rendered = (string) $make('alpha beta gamma delta echo', false, 20)
         ->render(Component::RETURN_OUTPUT);

      foreach (explode("\n", $rendered) as $line) {
         // ? Every wrapped line fits the width
         if ($line === '') {
            continue;
         }

         yield assert(
            assertion: mb_strwidth($line) <= 20,
            description: "Wrapped line fits 20 columns: `{$line}`"
         );
      }

      // @ The width floors at 20 — deep nesting never underflows
      $narrow = $make("> > > - alpha beta gamma delta echo foxtrot", false, 5);

      $rendered = (string) $narrow->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '│ │ │ • alpha') === true,
         description: 'Deeply nested narrow content renders without crashing'
      );

      // @ Styled wrapping reopens the SGR state on continuation lines
      $rendered = (string) $make('**alpha beta gamma delta echo foxtrot**', true, 20)
         ->render(Component::RETURN_OUTPUT);
      $lines = explode("\n", $rendered);

      yield assert(
         assertion: str_contains($lines[1] ?? '', "\e[1m") === true,
         description: 'The second wrapped line reopens the bold style'
      );

      // @ Escape bytes planted in the source never reach the output
      $hostile = $make("evil \e[2J\e[H text `code\e[31m`", false, 40);

      $rendered = (string) $hostile->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: substr_count($rendered, "\e") === 0
            && str_contains($rendered, 'evil') === true,
         description: 'Injected escapes are stripped from plain output'
      );

      $hostile = $make("evil \e[2J text", true, 40);

      $rendered = (string) $hostile->render(Component::RETURN_OUTPUT);
      $visible = (string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $rendered);

      yield assert(
         assertion: str_contains($rendered, "\e[2J") === false
            && str_contains($visible, 'evil [2J text') === true,
         description: 'Injected escapes are stripped from styled output too'
      );
   }
);
