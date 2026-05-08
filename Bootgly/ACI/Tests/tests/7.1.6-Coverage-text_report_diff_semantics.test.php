<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — text report diff maps covered and uncovered executable lines',

   test: new Assertions(Case: function (): Generator {
      $file = tempnam(sys_get_temp_dir(), 'bootgly-coverage-diff-');
      if ($file === false) {
         throw new RuntimeException('Could not create coverage diff fixture.');
      }

      file_put_contents($file, "<?php\n\$hit = true;\n\$miss = false;\n");

      try {
         $Driver = new class ($file) extends Driver {
            public function __construct (private string $file) {}

            public function collect (): array
            {
               return [$this->file => [2 => 1, 3 => 0]];
            }
         };

         $Cov = new Coverage($Driver);
         $Cov->diff = true;
         $Cov->start();
         $Cov->stop();

         $text = $Cov->report('text');

         yield (new Assertion(description: 'covered line is rendered on the covered side'))
            ->expect(str_contains($text, '+2 | $hit = true;'))
            ->to->be(true)
            ->assert();

         yield (new Assertion(description: 'uncovered line is rendered on the uncovered side'))
            ->expect(str_contains($text, '-3 | $miss = false;'))
            ->to->be(true)
            ->assert();
      }
      finally {
         unlink($file);
      }
   })
);
