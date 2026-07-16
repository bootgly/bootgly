<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function count;
use function explode;
use function rtrim;
use function str_contains;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render large glyph text side by side and stacked',
   test: function () {
      $Figlet = new Figlet(new Output('php://memory'));

      // @ Inline — one output row per glyph row, gap-joined
      $Figlet->text = 'OK';
      $rendered = (string) $Figlet->render(Component::RETURN_OUTPUT);
      $lines = explode("\n", rtrim($rendered, "\n"));

      yield assert(
         assertion: count($lines) === 6
            && str_contains($lines[0], '██████╗') === true,
         description: 'Inline text composes six gap-joined glyph rows'
      );

      // @ Lowercase maps to the uppercase glyphs
      $Figlet->text = 'ok';

      yield assert(
         assertion: $Figlet->render(Component::RETURN_OUTPUT) === $rendered,
         description: 'Lowercase input renders the uppercase glyphs'
      );

      // @ Digits — the shadow font covers 0-9
      $Figlet->text = '42';
      $rendered = (string) $Figlet->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '███████║') === true
            && substr_count($rendered, "\n") === 6,
         description: 'Digits render from the same font'
      );

      // @ Unknown characters degrade to spaces — never a crash
      $Figlet->text = 'A!';
      $rendered = (string) $Figlet->render(Component::RETURN_OUTPUT);
      $lines = explode("\n", rtrim($rendered, "\n"));

      yield assert(
         assertion: count($lines) === 6
            && str_contains($lines[0], '██████╗') === true,
         description: 'Characters without a glyph render as spaces'
      );

      // @ Stacked — one glyph block per character
      $Figlet->text = 'OK';
      $Figlet->stacked = true;
      $rendered = (string) $Figlet->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: substr_count($rendered, "\n") === 12,
         description: 'Stacked mode renders one block per character'
      );
   }
);
