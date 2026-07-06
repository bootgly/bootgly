<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should scan output expressions quote-aware (semicolon in strings)',
   test: new Assertions(function () {
      // @ Valid
      // A semicolon inside a string does not terminate @>
      $Template1 = new Template('@> "a;b";');
      $Template1->render();

      yield new Assertion(
         description: 'Semicolon in a raw output string does not truncate',
         fallback: "Template #1: output does not match: \n`" . $Template1->output . '`'
      )
         ->assert(
            actual: $Template1->output,
            expected: 'a;b'
         );

      // Same for escaped output @>>
      $Template2 = new Template('@>> "a;b";');
      $Template2->render();

      yield new Assertion(
         description: 'Semicolon in an escaped output string does not truncate',
         fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
      )
         ->assert(
            actual: $Template2->output,
            expected: 'a;b'
         );

      // Function calls with string args containing semicolons
      $Template3 = new Template('@> strlen("x;y;z");');
      $Template3->render();

      yield new Assertion(
         description: 'Semicolon inside a call argument string is preserved',
         fallback: "Template #3: output does not match: \n`" . $Template3->output . '`'
      )
         ->assert(
            actual: $Template3->output,
            expected: '5'
         );

      // Single-quoted strings and escaped quotes
      $Template4 = new Template("@> 'it\\'s; fine';");
      $Template4->render();

      yield new Assertion(
         description: 'Escaped quote and semicolon inside single-quoted string survive',
         fallback: "Template #4: output does not match: \n`" . $Template4->output . '`'
      )
         ->assert(
            actual: $Template4->output,
            expected: "it's; fine"
         );

      // Break-line output variants stay quote-aware
      $Template5 = new Template('@>. "a;b";');
      $Template5->render();

      yield new Assertion(
         description: 'Break-line-after output stays quote-aware',
         fallback: "Template #5: output does not match: \n`" . $Template5->output . '`'
      )
         ->assert(
            actual: $Template5->output,
            expected: "a;b\n"
         );

      // @ Invalid
      // ...
   })
);
