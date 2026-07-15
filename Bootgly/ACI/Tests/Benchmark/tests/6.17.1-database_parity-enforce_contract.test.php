<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;


$testFile = BOOTGLY_ROOT_DIR
   . '../bootgly_benchmarks/runners/tests/HTTPServerDatabaseParity.test.php';

return is_file($testFile)
   ? require $testFile
   : new Specification(
      description: 'It should enforce the HTTP server database-parity contract '
         . '(requires the optional bootgly_benchmarks sibling checkout)',
      skip: true,
      test: static function (): void {}
   );
