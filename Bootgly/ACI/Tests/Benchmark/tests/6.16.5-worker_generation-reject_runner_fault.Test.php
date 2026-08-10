<?php

use Bootgly\ACI\Tests\Suite\Test;


$testFile = BOOTGLY_ROOT_DIR
   . '../bootgly_benchmarks/runners/tests/WorkerGenerationRunnerFault.Test.php';

return is_file($testFile)
   ? require $testFile
   : new Test(
      description: 'It should reject runner faults during worker-generation monitoring '
         . '(requires the optional bootgly_benchmarks sibling checkout)',
      skip: true,
      test: static function (): void {}
   );
