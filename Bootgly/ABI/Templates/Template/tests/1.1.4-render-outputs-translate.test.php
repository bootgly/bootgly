<?php

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render translated outputs (@translate) escaped by default',
   test: new Assertions(function () {
      Language::reset();

      // @ Valid
      // Zero catalogs — the natural-source key renders verbatim
      $Template1 = new Template("@translate 'Sign in';");
      $Template1->render();

      yield new Assertion(
         description: 'Key renders verbatim with zero catalogs',
         fallback: "Template #1: output does not match: \n`" . $Template1->output . '`'
      )
         ->assert(
            actual: $Template1->output,
            expected: 'Sign in'
         );

      // Substitutions with render data
      $Template2 = new Template("@translate 'Welcome, {name}!', ['name' => \$name];");
      $Template2->render(['name' => 'Bootgly']);

      yield new Assertion(
         description: 'Placeholders interpolate render data',
         fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
      )
         ->assert(
            actual: $Template2->output,
            expected: 'Welcome, Bootgly!'
         );

      // Escaped by default — substitutions are an XSS surface
      $Template3 = new Template("@translate 'Welcome, {name}!', ['name' => \$name];");
      $Template3->render(['name' => '<b>x</b>']);

      yield new Assertion(
         description: 'Substituted values are HTML-escaped',
         fallback: "Template #3: output does not match: \n`" . $Template3->output . '`'
      )
         ->assert(
            actual: $Template3->output,
            expected: 'Welcome, &lt;b&gt;x&lt;/b&gt;!'
         );

      // Plural selection via named argument
      $Template4 = new Template("@translate '{count} result|{count} results', count: \$n;");
      $Template4->render(['n' => 7]);

      yield new Assertion(
         description: 'Named count argument selects the plural form',
         fallback: "Template #4: output does not match: \n`" . $Template4->output . '`'
      )
         ->assert(
            actual: $Template4->output,
            expected: '7 results'
         );

      // Catalog hit through the active locale
      Language::load(__DIR__ . '/translations');
      Language::$locale = 'pt-BR';

      $Template5 = new Template("@translate 'Welcome, {name}!', ['name' => \$name];");
      $Template5->render(['name' => 'Bootgly']);

      yield new Assertion(
         description: 'Catalog translation renders for the active locale',
         fallback: "Template #5: output does not match: \n`" . $Template5->output . '`'
      )
         ->assert(
            actual: $Template5->output,
            expected: 'Bem-vindo, Bootgly!'
         );

      Language::reset();

      // @ Neutral
      // Escaped directive emits its literal form
      $Template6 = new Template("@@translate 'Sign in';");
      $Template6->render();

      yield new Assertion(
         description: 'Escaped @@translate emits the literal directive',
         fallback: "Template #6: output does not match: \n`" . $Template6->output . '`'
      )
         ->assert(
            actual: $Template6->output,
            expected: "@translate 'Sign in';"
         );

      // A `;` inside a quoted argument does not truncate the directive
      $Template7 = new Template("@translate 'a; b';");
      $Template7->render();

      yield new Assertion(
         description: 'Quoted semicolons survive the argument scan',
         fallback: "Template #7: output does not match: \n`" . $Template7->output . '`'
      )
         ->assert(
            actual: $Template7->output,
            expected: 'a; b'
         );
   })
);
