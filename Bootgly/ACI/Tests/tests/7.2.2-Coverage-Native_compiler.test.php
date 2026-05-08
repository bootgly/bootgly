<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Compiler;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — Native Compiler rewrites magic constants and produces valid PHP',

   test: new Assertions(Case: function (): Generator {
      $source = <<<'PHP'
<?php
namespace Demo;

class Sample {
   public string $name = 'x';
   public function run (): array {
      $a = 1;
      if ($a > 0) {
         return [$a, __FILE__, __DIR__];
      }
      return [];
   }
}
PHP;

      $file = '/abs/path/to/Sample.php';
      $lines = [];
      $rewritten = Compiler::compile($source, $file, $lines);

      yield (new Assertion(description: 'rewritten source is syntactically valid PHP'))
          ->expect(@token_get_all($rewritten) !== false)
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'hit marker is injected for executable statements'))
          ->expect(str_contains($rewritten, 'Bootgly\\ACI\\Tests\\Coverage::hit'))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: '__FILE__ is replaced with literal'))
          ->expect(str_contains($rewritten, "'/abs/path/to/Sample.php'"))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: '__DIR__ is replaced with dirname literal'))
          ->expect(str_contains($rewritten, "'/abs/path/to'"))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'compiler reports executable lines'))
          ->expect(count($lines) > 0)
         ->to->be(true)
         ->assert();

      $source = <<<'PHP'
<?php
function render_header (bool|string $value, string $name): string {
   return ($value === true) ? match ($name) {
      'Date' => 'today',
      default => ''
   } : (string) $value;
}
PHP;

      $lines = [];
      $rewritten = Compiler::compile($source, '/abs/path/to/Header.php', $lines);

      yield (new Assertion(description: 'match expression inside ternary keeps false arm intact'))
         ->expect(str_contains($rewritten, ': \Bootgly\ACI\Tests\Coverage::hit'))
         ->to->be(false)
         ->assert();
   })
);
