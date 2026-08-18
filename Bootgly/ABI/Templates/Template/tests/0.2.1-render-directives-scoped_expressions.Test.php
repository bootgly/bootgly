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

      // The same scan consumes quoted strings whole, so a colon inside one survives
      $output = $render("@if (\$s === 'a:b'):HIT@if;", ['s' => 'a:b']);
      yield assert(
         assertion: $output === 'HIT',
         description: "@if comparing against a string holding a colon: \n`{$output}`"
      );

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
