<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Fakers as FakersTrait;
use Bootgly\ACI\Tests\Suite\Test\Specification;


$Host = new class {
   use FakersTrait;
};


return new Specification(
   description: 'Fakers trait — fake($kind, $seed) entry point dispatches to concrete Faker',

   test: new Assertions(Case: function () use ($Host): Generator {
      $i = $Host->fake('Integer', seed: 7);
      yield (new Assertion(description: 'fake(Integer) returns int'))
         ->expect(is_int($i))
         ->to->be(true)
         ->assert();

      $a = $Host->fake('uuid', seed: 9);
      $b = $Host->fake('Uuid', seed: 9);
      $c = $Host->fake('UUID', seed: 9);
      yield (new Assertion(description: 'trait honours seed determinism'))
         ->expect($a === $b && $b === $c)
         ->to->be(true)
         ->assert();

      $threw = false;
      try {
         $Host->fake('NotARealFaker');
      }
      catch (LogicException) {
         $threw = true;
      }
      yield (new Assertion(description: 'unknown kind throws LogicException'))
         ->expect($threw)
         ->to->be(true)
         ->assert();
   })
);
