<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should paint quotes, lists, tasks, fences and rules',
   test: function () {
      // ! Plain factory — structural asserts stay byte-exact
      $make = static function (string $source, null|int $width = 40): Markdown {
         $Markdown = new Markdown(new Output('php://memory'));
         $Markdown->width = $width;
         $Markdown->decoration = false;
         $Markdown->source = $source;

         // :
         return $Markdown;
      };

      // @ Quote gutters stack with nesting
      $rendered = (string) $make("> outer\n> > inner")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '│ outer') === true
            && str_contains($rendered, '│ │ inner') === true,
         description: 'Nested quotes stack their gutters'
      );

      // @ Bullets, ordered markers and continuation indent
      $rendered = (string) $make("- alpha\n- beta")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '• alpha') === true
            && str_contains($rendered, '• beta') === true,
         description: 'Unordered items render with bullets'
      );

      $rendered = (string) $make("9. nine\n10. ten")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '9.  nine') === true
            && str_contains($rendered, '10. ten') === true,
         description: 'Ordered markers align to the widest number'
      );

      // @ Nested list hugs its parent item (tight)
      $rendered = (string) $make("- parent\n  - child")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "• parent\n  • child") === true,
         description: 'A nested list renders directly under its item'
      );

      // @ Task glyphs
      $rendered = (string) $make("- [x] done\n- [ ] todo")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '[✓] done') === true
            && str_contains($rendered, '[ ] todo') === true,
         description: 'Task items render check glyphs'
      );

      // @ Fences render verbatim between fence lines
      $rendered = (string) $make("```php\n\$x = '**raw**';\n```")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '```php') === true
            && str_contains($rendered, "\$x = '**raw**';") === true,
         description: 'Fenced code keeps its source verbatim'
      );

      // @ Rules span the width
      $rendered = (string) $make('---', width: 20)->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '────────────────────') === true,
         description: 'The rule spans the configured width'
      );
   }
);
