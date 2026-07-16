<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function str_contains;
use ValueError;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should resolve named figlet fonts from the registry',
   test: function () {
      // @ Custom registered font — a raw glyph map
      Figlet::$Fonts['test.dots'] = [
         'A' => "•A•\n• •",
         'B' => "•B•\n• •"
      ];

      $Figlet = new Figlet(new Output('php://memory'));
      $Figlet->font = 'test.dots';
      $Figlet->text = 'AB';

      $rendered = (string) $Figlet->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '•A• •B•') === true,
         description: 'A registered glyph map renders side by side'
      );

      unset(Figlet::$Fonts['test.dots']);

      // @ Builtin `shadow` stays the default
      $Figlet = new Figlet(new Output('php://memory'));

      yield assert(
         assertion: $Figlet->font === 'shadow',
         description: 'The builtin shadow font is the default'
      );

      // @ Unknown names fail loud
      $caught = false;
      try {
         $Figlet->font = 'nonexistent';
         $Figlet->render(Component::RETURN_OUTPUT);
      }
      catch (ValueError) {
         $caught = true;
      }

      yield assert(
         assertion: $caught === true,
         description: 'An unknown font name throws a ValueError'
      );
   }
);
