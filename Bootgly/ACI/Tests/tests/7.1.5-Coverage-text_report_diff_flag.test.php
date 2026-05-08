<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — text report only shows per-file diff when flag is enabled',

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
         $Cov->start();
         $Cov->stop();

         $plain = $Cov->report('text');
         yield (new Assertion(description: 'plain text report does not include coverage diff headers'))
            ->expect(str_contains($plain, '--- uncovered:'))
            ->to->be(false)
            ->assert();

         $Cov->diff = true;
         $with = $Cov->report('text');
         yield (new Assertion(description: 'diff-enabled text report includes per-file diff headers'))
            ->expect(str_contains($with, '--- uncovered:') && str_contains($with, '+++ covered:'))
            ->to->be(true)
            ->assert();
      }
      finally {
         unlink($file);
      }
   })
);
