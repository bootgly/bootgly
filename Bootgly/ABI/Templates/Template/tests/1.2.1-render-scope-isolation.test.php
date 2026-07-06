<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should not leak executor internals into the template scope',
   test: new Assertions(function () {
      // @ Valid
      // The compiled cache path ($__file__) is not a template variable
      $Template1 = new Template("@> isSet(\$__file__) ? 'LEAK' : 'isolated';");
      $Template1->render();

      yield new Assertion(
         description: 'The compiled cache path is not exposed as $__file__',
         fallback: "Template #1: output does not match: \n`" . $Template1->output . '`'
      )
         ->assert(
            actual: $Template1->output,
            expected: 'isolated'
         );

      // The parameter bag ($parameters) is not a template variable
      $Template2 = new Template("@> isSet(\$parameters) ? 'LEAK' : 'isolated';");
      $Template2->render(['secret' => 'hunter2']);

      yield new Assertion(
         description: 'The parameter bag is not exposed as $parameters',
         fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
      )
         ->assert(
            actual: $Template2->output,
            expected: 'isolated'
         );

      // Supplied data still reaches the template as its own variable
      $Template3 = new Template('@> $name;');
      $Template3->render(['name' => 'Bootgly']);

      yield new Assertion(
         description: 'Supplied data is still available by its own name',
         fallback: "Template #3: output does not match: \n`" . $Template3->output . '`'
      )
         ->assert(
            actual: $Template3->output,
            expected: 'Bootgly'
         );

      // @ Invalid
      // Security: a user key `__file__` cannot redirect the include target
      $Template4 = new Template('@> "safe";');
      $Template4->render(['__file__' => '/etc/passwd']);

      yield new Assertion(
         description: 'A user __file__ key cannot hijack the include target',
         fallback: "Template #4: output does not match: \n`" . $Template4->output . '`'
      )
         ->assert(
            actual: $Template4->output,
            expected: 'safe'
         );
   })
);
