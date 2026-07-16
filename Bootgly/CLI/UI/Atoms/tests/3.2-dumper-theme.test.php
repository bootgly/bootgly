<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function str_contains;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should select named dump themes and pass caps through',
   test: function () {
      // @ Named theme selection
      Vars\Dumper::$Themes['test.atom'] = [
         Vars\Dumper::TYPE_INT => '35'
      ];

      $Output = new Output('php://memory');
      $Dumper = new Dumper($Output);
      $Dumper->decoration = true;
      $Dumper->theme = 'test.atom';
      $Dumper->value = 5;

      $rendered = (string) $Dumper->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[35m5\e[0m") === true
            && str_contains($rendered, "\e[38;2;209;154;102m") === false,
         description: 'A registered named theme paints with its own values'
      );

      unset(Vars\Dumper::$Themes['test.atom']);

      // @ Plain wins over theme — decoration off degrades to zero escapes
      $Dumper->decoration = false;
      $rendered = (string) $Dumper->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e") === false,
         description: 'decoration = false renders colorless regardless of theme'
      );

      // @ Caps passthrough — items cap visible through the Atom
      $Dumper = new Dumper(new Output('php://memory'));
      $Dumper->decoration = false;
      $Dumper->items = 1;
      $Dumper->value = [1, 2, 3];

      $rendered = (string) $Dumper->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '… +2 more') === true,
         description: 'Engine caps are settable through the Atom'
      );
   }
);
