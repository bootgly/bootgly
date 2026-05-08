<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Compiler;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Universe;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — Native parity projects statement hits to label/declaration/expression lines',

   test: new Assertions(Case: function (): Generator {
   $lines = Universe::$lines;
   $spans = Universe::$spans;
   $labels = Universe::$labels;
   $declarations = Universe::$declarations;

   try {
      Universe::reset();

      $source = <<<'PHP'
<?php
class Demo {
   public function run (int $n): array
   {
      switch ($n) {
         case 1:
            return [
               'a' => 1,
               'b' => 2,
            ];
         default:
            return [];
      }
   }
}
PHP;

         $file = '/tmp/ParityFixture.php';
         $fixtureLines = [];
         $fixtureSpans = [];
         $fixtureLabels = [];
         $fixtureDeclarations = [];

         Compiler::compile(
            source: $source,
            file: $file,
            lines: $fixtureLines,
            mode: Native::MODE_PARITY,
            spans: $fixtureSpans,
            labels: $fixtureLabels,
            declarations: $fixtureDeclarations
         );

         Universe::register($file, $fixtureLines, $fixtureSpans, $fixtureLabels, $fixtureDeclarations);

         $strict = Universe::merge([$file => [7 => 1]], Native::MODE_STRICT);
         yield (new Assertion(description: 'strict mode keeps inner expression lines as non-hit denominator'))
            ->expect(($strict[$file][8] ?? null) === 0 && ($strict[$file][9] ?? null) === 0)
            ->to->be(true)
            ->assert();

         $parity = Universe::merge([$file => [7 => 1]], Native::MODE_PARITY);

         yield (new Assertion(description: 'parity mode marks class declaration when file executed'))
            ->expect($parity[$file][2] ?? null)
            ->to->be(1)
            ->assert();

         yield (new Assertion(description: 'parity mode marks entered case label as hit'))
            ->expect($parity[$file][6] ?? null)
            ->to->be(1)
            ->assert();

         yield (new Assertion(description: 'parity mode projects return-array execution to inner lines'))
            ->expect(($parity[$file][8] ?? null) === 1 && ($parity[$file][9] ?? null) === 1)
            ->to->be(true)
            ->assert();
      }
      finally {
         Universe::$lines = $lines;
         Universe::$spans = $spans;
         Universe::$labels = $labels;
         Universe::$declarations = $declarations;
      }
   })
);
