<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;


$testFile = BOOTGLY_ROOT_DIR
   . '../bootgly_benchmarks/runners/tests/WorkerTelemetry.test.php';

return is_file($testFile)
   ? require $testFile
   : new Specification(
      description: 'It should strictly import and atomically merge worker telemetry '
         . '(requires the optional bootgly_benchmarks sibling checkout)',
      skip: true,
      test: static function (): void {}
   );
