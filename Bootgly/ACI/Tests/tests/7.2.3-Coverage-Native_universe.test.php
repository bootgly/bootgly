<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Universe;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — Native Universe registers and merges executable lines',

   test: new Assertions(Case: function (): Generator {
      $lines = Universe::$lines;
      $spans = Universe::$spans;
      $labels = Universe::$labels;
      $declarations = Universe::$declarations;

      try {
         Universe::reset();

         Universe::register('/a.php', [10 => 0, 20 => 0]);
         Universe::register('/a.php', [20 => 0, 30 => 0]);
         Universe::register('/b.php', [5 => 0]);

         yield (new Assertion(description: 'registers two distinct files'))
            ->expect(count(Universe::$lines))
            ->to->be(2)
            ->assert();

         yield (new Assertion(description: 'merges line sets per file'))
            ->expect(count(Universe::$lines['/a.php']))
            ->to->be(3)
            ->assert();

         $merged = Universe::merge([
            '/a.php' => [10 => 5, 99 => 1],
         ]);

         yield (new Assertion(description: 'merge preserves zero-hit denominator lines'))
            ->expect($merged['/a.php'][20])
            ->to->be(0)
            ->assert();

         yield (new Assertion(description: 'merge applies live hit counts'))
            ->expect($merged['/a.php'][10])
            ->to->be(5)
            ->assert();

         yield (new Assertion(description: 'merge keeps untouched files'))
            ->expect($merged['/b.php'][5])
            ->to->be(0)
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
