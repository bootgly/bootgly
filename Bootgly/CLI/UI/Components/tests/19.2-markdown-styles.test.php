<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should style elements from the overridable palette',
   test: function () {
      // ! Factory
      $make = static function (string $source): Markdown {
         $Markdown = new Markdown(new Output('php://memory'));
         $Markdown->width = 60;
         $Markdown->decoration = true;
         $Markdown->source = $source;

         // :
         return $Markdown;
      };

      // @ Heading styles apply per level and keep the # prefix
      $rendered = (string) $make('# One')->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[1;4;96m") === true
            && str_contains($rendered, '# One') === true,
         description: 'h1 opens bold+underline+bright-cyan and keeps the prefix'
      );

      // @ Nested emphasis restores the enclosing style after the inner reset
      $rendered = (string) $make('**a *b* c**')->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[3m") === true
            && str_contains($rendered, "\e[0m\e[1m c") === true,
         description: 'Closing the italic reopens the enclosing bold'
      );

      // @ Links style the label and dim the URL
      $rendered = (string) $make('[lbl](https://b.ly)')->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[4;94mlbl") === true
            && str_contains($rendered, "\e[2m(https://b.ly)") === true,
         description: 'The link label underlines and the URL dims'
      );

      // @ The palette is user-overridable
      $Custom = $make('# X');
      $Custom->styles['h1'] = ['35'];

      $rendered = (string) $Custom->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[35m") === true
            && str_contains($rendered, "\e[1;4;96m") === false,
         description: 'Overriding styles[h1] replaces the default SGR'
      );

      // @ Inline code paints and never interprets emphasis
      $rendered = (string) $make('`*x*`')->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[33m*x*") === true,
         description: 'Inline code renders literally in the code color'
      );
   }
);
