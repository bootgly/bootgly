<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render verbatim regions untouched',
   test: new Assertions(function () {
      // @ Valid
      // Verbatim block protects directives from compilation
      $Template1 = new Template("@!:\n@> \$a;\n@!;");
      $Template1->render(['a' => 'x']);

      yield new Assertion(
         description: 'Verbatim block passes directives through untouched',
         fallback: "Template #1: output does not match: \n`" . $Template1->output . '`'
      )
         ->assert(
            actual: $Template1->output,
            expected: "\n@> \$a;\n"
         );

      // Directives around a verbatim block still compile
      $Template2 = new Template("@> \$a;\n@!:@> \$a;@!;\n@> \$a;");
      $Template2->render(['a' => 'x']);

      yield new Assertion(
         description: 'Directives around verbatim still compile',
         fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
      )
         ->assert(
            actual: $Template2->output,
            expected: "x@> \$a;\nx"
         );

      // @ Neutral
      // Escaped tokens emit their literal form
      $Template3 = new Template('@@!: literal @@!;');
      $Template3->render();

      yield new Assertion(
         description: 'Escaped verbatim tokens emit literals',
         fallback: "Template #3: output does not match: \n`" . $Template3->output . '`'
      )
         ->assert(
            actual: $Template3->output,
            expected: '@!: literal @!;'
         );

      // @ Invalid
      // Security: PHP open tags inside a verbatim block render as literal text
      //   and never execute (raw PHP is `@:` … `@;`, not verbatim).
      $witness = sys_get_temp_dir() . '/bootgly-verbatim-' . uniqid();
      @unlink($witness);

      $Template4 = new Template(
         "@!:<?php file_put_contents('{$witness}', 'EXEC'); ?>@!;"
      );
      $Template4->render();

      yield new Assertion(
         description: 'PHP open tag inside verbatim does not execute',
         fallback: 'Witness created: ' . (is_file($witness) ? 'YES (RCE!)' : 'no')
      )
         ->assert(
            actual: is_file($witness),
            expected: false
         );
      yield new Assertion(
         description: 'PHP open tag inside verbatim renders as literal text',
         fallback: "Template #4: output does not match: \n`" . $Template4->output . '`'
      )
         ->assert(
            actual: $Template4->output,
            expected: "<?php file_put_contents('{$witness}', 'EXEC'); ?>"
         );

      // Short-echo and short open tags are neutralized too
      $Template5 = new Template('@!:<?= 1+1;?> and <? echo 9;?>@!;');
      $Template5->render();

      yield new Assertion(
         description: 'Short-echo and short open tags render as literal text',
         fallback: "Template #5: output does not match: \n`" . $Template5->output . '`'
      )
         ->assert(
            actual: $Template5->output,
            expected: '<?= 1+1;?> and <? echo 9;?>'
         );

      @unlink($witness);
   })
);
