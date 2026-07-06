<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;
use Bootgly\ABI\Templates\Template\Exceptions\TemplateException;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should throw TemplateException pointing at the template line',
   test: new Assertions(function () {
      // !
      $file = sys_get_temp_dir() . '/bootgly-' . uniqid() . '.template.php';

      // @ Invalid
      // Runtime error after merged PHP blocks (line parity across blank lines)
      file_put_contents($file, <<<'TEMPLATE'
      @:
      $a = 1;
      @;

      @:
      $b = 2;
      @;
      @> $a + $b + $Boom->c();
      TEMPLATE);

      $Exception1 = null;
      try {
         new Template(new File($file))->render();
      }
      catch (TemplateException $Exception1) {
      }

      yield new Assertion(
         description: 'Runtime error maps to the template file',
         fallback: 'Exception file: `' . ($Exception1?->getFile() ?? '<none>') . '`'
      )
         ->assert(
            actual: $Exception1?->getFile(),
            expected: $file
         );
      yield new Assertion(
         description: 'Runtime error maps to the template line',
         fallback: 'Exception line: `' . ($Exception1?->getLine() ?? '<none>') . '`'
      )
         ->assert(
            actual: $Exception1?->getLine(),
            expected: 8
         );
      yield new Assertion(
         description: 'Original error is chained as previous',
         fallback: 'Previous: `' . ($Exception1?->getPrevious()?->getMessage() ?? '<none>') . '`'
      )
         ->assert(
            actual: $Exception1?->getPrevious() instanceof Throwable,
            expected: true
         );

      // Syntax error (ParseError) maps to the template line
      file_put_contents($file, <<<'TEMPLATE'
      line
      @if $a and:
      @if;
      TEMPLATE);
      touch($file, time() + 2);

      $Exception2 = null;
      try {
         new Template(new File($file))->render(['a' => true]);
      }
      catch (TemplateException $Exception2) {
      }

      yield new Assertion(
         description: 'Syntax error maps to the template line',
         fallback: 'Exception: `' . ($Exception2?->getMessage() ?? '<none>')
            . '` at line `' . ($Exception2?->getLine() ?? '<none>') . '`'
      )
         ->assert(
            actual: $Exception2?->getLine(),
            expected: 2
         );

      // Inline template keeps template = null and reports the line in the message
      $Exception3 = null;
      try {
         new Template("first\n@> \$Boom->c();")->render();
      }
      catch (TemplateException $Exception3) {
      }

      yield new Assertion(
         description: 'Inline template has no source file',
         fallback: 'Template: `' . ($Exception3?->template ?? '<null>') . '`'
      )
         ->assert(
            actual: $Exception3?->template,
            expected: null
         );
      yield new Assertion(
         description: 'Inline template reports the line in the message',
         fallback: 'Message: `' . ($Exception3?->getMessage() ?? '<none>') . '`'
      )
         ->assert(
            actual: str_contains((string) $Exception3?->getMessage(), 'at line 2'),
            expected: true
         );

      // ! Cleanup
      @unlink($file);
   })
);
