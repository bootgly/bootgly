<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Fakers\Integer;
use Bootgly\ACI\Fakers\UUID;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Faker — same seed yields identical output (determinism)',

   test: new Assertions(Case: function (): Generator {
      $a = (new Integer(seed: 42))->generate();
      $b = (new Integer(seed: 42))->generate();
      yield (new Assertion(description: 'Integer with same seed → same value'))
         ->expect($a === $b)
         ->to->be(true)
         ->assert();

      $u1 = (new UUID(seed: 1))->generate();
      $u2 = (new UUID(seed: 1))->generate();
      yield (new Assertion(description: 'Uuid with same seed → same string'))
         ->expect($u1 === $u2)
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'Uuid output matches v4 RFC 4122 layout'))
         ->expect((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $u1))
         ->to->be(true)
         ->assert();

      $u3 = (new UUID(seed: 2))->generate();
      yield (new Assertion(description: 'different seeds → different Uuids'))
         ->expect($u1 !== $u3)
         ->to->be(true)
         ->assert();
   })
);
