<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function str_contains;

use Bootgly\ABI\Code\__String\Tokens;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render with a selected named theme',
   test: function () {
      Tokens\Highlighter::$Themes['test.atom'] = [
         Tokens::TOKEN_VARIABLE => '35'
      ];

      // @ Selected theme — its values paint the tokens
      $Highlighter = new Highlighter(new Output('php://memory'));
      $Highlighter->decoration = true;
      $Highlighter->theme = 'test.atom';
      $Highlighter->gutter = false;
      $Highlighter->source = "\$a = 1;";
      $rendered = (string) $Highlighter->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[35m\$a") === true
            && str_contains($rendered, "\e[96m") === false,
         description: 'The selected named theme paints the tokens'
      );

      // @ Plain decoration wins over any selected theme
      $Highlighter = new Highlighter(new Output('php://memory'));
      $Highlighter->decoration = false;
      $Highlighter->theme = 'test.atom';
      $Highlighter->gutter = false;
      $Highlighter->source = "\$a = 1;";
      $rendered = (string) $Highlighter->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e") === false,
         description: 'Plain decoration overrides the selected theme'
      );

      unset(Tokens\Highlighter::$Themes['test.atom']);
   }
);
