<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render escaped outputs (@>>) with HTML entities',
   test: new Assertions(function () {
      // @ Valid
      $Template1 = new Template('@>> $xss;');
      $Template1->render(['xss' => '<script>alert("x")</script>']);

      yield new Assertion(
         description: 'HTML special chars are escaped',
         fallback: "Template #1: output does not match: \n`" . $Template1->output . '`'
      )
         ->assert(
            actual: $Template1->output,
            expected: '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;'
         );

      $Template2 = new Template('@>> $quote;');
      $Template2->render(['quote' => "O'Reilly & Co"]);

      yield new Assertion(
         description: 'Single quotes and ampersands are escaped',
         fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
      )
         ->assert(
            actual: $Template2->output,
            expected: 'O&#039;Reilly &amp; Co'
         );

      $Template3 = new Template('@>> $n;');
      $Template3->render(['n' => 5]);

      yield new Assertion(
         description: 'Non-string values are cast before escaping',
         fallback: "Template #3: output does not match: \n`" . $Template3->output . '`'
      )
         ->assert(
            actual: $Template3->output,
            expected: '5'
         );

      // Multibyte content survives the explicit UTF-8 charset
      $Template6 = new Template('@>> $text;');
      $Template6->render(['text' => 'ação & reação']);

      yield new Assertion(
         description: 'Multibyte UTF-8 content is preserved while escaping',
         fallback: "Template #6: output does not match: \n`" . $Template6->output . '`'
      )
         ->assert(
            actual: $Template6->output,
            expected: 'ação &amp; reação'
         );

      // Raw output stays raw
      $Template4 = new Template('@> $html;');
      $Template4->render(['html' => '<b>bold</b>']);

      yield new Assertion(
         description: 'Raw output (@>) stays unescaped',
         fallback: "Template #4: output does not match: \n`" . $Template4->output . '`'
      )
         ->assert(
            actual: $Template4->output,
            expected: '<b>bold</b>'
         );

      // @ Neutral
      // Escaped directive emits its literal form
      $Template5 = new Template('@@>> $x;');
      $Template5->render();

      yield new Assertion(
         description: 'Escaped @>> emits the literal directive',
         fallback: "Template #5: output does not match: \n`" . $Template5->output . '`'
      )
         ->assert(
            actual: $Template5->output,
            expected: '@>> $x;'
         );

      // @ Invalid
      // ...
   })
);
