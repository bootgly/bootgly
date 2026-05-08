<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — stop() canonicalizes duplicated paths and merges line buckets',

   test: new Assertions(Case: function (): Generator {
      $target = dirname(__DIR__) . '/Coverage.php';
      $targetAlias = dirname(__DIR__) . '/./Coverage.php';
      $excluded = dirname(__DIR__) . '/tests/fake.php';

      $Driver = new class ($target, $targetAlias, $excluded) extends Driver {
         public function __construct (
            private string $target,
            private string $targetAlias,
            private string $excluded,
         ) {}

         public function collect (): array
         {
            return [
               $this->target      => [10 => 1, 20 => 0],
               $this->targetAlias => [20 => 1, 30 => 0],
               $this->excluded    => [1 => 1],
            ];
         }
      };

      $Cov = new Coverage($Driver);
      $Cov->start();
      $Cov->stop();

      $expected = str_replace('\\\\', '/', realpath($target) ?: $target);
      $files = array_keys($Cov->data);
      $file = $files[0] ?? null;

      yield (new Assertion(description: 'canonical path merge leaves one logical file entry'))
         ->expect(count($Cov->data))
         ->to->be(1)
         ->assert();

      yield (new Assertion(description: 'final key is the canonical resolved path'))
         ->expect($file)
         ->to->be($expected)
         ->assert();

      yield (new Assertion(description: 'merged bucket keeps max hit-state per line'))
         ->expect(
            ($Cov->data[$expected][10] ?? null) === 1
            && ($Cov->data[$expected][20] ?? null) === 1
            && ($Cov->data[$expected][30] ?? null) === 0
         )
         ->to->be(true)
         ->assert();
   })
);
