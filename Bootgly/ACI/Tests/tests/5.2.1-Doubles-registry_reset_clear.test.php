<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles;
use Bootgly\ACI\Tests\Doubles\Doubling;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Doubles registry should add, reset, and clear registered doubles',

   test: new Assertions(Case: function (): Generator {
      $Double = new class implements Doubling {
         public int $resets = 0;

         public function reset (): static
         {
            $this->resets++;

            return $this;
         }
      };

      $Doubles = new Doubles();
      $Registered = $Doubles->add($Double);

      yield (new Assertion(description: 'add returns the registered double'))
         ->expect($Registered)
         ->to->be($Double)
         ->assert();

      yield (new Assertion(description: 'registry stores the double'))
         ->expect($Doubles->list)
         ->to->be([$Double])
         ->assert();

      $Doubles->reset();

      yield (new Assertion(description: 'reset cascades to registered doubles'))
         ->expect($Double->resets)
         ->to->be(1)
         ->assert();

      $Doubles->clear();

      yield (new Assertion(description: 'clear removes registered doubles'))
         ->expect($Doubles->list)
         ->to->be([])
         ->assert();
   })
);
