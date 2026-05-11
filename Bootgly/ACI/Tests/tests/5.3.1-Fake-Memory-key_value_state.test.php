<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles;
use Bootgly\ACI\Tests\Doubles\Fake\Memory;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Fake Memory — stateful in-memory key-value substitute',

   test: new Assertions(Case: function (): Generator {
      $Memory = new Memory();

      yield (new Assertion(description: 'missing key returns default value'))
         ->expect($Memory->get('missing', 'fallback'))
         ->to->be('fallback')
         ->assert();

      $Memory->set('token', 'abc123');

      yield (new Assertion(description: 'set value is reported by check'))
         ->expect($Memory->check('token'))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'set value is returned by get'))
         ->expect($Memory->get('token'))
         ->to->be('abc123')
         ->assert();

      $Memory->set('nullable', null);

      yield (new Assertion(description: 'check follows Session present-even-when-null semantics'))
         ->expect($Memory->check('nullable'))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'null value follows Session get default semantics'))
         ->expect($Memory->get('nullable', 'fallback'))
         ->to->be('fallback')
         ->assert();

      yield (new Assertion(description: 'list exposes stored data'))
         ->expect($Memory->list())
         ->to->be([
            'token' => 'abc123',
            'nullable' => null,
         ])
         ->assert();

      $Memory->delete('token');

      yield (new Assertion(description: 'delete removes one key'))
         ->expect($Memory->check('token'))
         ->to->be(false)
         ->assert();

      $Memory->flush();

      yield (new Assertion(description: 'flush removes every key'))
         ->expect($Memory->list())
         ->to->be([])
         ->assert();

      $Memory->set('session', 'persisted');

      yield (new Assertion(description: 'reset returns the same fake'))
         ->expect($Memory->reset())
         ->to->be($Memory)
         ->assert();

      yield (new Assertion(description: 'reset clears stored state'))
         ->expect($Memory->list())
         ->to->be([])
         ->assert();

      $Memory->set('registry', 'state');

      $Doubles = new Doubles();
      $Registered = $Doubles->add($Memory);
      $Doubles->reset();

      yield (new Assertion(description: 'Doubles registry accepts Memory as Doubling'))
         ->expect($Registered)
         ->to->be($Memory)
         ->assert();

      yield (new Assertion(description: 'Doubles registry reset clears Memory'))
         ->expect($Memory->list())
         ->to->be([])
         ->assert();
   })
);
