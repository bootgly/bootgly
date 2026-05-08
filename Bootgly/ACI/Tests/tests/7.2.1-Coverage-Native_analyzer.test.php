<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Analyzer;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — Native Analyzer detects executable lines',

   test: new Assertions(Case: function (): Generator {
      $source = <<<'PHP'
<?php

namespace Foo;

class Bar
{
   public int $x = 1;
   public function run (int $a): int
   {
      $b = $a + 1;
      if ($b > 0) {
         return $b;
      }
      return 0;
   }
}
PHP;

      $lines = Analyzer::scan($source);

      yield (new Assertion(description: 'declarations are not anchored'))
         ->expect(isset($lines[3]) || isset($lines[5]) || isset($lines[7]) || isset($lines[8]))
         ->to->be(false)
         ->assert();

      yield (new Assertion(description: 'first executable assignment is anchored'))
         ->expect(isset($lines[10]))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'else/elseif continuations are not isolated anchors'))
         ->expect(isset($lines[11]) && isset($lines[12]) && isset($lines[14]))
         ->to->be(true)
         ->assert();
   })
);
