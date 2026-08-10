<?php

use Bootgly\ACI\Tests\Suite\Test;


$testFile = BOOTGLY_ROOT_DIR
   . '../bootgly_benchmarks/runners/tests/WorkerWarmup.Test.php';

return is_file($testFile)
   ? require $testFile
   : new Test(
      description: 'It should prove worker-aware warmup matrix coverage '
         . '(requires the optional bootgly_benchmarks sibling checkout)',
      skip: true,
      test: static function (): void {}
   );
