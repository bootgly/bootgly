<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render short empty ifs',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      // Not Empty true
      $Template11 = new Template(
         <<<'TEMPLATE'
         @if $items?:
            @> 'Some items found!';
         @if;
         TEMPLATE
      );
      $Template11->render([
         'items' => 3,
      ]);
      assert(
         assertion: $Template11->output === <<<OUTPUT
         Some items found!
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      );

      // Not Empty false
      $Template12 = new Template(
         <<<'TEMPLATE'
         @if $items?:
            @> 'Some items found!';
         @else:
            @> 'No items found.';
         @if;
         TEMPLATE
      );
      $Template12->render([
         'items' => 0,
      ]);
      assert(
         assertion: $Template12->output === <<<OUTPUT
         No items found.
         OUTPUT,
         description: "Template #1.2: output does not match: \n`" . $Template12->output . '`'
      );

      // isSet true
      $Template13 = new Template(
            <<<'TEMPLATE'
         @if $items??:
            @> 'Items is set!';
         @if;
         TEMPLATE
         );
      $Template13->render([
         'items' => 3,
      ]);
      assert(
         assertion: $Template13->output === <<<OUTPUT
         Items is set!
         OUTPUT,
         description: "Template #1.3: output does not match: \n`" . $Template13->output . '`'
      );
      // isSet false
      $Template14 = new Template(
         <<<'TEMPLATE'
         @if $items??:
            @> 'Items is set!';
         @else:
            @> 'Items is not set!';
         @if;
         TEMPLATE
      );
      $Template14->render([
         'items' => null,
      ]);
      assert(
         assertion: $Template14->output === <<<OUTPUT
         Items is not set!
         OUTPUT,
         description: "Template #1.4: output does not match: \n`" . $Template14->output . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
