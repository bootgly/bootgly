<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Fixture: every `:`-opened directive is exercised against a `::` expression.
 */
class TemplateScope_0_2_1
{
   public const string ACTIVE = 'active';
   public const int LIMIT = 2;
}


return new Test(
   description: 'It should keep `::` and quoted colons inside directive expressions',
   test: function () {
      // ! A compile failure is reported as its message, so a broken scan shows what it
      //   produced instead of aborting the suite
      $render = function (string $source, array $parameters = []): string {
         try {
            $Template = new Template($source);
            $Template->render($parameters);

            return $Template->output;
         }
         catch (Throwable $Throwable) {
            return $Throwable->getMessage();
         }
      };

      // @ Valid
      // The lazy `(.+?)[ ]?:` scan used to stop at the first colon of a `::`,
      // cutting class-constant expressions in half (TPL-4)
      $output = $render(
         '@if ($s === TemplateScope_0_2_1::ACTIVE):YES@if;',
         ['s' => 'active']
      );
      yield assert(
         assertion: $output === 'YES',
         description: "@if with a class constant: \n`{$output}`"
      );

      $output = $render('@if (TemplateScope_0_2_1::LIMIT > 1):BIG@if;');
      yield assert(
         assertion: $output === 'BIG',
         description: "@if opening on a class constant: \n`{$output}`"
      );

      $output = $render(
         '@for ($i = 0; $i < TemplateScope_0_2_1::LIMIT; $i++):@>. $i;@for;'
      );
      yield assert(
         assertion: $output === "0\n1\n",
         description: "@for bounded by a class constant: \n`{$output}`"
      );

      $output = $render(
         '@: $i = 0; @;@while ($i < TemplateScope_0_2_1::LIMIT):@>. $i;@: $i++; @;@while;'
      );
      yield assert(
         assertion: $output === "0\n1\n",
         description: "@while bounded by a class constant: \n`{$output}`"
      );

      // @foreach still needs a variable iterable — Iterators::queue() takes it by
      // reference — so the reachable `::` shape is an index into one
      $output = $render(
         '@foreach ($items[TemplateScope_0_2_1::ACTIVE] as $x):@>. $x;@foreach;',
         ['items' => ['active' => ['a', 'b']]]
      );
      yield assert(
         assertion: $output === "a\nb\n",
         description: "@foreach over an iterable indexed by a class constant: \n`{$output}`"
      );

      $output = $render(
         '@switch $s:@case TemplateScope_0_2_1::ACTIVE:ON@break;@default:OFF@switch;',
         ['s' => 'active']
      );
      yield assert(
         assertion: $output === 'ON',
         description: "@switch/@case on a class constant: \n`{$output}`"
      );

      // @else if opens on a colon too, so it needs the same scan
      $output = $render(
         '@if (false):N@else if ($s === TemplateScope_0_2_1::ACTIVE):Y@if;',
         ['s' => 'active']
      );
      yield assert(
         assertion: $output === 'Y',
         description: "@else if with a class constant: \n`{$output}`"
      );

      // The same scan consumes quoted strings whole, so a colon inside one survives
      $output = $render("@if (\$s === 'a:b'):HIT@if;", ['s' => 'a:b']);
      yield assert(
         assertion: $output === 'HIT',
         description: "@if comparing against a string holding a colon: \n`{$output}`"
      );

      $output = $render("@if (false):N@else if (\$s === 'a:b'):Y@if;", ['s' => 'a:b']);
      yield assert(
         assertion: $output === 'Y',
         description: "@else if comparing against a string holding a colon: \n`{$output}`"
      );

      // A ternary's `:` is a bare single colon — the directive terminator itself — so a
      // parenthesized group has to be consumed whole or the opener ends inside it and
      // the rest of the condition is emitted as page text (TPL-16)
      $output = $render('@if ($a ? true : false):T@if;', ['a' => true]);
      yield assert(
         assertion: $output === 'T',
         description: "@if on a ternary: \n`{$output}`"
      );

      $output = $render('@if ($a ?: $b):T@if;', ['a' => false, 'b' => true]);
      yield assert(
         assertion: $output === 'T',
         description: "@if on an elvis: \n`{$output}`"
      );

      $output = $render('@if ($a === ($b ? 1 : 2)):T@if;', ['a' => 1, 'b' => true]);
      yield assert(
         assertion: $output === 'T',
         description: "@if on a nested ternary: \n`{$output}`"
      );

      $output = $render(
         '@if (false):N@else if ($a ? true : false):T@if;',
         ['a' => true]
      );
      yield assert(
         assertion: $output === 'T',
         description: "@else if on a ternary: \n`{$output}`"
      );

      $output = $render(
         '@for ($i = 0; $i < ($big ? 3 : 2); $i++):@>. $i;@for;',
         ['big' => false]
      );
      yield assert(
         assertion: $output === "0\n1\n",
         description: "@for bounded by a ternary: \n`{$output}`"
      );

      $output = $render(
         '@: $i = 0; @;@while ($i < ($big ? 3 : 2)):@>. $i;@: $i++; @;@while;',
         ['big' => false]
      );
      yield assert(
         assertion: $output === "0\n1\n",
         description: "@while bounded by a ternary: \n`{$output}`"
      );

      $output = $render(
         "@foreach (\$items[\$k ? 'a' : 'b'] as \$x):@>. \$x;@foreach;",
         ['k' => true, 'items' => ['a' => ['x', 'y'], 'b' => []]]
      );
      yield assert(
         assertion: $output === "x\ny\n",
         description: "@foreach over a ternary-selected key: \n`{$output}`"
      );

      $output = $render(
         "@switch (\$a ? 'x' : 'y'):@case 'x':X@break;@default:D@switch;",
         ['a' => true]
      );
      yield assert(
         assertion: $output === 'X',
         description: "@switch on a ternary: \n`{$output}`"
      );

      $output = $render(
         "@switch \$s:@case (\$a ? 'x' : 'y'):X@break;@default:D@switch;",
         ['s' => 'x', 'a' => true]
      );
      yield assert(
         assertion: $output === 'X',
         description: "@case on a ternary: \n`{$output}`"
      );

      // @component's `with` payload opens on the same colon and needed the same scan
      $path = Template::$path;
      Template::$path = __DIR__ . '/templates/';

      try {
         $output = $render(
            "@component components/card with ['x' => (\$a ? 1 : 2)]:B@slot header:H@slot;@component;",
            ['a' => true]
         );
         yield assert(
            assertion: $output === '<div>H|B</div>',
            description: "@component with a ternary in its payload: \n`{$output}`"
         );
      }
      finally {
         Template::$path = $path;
      }

      // @ Neutral
      // The opener is still a single `:`, and escaping still wins over everything
      $output = $render('@@if ($a === 1):');
      yield assert(
         assertion: $output === '@if ($a === 1):',
         description: "Escaped @@if must stay literal: \n`{$output}`"
      );

      // @ Invalid
      // ...
   }
);
