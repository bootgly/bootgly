<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function count;
use function explode;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should colorize php fences via the pluggable highlighters',
   test: function () {
      $make = static function (string $source, null|bool $decoration = true): Markdown {
         $Markdown = new Markdown(new Output('php://memory'));
         $Markdown->width = 60;
         $Markdown->decoration = $decoration;
         $Markdown->source = $source;

         return $Markdown;
      };

      // @ php fence — colorized, fence markers stay themed
      $rendered = (string) $make("```php\n\$a = 'x';\n```")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[96m") === true
            && str_contains($rendered, "\e[92m") === true
            && str_contains($rendered, "\e[90m```php") === true,
         description: 'php fences paint variables, strings and keep the fence markers'
      );
      yield assert(
         assertion: str_contains($rendered, "\e[2m") === false,
         description: 'Highlighted fences drop the dimmed fallback'
      );

      // @ Uppercase infoword
      $rendered = (string) $make("```PHP\n\$a = 1;\n```")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[96m") === true,
         description: 'The language infoword matches case-insensitively'
      );

      // @ Other and bare fences keep the dimmed source
      $rendered = (string) $make("```js\nvar a = 1;\n```")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[2mvar a = 1;") === true
            && str_contains($rendered, "\e[96m") === false,
         description: 'Unplugged languages stay verbatim and dimmed'
      );

      $rendered = (string) $make("```\nplain text\n```")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[2mplain text") === true,
         description: 'Bare fences stay verbatim and dimmed'
      );

      // @ Plain output never highlights
      $rendered = (string) $make("```php\n\$a = 1;\n```", false)->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e") === false
            && str_contains($rendered, '$a = 1;') === true,
         description: 'Plain output stays escape-free and verbatim'
      );

      // @ Hostile escapes are cleaned before tokenization
      $rendered = (string) $make("```php\n\$a = \"\e[31mX\";\n```")->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[31m") === false
            && str_contains($rendered, 'X') === true,
         description: 'Injected escapes never reach the highlighted output'
      );

      // @ Line-count parity — interior blank lines survive highlighting
      $rendered = (string) $make("```php\n\$a = 1;\n\n\$b = 2;\n```")->render(Component::RETURN_OUTPUT);
      $parts = explode("\n", $rendered);

      yield assert(
         assertion: count($parts) === 6 && $parts[2] === '',
         description: 'Highlighted fences keep the source line count and blank lines'
      );

      // @ Pluggable — a custom language highlighter is honored
      $Markdown = $make("```js\nvar a;\n```");
      $Markdown->Highlighters['js'] = static fn (string $source): null|string => "[JS]{$source}";
      $rendered = (string) $Markdown->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '[JS]var a;') === true,
         description: 'A plugged highlighter takes over its language fences'
      );

      // @ Pluggable — removing the default falls back to dim
      $Markdown = $make("```php\n\$a = 1;\n```");
      unset($Markdown->Highlighters['php']);
      $rendered = (string) $Markdown->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[2m\$a = 1;") === true,
         description: 'Unplugging the php highlighter restores the dimmed fallback'
      );

      // @ Pluggable — a declining highlighter (null) falls back to dim
      $Markdown = $make("```php\n\$a = 1;\n```");
      $Markdown->Highlighters['php'] = static fn (string $source): null|string => null;
      $rendered = (string) $Markdown->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[2m\$a = 1;") === true,
         description: 'A declining highlighter falls back to the dimmed source'
      );
   }
);
