<?php

use Bootgly\ACI\Tests\Suite\Test;


$testFile = BOOTGLY_ROOT_DIR
   . '../bootgly_benchmarks/runners/tests/MeasurementBarrier.Test.php';

return is_file($testFile)
   ? require $testFile
   : new Test(
      description: 'It should align every load child on one measurement window '
         . '(requires the optional bootgly_benchmarks sibling checkout)',
      skip: true,
      test: static function (): void {}
   );
