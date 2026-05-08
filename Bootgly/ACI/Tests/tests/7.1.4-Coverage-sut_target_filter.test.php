<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — stop() keeps only configured SUT targets',

   test: new Assertions(Case: function (): Generator {
   $target = dirname(__DIR__) . '/Coverage.php';
   $targetAlias = dirname(__DIR__) . '/./Coverage.php';
   $neighbor = dirname(__DIR__) . '/Coverage/Driver.php';

      $Driver = new class ($targetAlias, $neighbor) extends Driver {
         public function __construct (
            private string $target,
            private string $neighbor,
         ) {}

         public function collect (): array
         {
            return [
               $this->target   => [10 => 1, 20 => 0],
               $this->neighbor => [30 => 1],
            ];
         }
      };

      $Cov = new Coverage($Driver);
      $Cov->targets = [$target];
      $Cov->start();
      $Cov->stop();

      $expected = str_replace('\\', '/', realpath($target) ?: $target);
      $files = array_keys($Cov->data);
      $file = $files[0] ?? null;

      yield (new Assertion(description: 'target filtering keeps one exact file entry'))
         ->expect(count($Cov->data))
         ->to->be(1)
         ->assert();

      yield (new Assertion(description: 'kept key is the canonical target file path'))
         ->expect($file)
         ->to->be($expected)
         ->assert();

      yield (new Assertion(description: 'target file keeps collected line buckets'))
         ->expect(
            ($Cov->data[$expected][10] ?? null) === 1
            && ($Cov->data[$expected][20] ?? null) === 0
         )
         ->to->be(true)
         ->assert();
   })
);
