<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Fixture: a `?->` chain and a property chain, both inside real conditions.
 */
class TemplateAccount_3_1_2_3
{
   public bool $active = true;
   public string $name = 'ana';
}


return new Test(
   description: 'It should rewrite the short conditional only at the end of the condition',
   test: function () {
      // ! A compile failure is reported as its message, so a broken rewrite shows what
      //   it produced instead of aborting the suite
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
      // The sugar used to rewrite ANY `$… ?` in the condition, so a nullsafe chain
      // became `!empty($User)->active` (TPL-5)
      $output = $render('@if $User?->active:ON@if;', ['User' => new TemplateAccount_3_1_2_3()]);
      yield assert(
         assertion: $output === 'ON',
         description: "A `?->` chain must survive the rewrite: \n`{$output}`"
      );

      $output = $render('@if $User?->active:ON@if;', ['User' => null]);
      yield assert(
         assertion: $output === '',
         description: "A null `?->` chain must render nothing: \n`{$output}`"
      );

      // `??` inside a real condition is not the trailing marker either
      $output = $render('@if ($a ?? false):Y@if;', ['a' => true]);
      yield assert(
         assertion: $output === 'Y',
         description: "A `??` inside a condition must survive: \n`{$output}`"
      );

      $output = $render('@if ($data["k"] ?? false):Y@if;', ['data' => ['k' => true]]);
      yield assert(
         assertion: $output === 'Y',
         description: "A `??` against a missing key must survive: \n`{$output}`"
      );

      // @ Neutral
      // The sugar itself keeps working, bare and parenthesized
      $output = $render('@if $items?:HAS@if;', ['items' => [1]]);
      yield assert(
         assertion: $output === 'HAS',
         description: "Bare `?` sugar: \n`{$output}`"
      );

      $output = $render('@if ($items?):HAS@if;', ['items' => [1]]);
      yield assert(
         assertion: $output === 'HAS',
         description: "Parenthesized `?` sugar: \n`{$output}`"
      );

      $output = $render('@if $items??:SET@if;', ['items' => 0]);
      yield assert(
         assertion: $output === 'SET',
         description: "Bare `??` sugar: \n`{$output}`"
      );

      $output = $render('@if ($items??):SET@if;', ['items' => 0]);
      yield assert(
         assertion: $output === 'SET',
         description: "Parenthesized `??` sugar: \n`{$output}`"
      );

      $output = $render("@if \$data['k']?:HAS@if;", ['data' => ['k' => 'x']]);
      yield assert(
         assertion: $output === 'HAS',
         description: "Array-index `?` sugar: \n`{$output}`"
      );

      $output = $render(
         '@if $User->name?:HAS@if;',
         ['User' => new TemplateAccount_3_1_2_3()]
      );
      yield assert(
         assertion: $output === 'HAS',
         description: "Property-chain `?` sugar: \n`{$output}`"
      );

      // @ Invalid
      // ...
   }
);
