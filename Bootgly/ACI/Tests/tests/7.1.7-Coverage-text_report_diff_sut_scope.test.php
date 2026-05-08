<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — text report diff respects SUT target filtering',

   test: new Assertions(Case: function (): Generator {
      $target = tempnam(sys_get_temp_dir(), 'bootgly-coverage-target-');
      $neighbor = tempnam(sys_get_temp_dir(), 'bootgly-coverage-neighbor-');
      if ($target === false || $neighbor === false) {
         throw new RuntimeException('Could not create coverage diff fixtures.');
      }

      file_put_contents($target, "<?php\n\$target = true;\n");
      file_put_contents($neighbor, "<?php\n\$neighbor = true;\n");

      try {
         $Driver = new class ($target, $neighbor) extends Driver {
            public function __construct (private string $target, private string $neighbor) {}

            public function collect (): array
            {
               return [
                  $this->target => [2 => 1],
                  $this->neighbor => [2 => 1],
               ];
            }
         };

         $Cov = new Coverage($Driver);
         $Cov->diff = true;
         $Cov->targets = [$target];
         $Cov->start();
         $Cov->stop();

         $text = $Cov->report('text');
         $targetPath = realpath($target) ?: $target;
         $neighborPath = realpath($neighbor) ?: $neighbor;

         yield (new Assertion(description: 'target fixture remains in diff output'))
            ->expect(str_contains($text, $targetPath))
            ->to->be(true)
            ->assert();

         yield (new Assertion(description: 'neighbor fixture is filtered out of diff output'))
            ->expect(str_contains($text, $neighborPath))
            ->to->be(false)
            ->assert();
      }
      finally {
         unlink($target);
         unlink($neighbor);
      }
   })
);
